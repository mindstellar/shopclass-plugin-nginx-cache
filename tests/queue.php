<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The fallback queue: what it keeps, what it drops, and what it never does.
 *
 * Purging is immediate — this holds only what the origin refused. Three of its rules are
 * the kind that look like details and are not: an entry ages from the *first* failure
 * rather than the last attempt, a retry that fails again must not re-queue itself into
 * the list it came out of, and an entry older than the longest window is pointless work
 * because whatever it would purge has already expired.
 *
 * The transport is replaced with a recorder here, so this runs with no nginx and no
 * network.  Usage:  php tests/queue.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['prefs'][$section][$key] ?? '';
}
function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
{
    $GLOBALS['writes'][] = $key;
    $GLOBALS['prefs'][$section][$key] = $value;
}

$GLOBALS['prefs'] = array('nginx_cache' => array(
    'ttl_item' => '3600', 'ttl_page' => '3600', 'ttl_aggregate' => '7200',
    'purge_host' => 'shop.example',
));
$GLOBALS['writes'] = array();

require_once ABS_PATH . 'src/Plugin.php';
require_once __DIR__ . '/lib/fake-client.php';
require_once ABS_PATH . 'src/Queue.php';
require_once __DIR__ . '/lib/harness.php';


use mindstellar\nginxcache\Client;
use mindstellar\nginxcache\Queue;

/** Put a queue on disk directly, so ages can be older than this test run. */
function seed(array $entries): void
{
    $GLOBALS['prefs']['nginx_cache']['queue'] = $entries === array() ? '' : json_encode($entries);
    Client::reset();
    $GLOBALS['writes'] = array();
}

harness_section('what goes in');

seed(array());
Queue::add('https://example.test/a');
Queue::add('https://example.test/b');
pin('two failures, two entries', 2, count(Queue::load()));

$first = Queue::load()['https://example.test/a'];
Queue::add('https://example.test/a');
pin('a repeat keeps the first failure\'s time — age is how long the page has been wrong', $first, Queue::load()['https://example.test/a']);

seed(array());
Queue::add('');
pin('an empty URL is not an entry', 0, count(Queue::load()));

harness_section('it cannot grow without bound');

seed(array());
for ($i = 0; $i < Queue::CAP + 5; $i++) {
    Queue::add('https://example.test/' . $i);
}
$entries = Queue::load();
pin('capped', Queue::CAP, count($entries));
check('the oldest went first', !isset($entries['https://example.test/0']));
check('...and the newest is still there', isset($entries['https://example.test/' . (Queue::CAP + 4)]));

harness_section('what comes out');

$now = time();
seed(array(
    'https://example.test/gone'   => $now - 10,
    'https://example.test/nocopy' => $now - 10,
    'https://example.test/down'   => $now - 10,
));
Client::$answers = array(
    'https://example.test/gone'   => 200,
    'https://example.test/nocopy' => 412,
    'https://example.test/down'   => 0,
);
Queue::retry();
$left = Queue::load();

check('a purged entry is done', !isset($left['https://example.test/gone']));
check('so is one nginx never held', !isset($left['https://example.test/nocopy']));
check('one the origin refused stays for the next tick', isset($left['https://example.test/down']));
check('and it is not re-queued as a second entry', count($left) === 1, json_encode($left));
check('the batch path is never used from a retry', !in_array('PURGE-BATCH', Client::$calls, true));

harness_section('a site on more than one hostname');

/* nginx files a copy of every page under each Host it was asked with, so an entry is
   only done when every one of them has been told. Re-sending a host that already
   succeeded is free: it answers 412, which counts as settled. */
$GLOBALS['prefs']['nginx_cache']['purge_host'] = "shop.example\nwww.shop.example";

seed(array('https://shop.example/a' => $now - 10));
Client::$answers = array(
    'shop.example|https://shop.example/a'     => 200,
    'www.shop.example|https://shop.example/a' => 0,
);
Queue::retry();
pin('both hosts are tried', 2, count(Client::$calls));
pin('...and named', array('shop.example', 'www.shop.example'), Client::$purgeHosts);
check('one host still failing keeps the entry', isset(Queue::load()['https://shop.example/a']));

seed(array('https://shop.example/a' => $now - 10));
Client::$answers = array(
    'shop.example|https://shop.example/a'     => 200,
    // Already purged on the earlier attempt; re-sending it is free and counts as done.
    'www.shop.example|https://shop.example/a' => 412,
);
Queue::retry();
pin('every host settled, so it is done', 0, count(Queue::load()));

$GLOBALS['prefs']['nginx_cache']['purge_host'] = 'shop.example';

harness_section('an entry older than the window it protects');

/* The longest configured TTL here is 7200. Anything queued before that has nothing left
   to purge — the page it would have invalidated expired on its own. */
seed(array(
    'https://example.test/stale' => $now - 7300,
    'https://example.test/fresh' => $now - 60,
));
Client::$answers = array('https://example.test/fresh' => 0);
Queue::retry();
$left = Queue::load();

check('dropped without a round trip', !in_array('https://example.test/stale', Client::$calls, true));
check('...and it is gone', !isset($left['https://example.test/stale']));
check('the one still inside the window is retried', in_array('https://example.test/fresh', Client::$calls, true));

harness_section('nothing queued, nothing done');

seed(array());
Queue::retry();
pin('no request', 0, count(Client::$calls));
pin('and no write', 0, count($GLOBALS['writes']));

harness_section('a queue that is not a queue');

foreach (array('not json', '"a string"', '[1,2,3]', 'null') as $junk) {
    $GLOBALS['prefs']['nginx_cache']['queue'] = $junk;
    pin('ignored: ' . $junk, array(), Queue::load());
}

$GLOBALS['prefs']['nginx_cache']['queue'] = json_encode(array('https://example.test/ok' => 123, '' => 5, 'x' => 'later'));
pin('only well-formed entries survive a read', array('https://example.test/ok' => 123), Queue::load());

exit(harness_result());
