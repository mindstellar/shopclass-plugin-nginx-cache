<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Purge everything: what the page_cache_purge listener sends, and what it falls back to
 * when nginx has no purge_all line.
 *
 * Usage:  php tests/purge-all.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

function osc_base_url($withIndex = false)
{
    return 'https://example.test/';
}
function osc_search_url($params = null)
{
    return 'https://example.test/search/category,' . ($params['sCategory'] ?? '');
}
function osc_rewrite_enabled()
{
    return true;
}
function osc_get_locales()
{
    return array(array('pk_c_code' => 'en_US'));
}
function osc_sitemap_robots_line()
{
    return 'Sitemap: https://example.test/sitemapindex.xml';
}
function osc_apply_filter($hook, $content, ...$args)
{
    return $content;
}
function osc_get_preference($key, $section = 'osclass')
{
    if ($section === 'osclass' && $key === 'rewrite_page_url') {
        return '{PAGE_SLUG}-p{PAGE_ID}';
    }

    return $GLOBALS['prefs'][$section][$key] ?? '';
}
function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    $GLOBALS['prefs'][$section][$key] = $value;
}

class Category
{
    public static function newInstance()
    {
        return new self();
    }

    public function listEnabled()
    {
        return array(array('pk_i_id' => 3), array('pk_i_id' => 7));
    }
}

class Page
{
    public static function newInstance()
    {
        return new self();
    }

    public function listAll($indelible = null)
    {
        $GLOBALS['pageListArg'] = $indelible;

        return array(array('pk_i_id' => 5, 's_internal_name' => 'about'));
    }
}

$GLOBALS['prefs'] = array('nginx_cache' => array(
    'purge_endpoint' => 'http://127.0.0.1/purge',
    'purge_host'     => 'example.test',
    'ttl_item'       => '3600',
));

require_once ABS_PATH . 'src/Plugin.php';
require_once __DIR__ . '/lib/fake-client.php';
require_once ABS_PATH . 'src/Queue.php';
require_once ABS_PATH . 'src/Purge.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\nginxcache\Client;
use mindstellar\nginxcache\Purge;
use mindstellar\nginxcache\Queue;

function fresh(int $answer, array $queue = array()): void
{
    Client::reset();
    Client::$purgeAllAnswer = $answer;
    $GLOBALS['prefs']['nginx_cache']['queue'] = $queue === array() ? '' : json_encode($queue);
}

harness_section('core asks for everything to go');

fresh(200, array('https://example.test/old' => time()));
$cleared = Purge::onPurgeAll(array('theme'));
check('it reports the cache cleared', $cleared);
pin('exactly one PURGE is sent', 1, Client::$purgeAllCalls);
pin('...and nothing per URL', array(), Client::$calls);
pin('the queue is superseded', array(), Queue::load());

foreach (array(404, 412) as $status) {
    fresh($status);
    check($status . ' means the zone was already empty', Purge::onPurgeAll());
}

harness_section('URLs collected earlier in the request go with it');

fresh(200);
Purge::collect(array('https://example.test/a-listing_i1'));
Purge::onPurgeAll(array('settings'));
Purge::flush();
pin('the shutdown flush has nothing left to send', array(), Client::$calls);

harness_section('nginx will not purge everything');

foreach (array(403, 405, 0) as $status) {
    fresh($status);
    $cleared = Purge::onPurgeAll(array('theme'));
    check($status . ': reported as not cleared', !$cleared);
    check($status . ': the purge-everything entry is queued', isset(Queue::load()[Queue::EVERYTHING]));
}

$sent = Client::$batchUrls;
foreach (array(
    'https://example.test/',
    'https://example.test/search/category,3',
    'https://example.test/search/category,7',
    'https://example.test/about-p5',
    'https://example.test/sitemapindex.xml',
) as $url) {
    check('the fallback purges ' . $url, in_array($url, $sent, true), json_encode($sent));
}
pin('...each once', count(array_unique($sent)), count($sent));
pin('static pages exclude the e-mail templates', 0, $GLOBALS['pageListArg']);

harness_section('older cores: their hooks ask once, shutdown purges once');

fresh(200);
Purge::requestPurgeAll('bender');
Purge::requestPurgeAll();
Purge::collect(array('https://example.test/x'));
Purge::flush();
pin('two events, one PURGE', 1, Client::$purgeAllCalls);
pin('...and the pending URL is dropped', array(), Client::$calls);
Purge::flush();
pin('a second flush sends nothing more', 1, Client::$purgeAllCalls);

harness_section('which hooks lead to a purge of everything');

$withCore = Purge::purgeAllHooks(true);
pin('a core that signals: page_cache_purge only', array('page_cache_purge'), array_keys($withCore));
pin('...handled by onPurgeAll', array(Purge::class, 'onPurgeAll'), $withCore['page_cache_purge']);

$old = Purge::purgeAllHooks(false);
pin('an older core: the shim hooks as well',
    array('page_cache_purge', 'theme_activate', 'after_plugin_activate', 'after_plugin_deactivate', 'admin_form_after_save'),
    array_keys($old));
pin('...which only ask for a purge at shutdown', array(Purge::class, 'requestPurgeAll'), $old['theme_activate']);

harness_section('a PURGE that reached PHP');

check('PURGE is refused', Purge::isStrayPurge('PURGE'));
check('...in any case', Purge::isStrayPurge('purge'));
check('GET is not', !Purge::isStrayPurge('GET'));
check('nor is no method at all', !Purge::isStrayPurge(''));

exit(harness_result());
