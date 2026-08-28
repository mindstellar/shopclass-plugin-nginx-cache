<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * What gets purged, and where the purge is sent.
 *
 * Both halves fail silently when they are wrong: a URL that is never named stays cached
 * for the whole window, and a purge addressed with the wrong key gets a cheerful 412 that
 * nothing surfaces. So the mapping and the URL set are pinned here rather than left to the
 * one integration test that needs a running nginx.
 *
 * Usage:  php tests/purge-urls.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

// ── the slice of core these classes touch ────────────────────────────────────
function osc_base_url($withIndex = false)
{
    return 'https://example.test/' . ($withIndex ? 'index.php' : '');
}
function osc_search_url($params = null)
{
    return 'https://example.test/search/category,' . ($params['sCategory'] ?? '');
}
function osc_user_public_profile_url($id = null)
{
    return 'https://example.test/user/' . $id;
}
function osc_item_url_from_item($item, $locale = '')
{
    return 'https://example.test/' . ($locale !== '' ? $locale . '/' : '') . $item['s_title'] . '_i' . $item['pk_i_id'];
}
function osc_item_url_ns($id, $locale = '')
{
    return 'https://example.test/index.php?page=item&id=' . $id;
}
function osc_get_locales()
{
    return $GLOBALS['locales'];
}
function osc_apply_filter($hook, $content, ...$args)
{
    return isset($GLOBALS['filters'][$hook]) ? $GLOBALS['filters'][$hook]($content) : $content;
}
function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['prefs'][$section][$key] ?? '';
}
function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    $GLOBALS['prefs'][$section][$key] = $value;
}
function __($s, $domain = '')
{
    return $s;
}

$GLOBALS['locales'] = array(array('pk_c_code' => 'en_US'));
$GLOBALS['filters'] = array();
$GLOBALS['prefs']   = array();

require_once ABS_PATH . 'src/Plugin.php';
require_once ABS_PATH . 'src/Client.php';
require_once ABS_PATH . 'src/Purge.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\nginxcache\Client;
use mindstellar\nginxcache\Plugin;
use mindstellar\nginxcache\Purge;

/* ---------------------------------------------------------------------------
 * Where the purge is sent
 * ------------------------------------------------------------------------ */
harness_section('a public URL becomes a purge URL');

$e = 'http://127.0.0.1/purge';

pin(
    'the path is appended to the endpoint',
    'http://127.0.0.1/purge/a-listing_i42',
    Client::purgeUrl('https://example.test/a-listing_i42', $e)
);
pin(
    'the query survives, because the cache key contains it',
    'http://127.0.0.1/purge/index.php?page=item&id=42',
    Client::purgeUrl('https://example.test/index.php?page=item&id=42', $e)
);
pin(
    'the home page is a path of its own, not an empty one',
    'http://127.0.0.1/purge/',
    Client::purgeUrl('https://example.test/', $e)
);
pin(
    'a URL with no path at all still purges the root',
    'http://127.0.0.1/purge/',
    Client::purgeUrl('https://example.test', $e)
);
pin(
    'a subdirectory install keeps its prefix — it is part of the key',
    'http://127.0.0.1/purge/shop/a-listing_i42',
    Client::purgeUrl('https://example.test/shop/a-listing_i42', $e)
);
pin(
    'a trailing slash on the endpoint does not double up',
    'http://127.0.0.1/purge/x',
    Client::purgeUrl('https://example.test/x', 'http://127.0.0.1/purge/')
);
pin(
    'an https endpoint is left alone — nginx terminating TLS keys on that scheme',
    'https://127.0.0.1/purge/x',
    Client::purgeUrl('https://example.test/x', 'https://127.0.0.1/purge')
);
pin('no endpoint, no purge', '', Client::purgeUrl('https://example.test/x', ''));
pin('no URL, no purge', '', Client::purgeUrl('', $e));

harness_section('which answers mean there is nothing left to do');

pin('200 — the entry was removed', true, Client::isSettled(200));
pin('412 — there was no entry, which is normal', true, Client::isSettled(Client::NOT_CACHED));
pin('404 — the purge location is not configured, so retry', false, Client::isSettled(404));
pin('0 — the origin was unreachable, so retry', false, Client::isSettled(0));
pin('500 — retry', false, Client::isSettled(500));

harness_section('the Host header is not optional');

/* Reaching the right box and naming the right host are separate requirements: a purge
   that arrives without the public Host computes a different key and deletes nothing,
   answering 412 as though the page had simply not been cached. */
$client   = (string) file_get_contents(ABS_PATH . 'src/Client.php');
$requests = substr_count($client, '->request(');
$hosts    = substr_count($client, "'Host'");
check('every request the client makes names a Host', $requests > 0 && $requests === $hosts, "$requests requests, $hosts Host headers");

/* ---------------------------------------------------------------------------
 * What gets purged
 * ------------------------------------------------------------------------ */
harness_section('the URLs a changed listing invalidates');

$item = array('pk_i_id' => 42, 's_title' => 'a-listing', 'fk_i_category_id' => 7, 'fk_i_user_id' => 3);
$urls = Purge::itemUrls($item);

check('the listing itself', in_array('https://example.test/a-listing_i42', $urls, true));
check('the home page, where it is listed', in_array('https://example.test/', $urls, true));
check('its category', in_array('https://example.test/search/category,7', $urls, true));
check("the seller's public profile", in_array('https://example.test/user/3', $urls, true));
check('and nothing else', count($urls) === 4, implode(' ', $urls));

harness_section('one locale is not a locale prefix');

/* With a single locale the prefixed URL is not a page anyone requests, so purging it
   would only ever be a round trip for a 412. */
check('no per-locale copies', count(Purge::itemUrls($item)) === 4);

$GLOBALS['locales'] = array(array('pk_c_code' => 'en_US'), array('pk_c_code' => 'fr_FR'));
$urls = Purge::itemUrls($item);
check('two locales, two prefixed copies', in_array('https://example.test/en_US/a-listing_i42', $urls, true)
    && in_array('https://example.test/fr_FR/a-listing_i42', $urls, true));
$GLOBALS['locales'] = array(array('pk_c_code' => 'en_US'));

harness_section('a listing whose title cannot be read');

/* The friendly URL is built from the title; without one there is nothing to build, and
   the non-friendly URL is the only form left that still resolves. */
$noTitle = Purge::itemUrls(array('pk_i_id' => 42, 'fk_i_category_id' => 7, 'fk_i_user_id' => 3));
check('falls back to the non-friendly URL', in_array('https://example.test/index.php?page=item&id=42', $noTitle, true));

/* s_title arrives as a per-locale array from some callers; str_replace() on an array
   would return one, and the URL would be built from the word "Array". */
$arrayTitle = Purge::itemUrls(array('pk_i_id' => 42, 's_title' => array('en_US' => 'x'), 'fk_i_category_id' => 7));
check('an array title takes the same fallback', in_array('https://example.test/index.php?page=item&id=42', $arrayTitle, true));

harness_section('a row that did not load');

/* findByPrimaryKey() on a listing that has gone answers an empty array, and an item URL
   built from that names id 0 -- a page that does not exist, purged instead of the one
   that does. */
$empty = Purge::itemUrls(array());
check('no listing URL at all', !in_array('https://example.test/index.php?page=item&id=0', $empty, true));
pin('just the home page', array('https://example.test/'), $empty);

harness_section('missing ids are not purged as id 0');

$bare = Purge::itemUrls(array('pk_i_id' => 42, 's_title' => 'a-listing'));
check('no category URL without a category', !in_array('https://example.test/search/category,0', $bare, true));
check('no profile URL without a seller', !in_array('https://example.test/user/0', $bare, true));

harness_section('a theme can add its own item routes');

$GLOBALS['filters']['nginx_cache_item_urls'] = static function ($urls) {
    $urls[] = 'https://example.test/amp/a-listing_i42';

    return $urls;
};
check('the filter reaches the list', in_array('https://example.test/amp/a-listing_i42', Purge::itemUrls($item), true));
$GLOBALS['filters'] = array();

harness_section('one save, one round trip per URL');

/* Several of the hooks fire for the same listing on a single save. Collecting through
   the request is what stops each of them being its own HTTP request. */
Purge::collect(array('https://example.test/x', 'https://example.test/x', '', 'https://example.test/y'));
$sent = array();
$GLOBALS['filters']['nginx_cache_purge_urls'] = static function ($urls) use (&$sent) {
    $sent = $urls;

    return array();   // deliver nothing; this test is about the list, not the transport
};
Purge::flush();
pin('duplicates collapse and empties are dropped', array('https://example.test/x', 'https://example.test/y'), $sent);

$sent = array();
Purge::flush();
pin('a second flush has nothing left to send', array(), $sent);
$GLOBALS['filters'] = array();

/* ---------------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------------ */
harness_section('the endpoint is an origin, not a page');

pin('the path is dropped', 'http://127.0.0.1', Plugin::originOf('http://127.0.0.1/purge'));
pin('the port is kept', 'http://web:8080', Plugin::originOf('http://web:8080/purge'));
pin('https is kept', 'https://127.0.0.1', Plugin::originOf('https://127.0.0.1/purge/'));
pin('not a URL', '', Plugin::originOf('127.0.0.1/purge'));
pin('empty', '', Plugin::originOf(''));

harness_section('the Host the cache key was built with');

pin('the public host', 'example.test', Plugin::publicHost());

/* The dev harness runs on :8000 and a visitor's Host header says so, so a purge that
   dropped the port would build a key nothing holds. */
pin('...with the port when the site runs on one', 'localhost:8000', Plugin::publicHost('http://localhost:8000/'));
pin('...and without it when it does not', 'shop.example', Plugin::publicHost('https://shop.example/'));
pin('no base URL to read', '', Plugin::publicHost('not a url'));

harness_section('the scheme nginx serves on, which is not always the site\'s');

$_SERVER['REQUEST_SCHEME'] = 'https';
pin('taken from what nginx passed through', 'https', Plugin::originScheme());

/* A proxy in front terminating TLS: the site is https, nginx is handed http, and the
   cache key says http. Reading the site's own base URL here would key every purge wrong. */
$_SERVER['REQUEST_SCHEME'] = 'http';
pin('...even when the site itself is https', 'http', Plugin::originScheme());

unset($_SERVER['REQUEST_SCHEME']);
$_SERVER['HTTPS'] = 'on';
pin('HTTPS=on, for servers that pass no REQUEST_SCHEME', 'https', Plugin::originScheme());
$_SERVER['HTTPS'] = 'off';
pin('HTTPS=off is not https', 'http', Plugin::originScheme());
unset($_SERVER['HTTPS']);
pin('nothing passed at all — the base URL is the last resort', 'https', Plugin::originScheme());

harness_section('defaults');

putenv('SHOPCLASS_PURGE_ENDPOINT');
putenv('SHOPCLASS_PURGE_HOST');
$d = Plugin::defaults();

pin('the endpoint is the loopback, on nginx\'s scheme', 'https://127.0.0.1/purge', $d['purge_endpoint'][0]);
pin('the host is the public one', 'example.test', $d['purge_host'][0]);
pin('an hour, not a day — the token in the page expires at two', '3600', $d['ttl_item'][0]);
pin('nothing is lengthened until a purge has been watched to work', '0', $d['verified'][0]);

putenv('SHOPCLASS_PURGE_ENDPOINT=http://web/purge');
putenv('SHOPCLASS_PURGE_HOST=shop.example');
$d = Plugin::defaults();
pin('the image presets the endpoint', 'http://web/purge', $d['purge_endpoint'][0]);
pin('...and the host', 'shop.example', $d['purge_host'][0]);
putenv('SHOPCLASS_PURGE_ENDPOINT');
putenv('SHOPCLASS_PURGE_HOST');

$types = array();
foreach (Plugin::defaults() as $key => [$value, $type]) {
    if (!in_array($type, array('STRING', 'INTEGER', 'BOOLEAN'), true)) {
        $types[] = $key;
    }
}
pin('every preference declares a type the column accepts', array(), $types);

exit(harness_result());
