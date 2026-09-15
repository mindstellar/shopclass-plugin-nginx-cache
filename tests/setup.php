<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The configuration the Setup page prints.
 *
 * Nobody reads a config block closely enough to catch a `$request_uri` where a
 * `$1$is_args$args` belongs — and the result is not an error, it is every purge answering
 * 412 exactly as a page that was never cached does. So the purge key is derived from the
 * cache key by substitution rather than written twice, and that is pinned here, along with
 * the reference file in nginx/ agreeing with what the page prints.
 *
 * Usage:  php tests/setup.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

function osc_get_preference($key, $section = 'osclass')
{
    return '';
}
function osc_base_url($withIndex = false)
{
    return 'https://shop.example/';
}
function __($s, $domain = '')
{
    return $s;
}

require_once ABS_PATH . 'src/Plugin.php';
require_once ABS_PATH . 'src/Setup.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\nginxcache\Plugin;
use mindstellar\nginxcache\Setup;

harness_section('one key, written once');

pin(
    'the purge key is the cache key with the captured path in it',
    str_replace('$request_uri', '$1$is_args$args', Setup::cacheKey()),
    Setup::purgeKey()
);
foreach (array('$scheme', '$request_method', '$host') as $part) {
    check('both keys carry ' . $part, strpos(Setup::cacheKey(), $part) !== false && strpos(Setup::purgeKey(), $part) !== false);
}
check('the query is not dropped from the purge key', strpos(Setup::purgeKey(), '$is_args$args') !== false);
check('the cache key is core\'s own, unchanged', Setup::cacheKey() === '"$scheme$request_method$host$request_uri"');

harness_section('the blocks the page prints');

$purge = Setup::purgeLocation();
$php   = Setup::phpScope();
$http  = Setup::httpScope();

check('the purge location uses the purge key', strpos($purge, Setup::purgeKey()) !== false);
check('the php block uses the cache key', strpos($php, Setup::cacheKey()) !== false);
check('...and the same zone in all three', substr_count($purge . $php . $http, Setup::ZONE) >= 3);
check('the purge location captures the path', strpos($purge, '^/purge(/.*)$') !== false);

/* A purge takes no credentials, so the allow list is the whole of the access control. */
check('nothing else may reach it', strpos($purge, 'deny all;') !== false);
foreach (Setup::ALLOW as $cidr) {
    check('reachable from ' . $cidr, strpos($purge, 'allow ' . $cidr . ';') !== false);
}

/* The self-test reads X-Cache to tell "cached, then purged" from "never cached". Without
   it the gate can never open, whatever else is configured. */
check('X-Cache is emitted', strpos($php, 'add_header X-Cache $upstream_cache_status') !== false);

/* The app's Cache-Control has to stay the authority — that is what makes the TTL a filter
   in PHP rather than an nginx change. */
check('nothing overrides the app\'s own window', strpos($php, 'fastcgi_cache_valid') === false);
check('...and nothing ignores its headers', strpos($php, 'fastcgi_ignore_headers') === false);

harness_section('the Dockerfile is for the nginx that is actually running');

$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.31.3';
pin('the version nginx reported', '1.31.3', Setup::nginxVersion());
check('and the build uses it', substr_count(Setup::dockerfile(), '1.31.3') >= 4, Setup::dockerfile());

unset($_SERVER['SERVER_SOFTWARE']);
pin('a fallback when it said nothing', Setup::FALLBACK_NGINX, Setup::nginxVersion());

$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
pin('...and when it is not nginx at all', Setup::FALLBACK_NGINX, Setup::nginxVersion());
unset($_SERVER['SERVER_SOFTWARE']);

$docker = Setup::dockerfile();
check('built as a dynamic module', strpos($docker, '--add-dynamic-module') !== false);
check('...against the running binary\'s ABI', strpos($docker, '--with-compat') !== false);
check('and the module it copies is the one make produced',
    strpos($docker, 'objs/ngx_http_cache_purge_module.so') !== false);
check('the runtime stage is stock nginx', substr_count($docker, 'FROM nginx:') === 2);

harness_section('what to put in the settings');

$_SERVER['REQUEST_SCHEME'] = 'https';
pin('the endpoint follows the scheme nginx serves on', 'https://127.0.0.1/purge', Setup::suggested()['endpoint']);
$_SERVER['REQUEST_SCHEME'] = 'http';
pin('...whatever the site\'s own is', 'http://127.0.0.1/purge', Setup::suggested()['endpoint']);
unset($_SERVER['REQUEST_SCHEME']);
pin('and the host is the one visitors send', Plugin::publicHost(), Setup::suggested()['host']);

harness_section('the reference file says the same thing');

/* nginx/shopclass-cache.conf is what someone reads when they are not in the admin. If it
   drifts from what the page prints, one of the two sends people to a silent 412. */
$conf = (string) file_get_contents(ABS_PATH . 'nginx/shopclass-cache.conf');
check('same cache key', strpos($conf, Setup::cacheKey()) !== false);
check('same purge key', strpos($conf, Setup::purgeKey()) !== false);
check('same zone', strpos($conf, Setup::ZONE) !== false);
check('same load_module line', strpos($conf, Setup::loadModule()) !== false);

exit(harness_result());
