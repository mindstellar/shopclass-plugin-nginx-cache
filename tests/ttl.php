<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * How long a page may be held — the one filter that makes this plugin do anything.
 *
 * Two rules, and both are about not making things worse. Nothing is lengthened until a
 * purge has been watched to work, because a long window over a broken purge is strictly
 * worse than the short one it replaced. And a page is only lengthened if the purge could
 * name it: a search carrying parameters is its own cache entry per keyword, filter, sort
 * and page number, and a newly posted listing has to appear in it.
 *
 * Usage:  php tests/ttl.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['prefs'][$section][$key] ?? '';
}
function osc_get_bool_preference($key, $section = 'osclass')
{
    return (bool) osc_get_preference($key, $section);
}
function osc_is_ad_page()
{
    return $GLOBALS['page'] === 'item';
}
function osc_is_static_page()
{
    return $GLOBALS['page'] === 'page';
}
function osc_is_home_page()
{
    return $GLOBALS['page'] === 'home';
}
function osc_is_public_profile()
{
    return $GLOBALS['page'] === 'profile';
}
function osc_is_search_page()
{
    return $GLOBALS['page'] === 'search';
}
function osc_rewrite_enabled()
{
    return $GLOBALS['rewrite'];
}

/** The request's parameters, as core's own page-type helpers read them. */
class Params
{
    public static function getParamsAsArray()
    {
        return $GLOBALS['params'];
    }
}

$GLOBALS['prefs'] = array('nginx_cache' => array(
    'verified' => '1', 'ttl_item' => '3600', 'ttl_page' => '3600', 'ttl_aggregate' => '3600',
));
$GLOBALS['page']    = '';
$GLOBALS['params']  = array();
$GLOBALS['rewrite'] = true;
$_SERVER['QUERY_STRING'] = '';

require_once ABS_PATH . 'src/Plugin.php';
require_once ABS_PATH . 'src/Ttl.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\nginxcache\Ttl;

/** Core's default is 30; this is what the filter answers for a given page. */
function ttl(string $page, array $params = array()): int
{
    $GLOBALS['page']   = $page;
    $GLOBALS['params'] = $params;

    return Ttl::forCurrentPage(30);
}

harness_section('until a purge has been watched to work, nothing changes');

$GLOBALS['prefs']['nginx_cache']['verified'] = '0';
foreach (array('item', 'page', 'home', 'profile') as $page) {
    pin("$page keeps core's own window", 30, ttl($page));
}
pin('a plain category too', 30, ttl('search', array('sCategory' => '7')));

harness_section('with the purge confirmed, nameable pages are held');

$GLOBALS['prefs']['nginx_cache']['verified'] = '1';
pin('a listing', 3600, ttl('item'));
pin('a static page', 3600, ttl('page'));
pin('the home page', 3600, ttl('home'));
pin('a seller profile', 3600, ttl('profile'));
pin('a category browse', 3600, ttl('search', array('page' => 'search', 'sCategory' => '7')));

harness_section('a search that cannot be named keeps the short window');

pin('a keyword', 30, ttl('search', array('sCategory' => '7', 'sPattern' => 'bike')));
pin('a region', 30, ttl('search', array('sCategory' => '7', 'sRegion' => '3')));
pin('nothing but a keyword', 30, ttl('search', array('sPattern' => 'bike')));
pin('several categories at once', 30, ttl('search', array('sCategory' => '7,8')));
pin('a category as an array', 30, ttl('search', array('sCategory' => array('7'))));

/* Page two of a category and a re-sorted category are separate cache entries, and Purge
   names neither — only the bare category URL. Core's own osc_is_search_category_page()
   counts these as the category page; this deliberately does not. */
pin('page two of a category', 30, ttl('search', array('sCategory' => '7', 'iPage' => '2')));
pin('a re-sorted category', 30, ttl('search', array('sCategory' => '7', 'sOrder' => 'i_price', 'iOrderType' => 'asc')));

harness_section('a URL nobody will purge');

/* Every one of these is the same page by every helper core has, and every one of them is
   its own cache entry that Purge does not name. A campaign tag on the home page is the
   one that would bite: the link is shared, the entry is held for an hour, and no post,
   edit or delete can clear it. */
$_SERVER['QUERY_STRING'] = 'utm_source=newsletter';
pin('the home page with a campaign tag', 30, ttl('home'));
pin('a listing with a comment page', 30, ttl('item'));
pin('a profile with anything appended', 30, ttl('profile'));
$_SERVER['QUERY_STRING'] = '';
pin('...and the canonical URL is still held', 3600, ttl('home'));

/* Without permalinks the canonical URL of every page carries a query string of its own,
   so none of them can be told apart from the case above. Nothing is lengthened. */
$GLOBALS['rewrite'] = false;
pin('permalinks off: a listing', 30, ttl('item'));
pin('permalinks off: the home page', 30, ttl('home'));
$GLOBALS['rewrite'] = true;

harness_section('a page type nobody named');

pin('the contact form is not lengthened', 30, ttl('contact'));
pin('nor a login page', 30, ttl('login'));

harness_section('the filter lengthens; it never shortens');

/* A configured value below core's own default is a misconfiguration, not an instruction:
   this plugin exists to hold pages for longer. */
$GLOBALS['prefs']['nginx_cache']['ttl_item'] = '5';
pin('a smaller setting is ignored', 30, ttl('item'));

$GLOBALS['prefs']['nginx_cache']['ttl_item'] = '';
pin('an empty setting is ignored', 30, ttl('item'));

/* Core deciding a page must not be cached at all has to survive this filter: the value
   it passes is the answer, and there is nothing to lengthen. */
$GLOBALS['prefs']['nginx_cache']['ttl_item'] = '3600';
$GLOBALS['page'] = 'item';
pin('a page core will not cache stays uncached', 0, Ttl::forCurrentPage(0));

exit(harness_result());
