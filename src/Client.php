<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\nginxcache;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * Sends purges to nginx.
 *
 * Two things about this are easy to get wrong and both fail quietly, so they are stated
 * here rather than left to a reader of the config:
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
 */
class Client
{
    /** nginx answers a purge for an entry it does not hold with 412, not 404. */
    public const NOT_CACHED = 412;

    /**
     * @param string[] $urls
     *
     * @return array<string, int> url => HTTP status
     */
    public static function purge(array $urls): array
    {
        $results = array();

        foreach ($urls as $url) {
            $results[$url] = self::purgeOne($url);
        }

        return $results;
    }

    /**
     * @return int HTTP status, or 0 when the origin could not be reached at all
     */
    public static function purgeOne(string $url): int
    {
        // TODO(phase 2): map the public URL's path onto purge_endpoint, send it with a
        // Host: purge_host header and a short timeout, and hand anything that is neither
        // 200 nor 412 to Queue::add() for the hourly retry. 412 means the entry was not
        // held, which is a normal outcome and not worth retrying -- unless every purge
        // returns it, which means purge_host is wrong and belongs in the admin page as a
        // configuration error rather than a transient one.
        return 0;
    }
}
