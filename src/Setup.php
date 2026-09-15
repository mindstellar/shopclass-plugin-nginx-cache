<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\nginxcache;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * The nginx configuration this install needs, written out with its own values.
 *
 * A standalone install has to add all of it by hand, and every part of it fails quietly
 * when it is subtly wrong -- so the Setup page prints the real host, the real scheme and
 * the real nginx version rather than a sample to adapt. The same text lives in
 * nginx/shopclass-cache.conf as a reference; this is the filled-in form of it.
 */
class Setup
{
    /** The cache zone, named as core's own reference config names it. */
    public const ZONE = 'MICROCACHE';

    /** Where PHP runs, from nginx's point of view. */
    public const ALLOW = array('127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16');

    /** The version the Dockerfile builds against when nginx has not said which it is. */
    public const FALLBACK_NGINX = '1.29.0';

    /** Core's own key, unchanged: this plugin does not alter what a page is filed under. */
    public static function cacheKey(): string
    {
        return '"$scheme$request_method$host$request_uri"';
    }

    /**
     * The same key, as the purge location can build it: `$1` is the target path the
     * location captured and `$is_args$args` is its query.
     *
     * Derived from cacheKey() by substitution rather than written out again, because the
     * two drifting apart is the failure this plugin cannot see -- every purge would answer
     * 412, which is also what a page that was never cached answers.
     */
    public static function purgeKey(): string
    {
        return str_replace('$request_uri', '$1$is_args$args', self::cacheKey());
    }

    /** nginx's version, as it told PHP, or the one the Dockerfile below falls back to. */
    public static function nginxVersion(): string
    {
        if (preg_match('~^nginx/(\d+\.\d+\.\d+)~', (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), $m)) {
            return $m[1];
        }

        return self::FALLBACK_NGINX;
    }

    /** Stock nginx plus one module, not a switch to OpenResty. */
    public static function dockerfile(): string
    {
        $v = self::nginxVersion();

        return <<<DOCKER
FROM nginx:{$v}-alpine AS build
RUN apk add --no-cache build-base pcre-dev zlib-dev openssl-dev linux-headers git \\
 && cd /tmp \\
 && wget -q https://nginx.org/download/nginx-{$v}.tar.gz \\
 && tar xf nginx-{$v}.tar.gz \\
 && git clone --depth 1 https://github.com/nginx-modules/ngx_cache_purge.git \\
 && cd nginx-{$v} \\
 && ./configure --with-compat --add-dynamic-module=../ngx_cache_purge \\
 && make modules

FROM nginx:{$v}-alpine
COPY --from=build /tmp/nginx-{$v}/objs/ngx_http_cache_purge_module.so /etc/nginx/modules/
DOCKER;
    }

    /** Main context, above `http {` — not conf.d, which is already inside it. */
    public static function loadModule(): string
    {
        return 'load_module modules/ngx_http_cache_purge_module.so;';
    }

    public static function httpScope(): string
    {
        $zone = self::ZONE;

        return <<<CONF
fastcgi_cache_path /var/cache/nginx/microcache levels=1:2 keys_zone={$zone}:10m
                   max_size=500m inactive=1d use_temp_path=off;

map \$http_cookie \$mc_private {
    default 0;
    "~(^|;\\s*)(oc_cache_bypass|oc_userLocale|osclass|PHPSESSID)=" 1;
}
CONF;
    }

    /** The purge location. Its allow list is the whole of its access control. */
    public static function purgeLocation(): string
    {
        $allow = '';
        foreach (self::ALLOW as $cidr) {
            $allow .= "    allow {$cidr};\n";
        }
        $zone = self::ZONE;
        $key  = self::purgeKey();

        return "location ~ ^/purge(/.*)\$ {\n"
            . $allow
            . "    deny all;\n"
            . "    fastcgi_cache_purge {$zone} {$key};\n"
            . '}';
    }

    /** Added inside the existing `location ~ \.php$`, which keeps its fastcgi_pass. */
    public static function phpScope(): string
    {
        $zone = self::ZONE;
        $key  = self::cacheKey();

        return <<<CONF
fastcgi_cache            {$zone};
fastcgi_cache_key        {$key};
fastcgi_cache_bypass     \$mc_private;
fastcgi_no_cache         \$mc_private;
fastcgi_cache_lock       on;
fastcgi_cache_use_stale  updating error timeout http_500 http_503;
fastcgi_cache_background_update on;
add_header X-Cache \$upstream_cache_status always;
CONF;
    }

    /**
     * What to put in the two settings, for this install.
     *
     * @return array{endpoint:string, host:string}
     */
    public static function suggested(): array
    {
        return array(
            'endpoint' => Plugin::originScheme() . '://127.0.0.1/purge',
            'host'     => Plugin::publicHost(),
        );
    }
}
