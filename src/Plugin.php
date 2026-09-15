<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\nginxcache;

use Params;

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
     * The longest window any tier may be set to.
     *
     * Not a round number picked for comfort: a cached page carries the CSRF token minted
     * when it was cached, and core stops accepting a token 7200s after it was issued.
     * Bucketing means the token can already be up to 1800s old when the page is stored,
     * a page held for T seconds is handed out up to T seconds after that, and the visitor
     * then takes some time to fill the form in. 1800 + 3600 + a half-hour to write a
     * message still lands inside 7200; anything longer starts answering "your session has
     * expired" to people contacting sellers, and the purge self-test cannot see it,
     * because purging keeps working perfectly while the forms quietly do not.
     */
    public const TTL_MAX = 3600;

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
            // The hosts cached pages are filed under -- one per line, and one is the
            // ordinary case. A purge presenting anything else is a different key and comes
            // back 412 having deleted nothing. The port is part of it when the site runs
            // on one, because that is what a visitor's Host header says.
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

    /**
     * Every host cached pages of this site are filed under.
     *
     * One site is often reachable at more than one name -- with and without www, a
     * staging alias, a proxy that passes a different Host through -- and nginx keys an
     * entry per name. A purge names one of them, so a host missing from this list keeps
     * serving the page it had for the whole window.
     *
     * Stored as one preference so nothing has to migrate; commas, spaces and newlines all
     * separate, because people will type all three.
     *
     * @return string[] in the order given, without repeats
     */
    public static function purgeHosts(): array
    {
        return self::parseHosts((string) self::get('purge_host'));
    }

    /** @return string[] */
    public static function parseHosts(string $raw): array
    {
        $hosts = array();
        foreach (preg_split('/[\s,]+/', $raw) ?: array() as $host) {
            $host = trim($host);
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
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
     * Every configured host is primed, purged and re-checked in turn, because priming
     * with `Host: X` creates an entry keyed on X -- so each one can be proved rather than
     * taken on trust.
     *
     * What that cannot prove is that those names are the ones visitors actually send: a
     * list of typos would verify itself perfectly and purge nothing anybody reads. So one
     * check does not come from nginx at all -- the site's own host has to be in the list.
     *
     * @return array{ok:bool, message:string}
     */
    public static function selfTest(): array
    {
        $endpoint = trim((string) self::get('purge_endpoint'));

        if ($endpoint === '' || self::purgeHosts() === array()) {
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

        // The host a visitor's request carries is the one their cached pages are filed
        // under. Anything else in the list may well be right; this one has to be there.
        $visitorHost = self::publicHost();
        if ($visitorHost === '') {
            return self::verdict(false, __('The site has no host to test against; check its base URL.', 'nginx-cache'));
        }

        $hosts = self::purgeHosts();
        if (!in_array($visitorHost, $hosts, true)) {
            return self::verdict(false, sprintf(
                __('Visitors reach this site as "%1$s", which is not in the host list (%2$s) — so the pages they are served would never be purged. Add it.', 'nginx-cache'),
                $visitorHost,
                implode(', ', $hosts)
            ));
        }

        foreach ($hosts as $host) {
            $failure = self::testOneHost($origin, $host);
            if ($failure !== '') {
                return self::verdict(false, $failure);
            }
        }

        return self::verdict(true, count($hosts) === 1
            ? __('Purge confirmed: the page was cached, purged, and re-rendered. Longer cache windows are now in use.', 'nginx-cache')
            : sprintf(
                __('Purge confirmed on all %d hosts: each cached the page, purged it, and re-rendered. Longer cache windows are now in use.', 'nginx-cache'),
                count($hosts)
            ));
    }

    /**
     * Prime, purge and re-check the home page as one host sees it.
     *
     * Priming with `Host: X` files an entry under X, so this proves the round trip for
     * that host rather than assuming it from another one working.
     *
     * @return string the failure, or '' when this host is good
     */
    private static function testOneHost(string $origin, string $host): string
    {
        $home  = osc_base_url();
        $probe = $origin . (string) (parse_url($home, PHP_URL_PATH) ?: '/');
        $named = count(self::purgeHosts()) > 1 ? sprintf(__(' (host: %s)', 'nginx-cache'), $host) : '';

        $first = Client::probe($probe, $host);
        if ($first['status'] === 0) {
            return sprintf(
                __('Could not reach %s. The purge endpoint must be an address this server can open itself, not the site\'s public URL.', 'nginx-cache'),
                $origin
            );
        }
        if ($first['status'] !== 200) {
            return sprintf(
                __('%1$s answered HTTP %2$d for the home page. Check that %3$s names a server block on that address.', 'nginx-cache'),
                $probe,
                $first['status'],
                $host
            );
        }
        if ($first['cache'] === '') {
            return __('nginx is not sending X-Cache, so it is not caching this site yet. Add the caching stanza from the Setup page.', 'nginx-cache');
        }

        $second = Client::probe($probe, $host);
        if (!self::isHeld($second['cache'])) {
            return sprintf(
                __('The home page was not held in the cache (X-Cache: %1$s), so there is nothing for a purge to remove.%2$s', 'nginx-cache'),
                $second['cache'] !== '' ? $second['cache'] : '-',
                $named
            );
        }

        $status = Client::purgeOne($home, null, $host);
        if ($status === Client::NOT_CACHED) {
            return sprintf(
                __('The purge reached nginx but matched no entry for "%s". The key it built differs from the one holding the page — check the endpoint\'s scheme against the Setup page.', 'nginx-cache'),
                $host
            );
        }
        if ($status !== 200) {
            return sprintf(
                __('The purge returned HTTP %1$d. A 404 there means the purge location is not in the nginx config; see the Setup page.%2$s', 'nginx-cache'),
                $status,
                $named
            );
        }

        $third = Client::probe($probe, $host);
        if (self::isHeld($third['cache'])) {
            return sprintf(
                __('The purge reported success but the page is still being served from the cache (X-Cache: %1$s).%2$s', 'nginx-cache'),
                $third['cache'],
                $named
            );
        }

        return '';
    }

    /**
     * Whether nginx says it has an entry for this page.
     *
     * Not just HIT. An entry past its window is served stale while it refreshes behind
     * the request -- STALE, then UPDATING -- and one being revalidated says EXPIRED. All
     * of those are entries a purge has something to remove; only MISS and BYPASS are not.
     * Requiring HIT made the test fail whenever it happened to run during a refresh, which
     * is a false alarm on the one control that decides whether any of this is switched on.
     */
    private static function isHeld(string $cache): bool
    {
        return !in_array($cache, array('', 'MISS', 'BYPASS'), true);
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

    /**
     * A window a tier may actually be given: never negative, never past what the token in
     * the page survives. Zero is allowed and means "leave this tier on core's own window".
     */
    public static function clampTtl(int $seconds): int
    {
        return max(0, min($seconds, self::TTL_MAX));
    }

    /** Every button saves the form first, so a test runs against what is on screen. */
    private static function persistSettings(): void
    {
        $endpoint = trim(Params::getParamString('purge_endpoint'));

        // Normalised on the way in, so re-ordering whitespace is not a change and does
        // not close the gate, while adding or removing a host is and does.
        $host = implode("\n", self::parseHosts(Params::getParamString('purge_host')));

        // A verdict belongs to the settings it was reached with. Change where the purge
        // goes or what it names, and the last one says nothing about the new ones -- so
        // the gate closes and the longer windows stop until the test is run again.
        if ($endpoint !== (string) self::get('purge_endpoint') || $host !== (string) self::get('purge_host')) {
            osc_set_preference('verified', '0', self::SECTION, 'BOOLEAN');
            osc_set_preference('last_test', '', self::SECTION);
        }

        osc_set_preference('purge_endpoint', $endpoint, self::SECTION, 'STRING');
        osc_set_preference('purge_host', $host, self::SECTION, 'STRING');

        foreach (array('ttl_item', 'ttl_page', 'ttl_aggregate') as $key) {
            osc_set_preference($key, (string) self::clampTtl(Params::getParamInt($key)), self::SECTION, 'INTEGER');
        }

        osc_reset_preferences();
    }

    /** Whether anything on the form asked for a window longer than one can be given. */
    private static function askedForTooLong(): bool
    {
        foreach (array('ttl_item', 'ttl_page', 'ttl_aggregate') as $key) {
            if (Params::getParamInt($key) > self::TTL_MAX) {
                return true;
            }
        }

        return false;
    }

    public static function handleAdminPost(): void
    {
        $action = Params::getParamString('nginx_cache_action');
        if ($action === '') {
            return;
        }
        osc_csrf_check();

        $tooLong = self::askedForTooLong();
        self::persistSettings();

        if ($tooLong) {
            osc_add_flash_warning_message(sprintf(
                __('Windows were shortened to %d seconds. Past that the security token cached inside a page outlives itself, and every form on it — contact seller, report, comment — starts answering "your session has expired".', 'nginx-cache'),
                self::TTL_MAX
            ), 'admin');
        }

        switch ($action) {
            case 'save':
                osc_add_flash_ok_message(__('Settings saved.', 'nginx-cache'), 'admin');
                break;

            case 'test':
                $result = self::selfTest();
                if ($result['ok']) {
                    osc_add_flash_ok_message($result['message'], 'admin');
                } else {
                    osc_add_flash_error_message($result['message'], 'admin');
                }
                break;

            case 'retry_queue':
                $before = count(Queue::load());
                Queue::retry();
                $after = count(Queue::load());
                osc_add_flash_ok_message(sprintf(
                    __('%1$d of %2$d queued purges delivered.', 'nginx-cache'),
                    $before - $after,
                    $before
                ), 'admin');
                break;
        }

        osc_redirect_to(osc_route_admin_url('nginx-cache-settings'));
    }
}
