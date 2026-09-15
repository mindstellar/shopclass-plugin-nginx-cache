<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The gate: what the self-test checks, and what it refuses to take on trust.
 *
 * It exists because a purge that does not work is indistinguishable from a purge with
 * nothing to do — both answer 412 and neither is logged anywhere. Everything it decides
 * is pinned here, but one assertion matters more than the rest: the entry it primes is
 * created with the *site's* host and removed with the *configured* one. Prime with the
 * host you purge with and the test proves only that it agrees with itself, which is
 * exactly the state it is meant to catch.
 *
 * The transport is replaced with a recorder, so this runs with no nginx.
 * Usage:  php tests/self-test.php
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
function osc_base_url($withIndex = false)
{
    return $GLOBALS['baseUrl'];
}
function __($s, $domain = '')
{
    return $s;
}

$GLOBALS['baseUrl'] = 'http://shop.example:8000/';
$GLOBALS['prefs']   = array('nginx_cache' => array(
    'purge_endpoint' => 'http://127.0.0.1/purge',
    'purge_host'     => 'shop.example:8000',
));

require_once __DIR__ . '/lib/fake-client.php';
require_once ABS_PATH . 'src/Plugin.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\nginxcache\Client;
use mindstellar\nginxcache\Plugin;

/**
 * Run the test with a scripted sequence of nginx answers.
 *
 * @param array $probes  what each GET reports, in order
 * @param int   $purge   what the purge answers
 */
function run(array $probes, int $purge = 200, array $settings = array()): array
{
    $GLOBALS['prefs']['nginx_cache'] = array_merge(array(
        'purge_endpoint' => 'http://127.0.0.1/purge',
        'purge_host'     => 'shop.example:8000',
    ), $settings);

    Client::reset();
    Client::$probes  = $probes;
    Client::$answers = array($GLOBALS['baseUrl'] => $purge);
    unset($_SERVER['REQUEST_SCHEME'], $_SERVER['HTTPS']);

    return Plugin::selfTest();
}

$hit  = array('status' => 200, 'cache' => 'HIT');
$miss = array('status' => 200, 'cache' => 'MISS');
$good = array($miss, $hit, $miss);

harness_section('a purge watched from end to end');

$r = run($good);
check('it passes', $r['ok'], $r['message']);
pin('...and the gate opens', '1', $GLOBALS['prefs']['nginx_cache']['verified']);
check('...with the verdict kept for the settings page', strpos((string) $GLOBALS['prefs']['nginx_cache']['last_test'], '"ok":true') !== false);

harness_section('an entry is an entry, whatever nginx calls it');

/* A page past its window is served stale while it refreshes behind the request, and one
   being revalidated says EXPIRED. All of those are entries a purge can remove; requiring
   HIT failed the test whenever it happened to run during a refresh. */
foreach (array('HIT', 'STALE', 'UPDATING', 'EXPIRED', 'REVALIDATED') as $state) {
    $r = run(array($miss, array('status' => 200, 'cache' => $state), $miss));
    check('X-Cache: ' . $state . ' counts as held', $r['ok'], $r['message']);
}
foreach (array('MISS', 'BYPASS') as $state) {
    $r = run(array($miss, array('status' => 200, 'cache' => $state), $miss));
    check('X-Cache: ' . $state . ' does not', !$r['ok']);
}

/* And the same rule read backwards: after the purge, anything but a miss means the entry
   is still there. */
$r = run(array($miss, $hit, array('status' => 200, 'cache' => 'UPDATING')));
check('a page still refreshing after the purge is a failure', !$r['ok'], $r['message']);
check('...saying what nginx answered', strpos($r['message'], 'UPDATING') !== false, $r['message']);

harness_section('every host in the list is proved on its own');

/* nginx files a copy of every page under each Host it was asked with, and priming with
   `Host: X` creates an entry keyed on X — so each name can be proved rather than assumed
   from another one working. */
$r = run(array($miss, $hit, $miss, $miss, $hit, $miss), 200, array(
    'purge_endpoint' => 'http://127.0.0.1/purge',
    'purge_host'     => "shop.example:8000\nwww.shop.example:8000",
));
check('two hosts, both confirmed', $r['ok'], $r['message']);
check('...and it says how many', strpos($r['message'], '2 hosts') !== false, $r['message']);
pin('each was primed and re-checked under its own name', array(
    'shop.example:8000', 'shop.example:8000', 'shop.example:8000',
    'www.shop.example:8000', 'www.shop.example:8000', 'www.shop.example:8000',
), array_column(Client::$probeCalls, 'host'));
pin('and purged under its own name', array('shop.example:8000', 'www.shop.example:8000'), Client::$purgeHosts);

/* A second host that is not caching is a failure even though the first one worked --
   otherwise the list would be a way of hiding hosts nothing purges. */
$r = run(array($miss, $hit, $miss, $miss, $miss), 200, array(
    'purge_endpoint' => 'http://127.0.0.1/purge',
    'purge_host'     => "shop.example:8000\nwww.shop.example:8000",
));
check('one bad host fails the lot', !$r['ok']);
check('...naming which one', strpos($r['message'], 'www.shop.example:8000') !== false, $r['message']);

harness_section('...but the list still has to name what visitors send');

/* The one thing priming cannot prove. A list of names nobody uses would verify itself
   perfectly and purge nothing anybody reads, so this check does not come from nginx: the
   site's own host has to be in the list. */
$r = run($good, 200, array(
    'purge_endpoint' => 'http://127.0.0.1/purge',
    'purge_host'     => 'something.else',
));
check('it fails', !$r['ok']);
check('...naming the host visitors use', strpos($r['message'], 'shop.example:8000') !== false, $r['message']);
check('...and what was listed instead', strpos($r['message'], 'something.else') !== false, $r['message']);
pin('...before spending a request on it', 0, count(Client::$probeCalls));
pin('...and the gate stays shut', '0', $GLOBALS['prefs']['nginx_cache']['verified']);

harness_section('a list is one value, however it is typed');

foreach (array(
    "shop.example:8000\nwww.shop.example:8000" => 'newlines',
    'shop.example:8000, www.shop.example:8000'  => 'commas',
    ' shop.example:8000   www.shop.example:8000 ' => 'spaces',
    "shop.example:8000,,\n shop.example:8000 \nwww.shop.example:8000" => 'repeats and blanks',
) as $raw => $how) {
    pin('parsed by ' . $how, array('shop.example:8000', 'www.shop.example:8000'), Plugin::parseHosts($raw));
}
pin('nothing at all', array(), Plugin::parseHosts('   '));

harness_section('the scheme is in the key too, and cannot be tested the same way');

/* The prime is forced onto the endpoint's scheme, so an https site with an http endpoint
   would prime and purge one key while visitors filed pages under another — and the test
   could not see it. It is checked against what nginx told PHP instead. */
$_SERVER['REQUEST_SCHEME'] = 'https';
$GLOBALS['prefs']['nginx_cache'] = array('purge_endpoint' => 'http://127.0.0.1/purge', 'purge_host' => 'shop.example:8000');
Client::reset();
Client::$probes = $good;
$r = Plugin::selfTest();
check('an http endpoint on an https origin fails', !$r['ok']);
check('...saying which scheme to use', strpos($r['message'], 'https') !== false, $r['message']);
pin('...before spending a request on it', 0, count(Client::$probeCalls));
unset($_SERVER['REQUEST_SCHEME']);

harness_section('everything else it refuses to pass');

$cases = array(
    'nothing configured'          => array(array(), 200, array('purge_endpoint' => '', 'purge_host' => '')),
    'an endpoint that is not a URL' => array(array(), 200, array('purge_endpoint' => '127.0.0.1/purge', 'purge_host' => 'shop.example:8000')),
    'an origin that cannot be reached' => array(array(array('status' => 0, 'cache' => '')), 200, array()),
    'an origin answering something else' => array(array(array('status' => 502, 'cache' => '')), 200, array()),
    'an nginx not sending X-Cache' => array(array(array('status' => 200, 'cache' => '')), 200, array()),
    'a page nginx will not hold'   => array(array($miss, $miss), 200, array()),
    'no purge location'            => array(array($miss, $hit), 404, array()),
    'a page still served after the purge' => array(array($miss, $hit, $hit), 200, array()),
);
foreach ($cases as $label => [$probes, $purge, $settings]) {
    $r = run($probes, $purge, $settings);
    check($label . ' — fails', !$r['ok'], $r['message']);
    check($label . ' — and says why', $r['message'] !== '' && substr($r['message'], -1) === '.', $r['message']);
}

harness_section('a passing test after a failing one reopens the gate');

run(array($miss, $hit), 412);
pin('shut', '0', $GLOBALS['prefs']['nginx_cache']['verified']);
run($good);
pin('open', '1', $GLOBALS['prefs']['nginx_cache']['verified']);

exit(harness_result());
