<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\nginxcache;

use Symfony\Component\HttpClient\HttpClient;
use Throwable;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * Sends purges to nginx.
 *
 * Three things about this are easy to get wrong and all three fail quietly, so they are
 * stated here rather than left to a reader of the config:
 *
 * 1. **The request goes to the origin, never to the site's public URL.** An origin behind
 *    a proxy resolves its own public hostname to the proxy edge and cannot connect back
 *    to itself; the request times out and nothing reports it. This is the same trap that
 *    left auto-cron silently dead on proxied installs for as long as it existed.
 *
 * 2. **The Host header must be the public hostname anyway.** nginx's cache key is
 *    `$scheme$request_method$host$request_uri`, so a purge presenting the container name
 *    computes a different key, matches no entry, and returns 412 having deleted nothing.
 *    Reaching the right box and naming the right host are separate requirements.
 *
 * 3. **The endpoint's scheme has to be the scheme nginx serves the site on**, because
 *    `$scheme` is in that key too. Where nginx terminates TLS the endpoint is
 *    `https://127.0.0.1/purge` — talking to ourselves over TLS, which is why the
 *    certificate is not verified here: it is issued for the public name, and the
 *    connection is to a loopback address by design.
 */
class Client
{
    /** nginx answers a purge for an entry it does not hold with 412, not 404. */
    public const NOT_CACHED = 412;

    /** The endpoint is the local origin. Anything slow here is something being wrong. */
    private const TIMEOUT = 3;

    /**
     * Purge each URL, queueing whatever could not be delivered.
     *
     * @param string[] $urls
     *
     * @return array<string, int> url => HTTP status, 0 when the origin was unreachable
     */
    public static function purge(array $urls): array
    {
        $results = array();

        foreach ($urls as $url) {
            $status         = self::purgeOne($url);
            $results[$url]  = $status;

            if (!self::isSettled($status)) {
                Queue::add($url);
            }
        }

        return $results;
    }

    /**
     * Whether a status means there is nothing left to do.
     *
     * 200 deleted the entry; 412 means there was none to delete, which is the ordinary
     * answer for a URL nobody had requested yet. Everything else -- a refused connection,
     * a timeout, the 404 nginx gives when the purge location is not configured at all --
     * leaves a cached page wrong and is worth retrying.
     */
    public static function isSettled(int $status): bool
    {
        return $status === 200 || $status === self::NOT_CACHED;
    }

    /**
     * @return int HTTP status, or 0 when the origin could not be reached at all
     */
    public static function purgeOne(string $url, ?string $endpoint = null, ?string $host = null): int
    {
        $target = self::purgeUrl($url, $endpoint ?? (string) Plugin::get('purge_endpoint'));
        if ($target === '') {
            return 0;
        }

        return self::get($target, $host ?? (string) Plugin::get('purge_host'));
    }

    /**
     * The purge location's URL for a public one: the endpoint, then the path and query
     * exactly as they appear in the cache key.
     *
     * Kept separate from the sending because it is the half that can be tested without
     * an nginx to talk to, and the half that is wrong when nothing gets purged.
     */
    public static function purgeUrl(string $url, string $endpoint): string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        $url      = trim($url);
        if ($endpoint === '' || $url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $query = (string) ($parts['query'] ?? '');

        return $endpoint . $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * One request, no exceptions out. A purge that cannot be delivered is a queue entry,
     * never a fatal on the save that triggered it.
     *
     * @return int HTTP status, 0 when the request never completed
     */
    public static function get(string $target, string $host, array $headers = array()): int
    {
        if ($host !== '') {
            $headers['Host'] = $host;
        }

        try {
            $response = HttpClient::create(array('verify_peer' => false, 'verify_host' => false))
                ->request('GET', $target, array(
                    'headers'       => $headers,
                    'timeout'       => self::TIMEOUT,
                    // A redirect would be answered by a different URL than the one whose
                    // entry is being purged, so following one can only mislead.
                    'max_redirects' => 0,
                ));

            return $response->getStatusCode();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * A request whose response headers are needed, not just its status -- the self-test
     * reads X-Cache to see whether an entry was created and then removed.
     *
     * @return array{status:int, cache:string} cache is nginx's $upstream_cache_status, '' when absent
     */
    public static function probe(string $target, string $host): array
    {
        try {
            $response = HttpClient::create(array('verify_peer' => false, 'verify_host' => false))
                ->request('GET', $target, array(
                    'headers'       => array('Host' => $host),
                    'timeout'       => self::TIMEOUT,
                    'max_redirects' => 0,
                ));

            $status  = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $cache   = isset($headers['x-cache'][0]) ? strtoupper((string) $headers['x-cache'][0]) : '';

            return array('status' => $status, 'cache' => $cache);
        } catch (Throwable $e) {
            return array('status' => 0, 'cache' => '');
        }
    }
}
