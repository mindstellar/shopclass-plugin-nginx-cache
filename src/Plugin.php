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
 * Settings, install/uninstall, and the self-test that decides whether the longer
 * cache window is safe to use.
 */
class Plugin
{
    public const SECTION = 'nginx_cache';

    /**
     * Defaults. Env vars come first so the bundled Docker image comes up configured
     * with no admin visit; a standalone install gets the loopback default and the
     * setup page.
     *
     * @return array<string, array{0:string, 1:string}> key => [value, type]
     */
    public static function defaults(): array
    {
        return array(
            // Where the purge location lives, reached from inside the network. Never the
            // public URL: an origin behind a proxy cannot resolve its own public host
            // back to itself, which is the same trap that silently broke auto-cron.
            //
            // Its scheme is nginx's own, not the site's: `$scheme` is part of the cache
            // key, so on an install where nginx terminates TLS the purge has to arrive
            // over TLS too, even though it is addressed to the loopback.
            'purge_endpoint' => array(
                getenv('SHOPCLASS_PURGE_ENDPOINT') ?: self::originScheme() . '://127.0.0.1/purge',
                'STRING',
            ),
            // The Host the cache key was built with. A purge presenting anything else is
            // a different key and comes back 412 having deleted nothing. Carries the port
            // when the site runs on one, because that is what a visitor's Host header says.
            'purge_host'     => array(getenv('SHOPCLASS_PURGE_HOST') ?: self::publicHost(), 'STRING'),
            'ttl_item'       => array('3600', 'INTEGER'),
            'ttl_page'       => array('3600', 'INTEGER'),
            'ttl_aggregate'  => array('3600', 'INTEGER'),
            // Set only by a self-test that primed, purged and confirmed the entry gone.
            // Cleared whenever the endpoint or host changes. Ttl reads this.
            'verified'       => array('0', 'BOOLEAN'),
            // What that self-test last reported, for the admin page to show.
            'last_test'      => array('', 'STRING'),
            // Purges the origin refused, waiting for the next cron tick. See Queue.
            'queue'          => array('', 'STRING'),
        );
    }

    public static function install(): void
    {
        foreach (self::defaults() as $key => [$value, $type]) {
            osc_set_preference($key, $value, self::SECTION, $type);
        }
    }

    public static function uninstall(): void
    {
        // Key first, section second. Passing the section alone deletes a preference
        // named after it in the 'osclass' section -- which exists nowhere, so the
        // uninstall reports success and leaves every setting behind.
        foreach (array_keys(self::defaults()) as $key) {
            osc_delete_preference($key, self::SECTION);
        }
    }

    /** @return string */
    public static function get(string $key)
    {
        return osc_get_preference($key, self::SECTION);
    }

    /**
     * The scheme nginx itself serves on, which is not always the site's own.
     *
     * Where a proxy terminates TLS, the site is https while nginx is handed plain http
     * and keys its cache entries on that. FastCGI passes nginx's `$scheme` through, so
     * this reads it rather than guessing from the configured base URL.
     */
    public static function originScheme(): string
    {
        $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
        if ($scheme === 'http' || $scheme === 'https') {
            return $scheme;
        }

        // Set at all means the server has an opinion, and "off" is one of them --
        // falling through to the base URL there would key every purge as https on a
        // site nginx is serving over plain http behind a proxy.
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '') {
            return $https === 'off' ? 'http' : 'https';
        }

        // CLI install, or a server passing neither: the site's own scheme is the best
        // available answer, and the self-test is what catches it being the wrong one.
        return parse_url(osc_base_url(), PHP_URL_SCHEME) === 'https' ? 'https' : 'http';
    }

    /** Host, with the port when the site runs on a non-default one. */
    public static function publicHost(?string $baseUrl = null): string
    {
        $parts = parse_url($baseUrl ?? osc_base_url());
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $host = (string) $parts['host'];

        return empty($parts['port']) ? $host : $host . ':' . $parts['port'];
    }

    /**
     * Prime a URL, purge it, and report whether the entry actually went away.
     *
     * This is what gates the longer window. A purge that fails silently turns a
     * thirty-second staleness into an hour of it, so the plugin refuses to lengthen
     * anything until it has watched one round trip work.
     *
     * The one thing it must not do is prove itself. Priming with the same Host the purge
     * presents makes the test self-consistent and blind: a `purge_host` naming nothing a
     * visitor ever sends would create an entry under that name, delete it again, and
     * report success while every real purge missed. So the entry is created the way a
     * visitor creates one -- the site's own host -- and removed with the configured
     * settings. A mismatch between them is the failure this is here to catch.
     *
     * @return array{ok:bool, message:string}
     */
    public static function selfTest(): array
    {
        $endpoint = trim((string) self::get('purge_endpoint'));
        $host     = trim((string) self::get('purge_host'));

        if ($endpoint === '' || $host === '') {
            return self::verdict(false, __('Set the purge endpoint and host first.', 'nginx-cache'));
        }

        $origin = self::originOf($endpoint);
        if ($origin === '') {
            return self::verdict(false, sprintf(__('"%s" is not a URL.', 'nginx-cache'), $endpoint));
        }

        // The scheme is in the cache key, and the endpoint's is the one every purge will
        // be sent with. Where it differs from the scheme nginx serves on, every purge
        // builds a key nothing holds -- and the test below could not see it, because it
        // would prime over that same wrong scheme.
        $endpointScheme = (string) parse_url($endpoint, PHP_URL_SCHEME);
        $servedScheme   = self::originScheme();
        if (self::schemeIsKnown() && $endpointScheme !== $servedScheme) {
            return self::verdict(false, sprintf(
                __('The endpoint is %1$s but nginx is serving this site over %2$s, and the scheme is part of the cache key. Change the endpoint to %2$s://.', 'nginx-cache'),
                $endpointScheme,
                $servedScheme
            ));
        }

        // The host a visitor's request carries, which is the host their cache entries are
        // filed under. The purge below uses the configured one instead, on purpose.
        $visitorHost = self::publicHost();
        if ($visitorHost === '') {
            return self::verdict(false, __('The site has no host to test against; check its base URL.', 'nginx-cache'));
        }

        $home  = osc_base_url();
        $path  = (string) (parse_url($home, PHP_URL_PATH) ?: '/');
        $probe = $origin . $path;

        $first = Client::probe($probe, $visitorHost);
        if ($first['status'] === 0) {
            return self::verdict(false, sprintf(
                __('Could not reach %s. The purge endpoint must be an address this server can open itself, not the site\'s public URL.', 'nginx-cache'),
                $origin
            ));
        }
        if ($first['status'] !== 200) {
            return self::verdict(false, sprintf(
                __('%1$s answered HTTP %2$d for the home page. Check that %3$s names a server block on that address.', 'nginx-cache'),
                $probe,
                $first['status'],
                $visitorHost
            ));
        }
        if ($first['cache'] === '') {
            return self::verdict(false, __('nginx is not sending X-Cache, so it is not caching this site yet. Add the caching stanza from the Setup page.', 'nginx-cache'));
        }

        $second = Client::probe($probe, $visitorHost);
        if ($second['cache'] !== 'HIT') {
            return self::verdict(false, sprintf(
                __('The home page was not held in the cache (X-Cache: %s), so there is nothing for a purge to remove.', 'nginx-cache'),
                $second['cache'] !== '' ? $second['cache'] : '-'
            ));
        }

        $status = Client::purgeOne($home, $endpoint, $host);
        if ($status === Client::NOT_CACHED) {
            return self::verdict(false, sprintf(
                __('The purge reached nginx but matched no entry: the page is filed under "%1$s" and the purge asked for "%2$s". Set the host to %1$s.', 'nginx-cache'),
                $visitorHost,
                $host
            ));
        }
        if ($status !== 200) {
            return self::verdict(false, sprintf(
                __('The purge returned HTTP %d. A 404 there means the purge location is not in the nginx config; see the Setup page.', 'nginx-cache'),
                $status
            ));
        }

        $third = Client::probe($probe, $visitorHost);
        if ($third['cache'] === 'HIT') {
            return self::verdict(false, __('The purge reported success but the page is still being served from the cache.', 'nginx-cache'));
        }

        return self::verdict(true, __('Purge confirmed: the page was cached, purged, and re-rendered. Longer cache windows are now in use.', 'nginx-cache'));
    }

    /** Whether nginx told us its scheme, rather than us having guessed at it. */
    private static function schemeIsKnown(): bool
    {
        return ($_SERVER['REQUEST_SCHEME'] ?? '') !== '' || ($_SERVER['HTTPS'] ?? '') !== '';
    }

    /** The scheme, host and port of the purge endpoint, without its path. */
    public static function originOf(string $endpoint): string
    {
        $parts = parse_url(trim($endpoint));
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (empty($parts['port']) ? '' : ':' . $parts['port']);
    }

    /** Record the verdict; `verified` is the flag Ttl reads before lengthening anything. */
    private static function verdict(bool $ok, string $message): array
    {
        osc_set_preference('verified', $ok ? '1' : '0', self::SECTION, 'BOOLEAN');
        osc_set_preference('last_test', (string) json_encode(array(
            'ok'      => $ok,
            'message' => $message,
            'at'      => date('Y-m-d H:i:s'),
        )), self::SECTION);

        return array('ok' => $ok, 'message' => $message);
    }

    public static function handleAdminPost(): void
    {
        // TODO(phase 3): save settings, run selfTest(), clear `verified` when
        // purge_endpoint or purge_host changes.
    }
}
