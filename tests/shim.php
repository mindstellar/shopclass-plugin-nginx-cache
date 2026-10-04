<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * index.php listens to the old theme/plugin/settings hooks only on a core without
 * osc_purge_page_cache(), so a core that fires page_cache_purge is never purged twice.
 * Each case loads index.php in its own process, because a function cannot be undefined.
 *
 * Usage:  php tests/shim.php
 */

if (($argv[1] ?? '') === 'child') {
    define('ABS_PATH', dirname(__DIR__) . '/');
    $GLOBALS['hooks'] = array();

    if (($argv[2] ?? '') === 'new') {
        function osc_purge_page_cache($reason = '')
        {
        }
    }
    function osc_add_hook($hook, $cb, $priority = 5)
    {
        $GLOBALS['hooks'][] = $hook;
    }
    function osc_add_filter($hook, $cb, $priority = 5)
    {
    }
    function osc_register_plugin($path, $cb)
    {
    }
    function osc_plugin_path($file)
    {
        return 'nginx-cache/index.php';
    }
    function osc_plugin_folder($file)
    {
        return 'nginx-cache/';
    }
    function osc_add_route($id, $regexp, $url, $file)
    {
    }

    require dirname(__DIR__) . '/index.php';
    echo json_encode($GLOBALS['hooks']);
    exit(0);
}

require_once __DIR__ . '/lib/harness.php';

function hooksOn(string $core): array
{
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . $core);

    return (array) json_decode((string) $out, true);
}

$legacy = array('theme_activate', 'after_plugin_activate', 'after_plugin_deactivate', 'admin_form_after_save');

harness_section('a core with osc_purge_page_cache()');

$hooks = hooksOn('new');
check('page_cache_purge is listened to', in_array('page_cache_purge', $hooks, true), json_encode($hooks));
foreach ($legacy as $hook) {
    check('...and ' . $hook . ' is not', !in_array($hook, $hooks, true));
}

harness_section('an older core');

$hooks = hooksOn('old');
check('page_cache_purge is still registered, harmlessly', in_array('page_cache_purge', $hooks, true), json_encode($hooks));
foreach ($legacy as $hook) {
    check('...and so is ' . $hook, in_array($hook, $hooks, true));
}

exit(harness_result());
