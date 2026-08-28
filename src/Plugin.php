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
        $host = (string) parse_url(osc_base_url(), PHP_URL_HOST);

        return array(
            // Where the purge location lives, reached from inside the network. Never the
            // public URL: an origin behind a proxy cannot resolve its own public host
            // back to itself, which is the same trap that silently broke auto-cron.
            'purge_endpoint' => array(getenv('SHOPCLASS_PURGE_ENDPOINT') ?: 'http://127.0.0.1/purge', 'STRING'),
            // The Host the cache key was built with. A purge presenting anything else is
            // a different key and comes back 412 having deleted nothing.
            'purge_host'     => array(getenv('SHOPCLASS_PURGE_HOST') ?: $host, 'STRING'),
            'ttl_item'       => array('3600', 'INTEGER'),
            'ttl_page'       => array('3600', 'INTEGER'),
            'ttl_aggregate'  => array('3600', 'INTEGER'),
            // Set only by a self-test that primed, purged and confirmed the entry gone.
            // Cleared whenever the endpoint or host changes. Ttl reads this.
            'verified'       => array('0', 'BOOLEAN'),
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
     * Prime a URL, purge it, and report whether the entry actually went away.
     *
     * This is what gates the longer window. A purge that fails silently turns a
     * thirty-second staleness into an hour of it, so the plugin refuses to lengthen
     * anything until it has watched one round trip work.
     *
     * @return array{ok:bool, message:string}
     */
    public static function selfTest(): array
    {
        // TODO(phase 2): GET a known-cacheable URL twice (MISS then HIT), purge it,
        // GET again and require MISS. Store the verdict in `verified`.
        return array('ok' => false, 'message' => 'not implemented');
    }

    public static function handleAdminPost(): void
    {
        // TODO(phase 3): save settings, run selfTest(), clear `verified` when
        // purge_endpoint or purge_host changes.
    }
}
