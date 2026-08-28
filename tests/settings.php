<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Saving the settings, and the two rules that keep the gate honest.
 *
 * A verdict belongs to the settings it was reached with: change where a purge goes or
 * what it names and the last test says nothing about the new values, so the gate has to
 * close. And a window longer than the token inside the page survives is refused rather
 * than saved, because the self-test cannot catch that one — purging keeps working
 * perfectly while every form on the page starts reporting an expired session.
 *
 * Usage:  php tests/settings.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['prefs'][$section][$key] ?? '';
}
function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    $GLOBALS['prefs'][$section][$key] = $value;
}
function osc_reset_preferences()
{
}
function osc_base_url($withIndex = false)
{
    return 'http://shop.example/';
}
function osc_route_admin_url($id, $args = array())
{
    return 'http://shop.example/oc-admin/?route=' . $id;
}
function osc_csrf_check()
{
    $GLOBALS['csrfChecked'] = true;
}
function osc_add_flash_ok_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = array('ok', $msg);
}
function osc_add_flash_error_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = array('error', $msg);
}
function osc_add_flash_warning_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = array('warning', $msg);
}
function osc_redirect_to($url)
{
    throw new RuntimeException('redirect:' . $url);
}
function __($s, $domain = '')
{
    return $s;
}

/** The request, as core's typed accessors read it. */
class Params
{
    public static $values = array();

    public static function getParamString($key, $trim = false)
    {
        $v = self::$values[$key] ?? '';

        return is_string($v) ? $v : '';
    }

    public static function getParamInt($key)
    {
        return (int) (self::$values[$key] ?? 0);
    }
}

$GLOBALS['prefs'] = array();
$GLOBALS['flash'] = array();

require_once __DIR__ . '/lib/fake-client.php';
require_once ABS_PATH . 'src/Plugin.php';
require_once ABS_PATH . 'src/Queue.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\nginxcache\Client;
use mindstellar\nginxcache\Plugin;
use mindstellar\nginxcache\Queue;

/** Settings as they stand before a POST. */
function stored(array $overrides = array()): void
{
    $GLOBALS['prefs']['nginx_cache'] = array_merge(array(
        'purge_endpoint' => 'http://127.0.0.1/purge',
        'purge_host'     => 'shop.example',
        'ttl_item'       => '3600',
        'ttl_page'       => '3600',
        'ttl_aggregate'  => '3600',
        'verified'       => '1',
        'last_test'      => '{"ok":true}',
        'queue'          => '',
    ), $overrides);
}

/** Submit the form. Returns the URL it redirected to, or '' if it did not. */
function submit(array $fields): string
{
    Params::$values      = $fields;
    $GLOBALS['flash']    = array();
    $GLOBALS['csrfChecked'] = false;
    Client::reset();

    try {
        Plugin::handleAdminPost();
    } catch (RuntimeException $e) {
        return substr($e->getMessage(), strlen('redirect:'));
    }

    return '';
}

/** The whole form, as the page posts it. */
function form(string $action, array $overrides = array()): array
{
    return array_merge(array(
        'nginx_cache_action' => $action,
        'purge_endpoint'     => 'http://127.0.0.1/purge',
        'purge_host'         => 'shop.example',
        'ttl_item'           => '3600',
        'ttl_page'           => '3600',
        'ttl_aggregate'      => '3600',
    ), $overrides);
}

function pref(string $key)
{
    return $GLOBALS['prefs']['nginx_cache'][$key] ?? null;
}

harness_section('a request that is not this form');

stored();
pin('nothing happens on an ordinary admin page', '', submit(array()));
check('...and no token is spent on it', $GLOBALS['csrfChecked'] === false);
pin('...and nothing is written', '3600', pref('ttl_item'));

harness_section('saving');

stored();
$to = submit(form('save', array('ttl_item' => '1800')));
check('the token is checked', $GLOBALS['csrfChecked'] === true);
pin('the value is stored', '1800', pref('ttl_item'));
pin('and it says so', 'ok', $GLOBALS['flash'][0][0] ?? '');
check('and it lands back on the settings page', strpos($to, 'nginx-cache-settings') !== false, $to);

harness_section('a verdict belongs to the settings it was reached with');

stored();
submit(form('save', array('purge_endpoint' => 'http://web/purge')));
pin('a new endpoint closes the gate', '0', pref('verified'));
pin('...and clears the verdict with it', '', pref('last_test'));

stored();
submit(form('save', array('purge_host' => 'shop.example:8080')));
pin('a new host closes it too', '0', pref('verified'));

stored();
submit(form('save', array('ttl_item' => '900')));
pin('but a window is not a reason to re-test', '1', pref('verified'));

stored();
submit(form('save'));
pin('nor is saving the same thing twice', '1', pref('verified'));

harness_section('a window longer than the token in the page survives');

stored();
submit(form('save', array('ttl_item' => '86400')));
pin('shortened to the ceiling', (string) Plugin::TTL_MAX, pref('ttl_item'));
pin('and it is said out loud', 'warning', $GLOBALS['flash'][0][0] ?? '');
check('...in terms of what breaks, not just a number',
    strpos((string) ($GLOBALS['flash'][0][1] ?? ''), 'session has expired') !== false,
    (string) ($GLOBALS['flash'][0][1] ?? ''));

stored();
submit(form('save', array('ttl_item' => '-5', 'ttl_page' => 'soon')));
pin('a negative window is none at all', '0', pref('ttl_item'));
pin('and neither is a word', '0', pref('ttl_page'));
check('nothing to warn about there', ($GLOBALS['flash'][0][0] ?? '') === 'ok');

pin('the ceiling itself is allowed', Plugin::TTL_MAX, Plugin::clampTtl(Plugin::TTL_MAX));
pin('a second past it is not', Plugin::TTL_MAX, Plugin::clampTtl(Plugin::TTL_MAX + 1));

harness_section('testing the purge from the page');

stored(array('verified' => '0'));
Client::reset();
Client::$probes = array(
    array('status' => 200, 'cache' => 'MISS'),
    array('status' => 200, 'cache' => 'HIT'),
    array('status' => 200, 'cache' => 'MISS'),
);
Client::$answers = array('http://shop.example/' => 200);
Params::$values = form('test');
$GLOBALS['flash'] = array();
try {
    Plugin::handleAdminPost();
} catch (RuntimeException $e) {
}
pin('a passing test opens the gate', '1', pref('verified'));
pin('and reports it', 'ok', $GLOBALS['flash'][0][0] ?? '');

/* The form is saved before the test runs, so Test purge tests what is on screen rather
   than what was on screen last time. */
stored(array('verified' => '0'));
Client::reset();
Client::$probes = array(array('status' => 0, 'cache' => ''));
Params::$values   = form('test', array('purge_host' => 'typed.just.now'));
$GLOBALS['flash'] = array();
try {
    Plugin::handleAdminPost();
} catch (RuntimeException $e) {
}
pin('the typed value was saved first', 'typed.just.now', pref('purge_host'));
pin('a failing test is reported as one', 'error', $GLOBALS['flash'][0][0] ?? '');

harness_section('retrying what could not be delivered');

stored(array('queue' => json_encode(array(
    'http://shop.example/a' => time(),
    'http://shop.example/b' => time(),
))));
Client::reset();
Client::$answers = array('http://shop.example/a' => 200);
Params::$values  = form('retry_queue');
$GLOBALS['flash'] = array();
try {
    Plugin::handleAdminPost();
} catch (RuntimeException $e) {
}
pin('the one that went through is gone', 1, count(Queue::load()));
check('and the count is reported', strpos((string) ($GLOBALS['flash'][0][1] ?? ''), '1 of 2') !== false,
    (string) ($GLOBALS['flash'][0][1] ?? ''));

exit(harness_result());
