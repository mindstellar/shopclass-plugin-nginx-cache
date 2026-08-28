<?php
/*
Plugin Name: nginx Cache
Plugin URI: https://github.com/mindstellar/shopclass-plugin-nginx-cache
Description: Hold pages in nginx's FastCGI cache for an hour instead of thirty seconds, and purge them the moment a listing changes — including after a storage offload rewrites its image URLs.
Version: 0.1.0
Author: Mindstellar Community
Author URI: https://mindstellar.com
Short Name: nginx-cache
Requires Shopclass: 6.2.0
Tested up to: 6.2
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-nginx-cache/issues
*/

/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\nginxcache\Plugin;
use mindstellar\nginxcache\Purge;
use mindstellar\nginxcache\Ttl;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

require_once __DIR__ . '/src/Plugin.php';
require_once __DIR__ . '/src/Ttl.php';
require_once __DIR__ . '/src/Purge.php';
require_once __DIR__ . '/src/Client.php';
require_once __DIR__ . '/src/Queue.php';

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', 'nginx_cache_configure');

// ── Admin ────────────────────────────────────────────────────────────────────
osc_add_route(
    'nginx-cache-settings',
    'nginx-cache/settings',
    'nginx-cache/settings',
    osc_plugin_folder(__FILE__) . 'admin/settings.php'
);
osc_add_route(
    'nginx-cache-help',
    'nginx-cache/help',
    'nginx-cache/help',
    osc_plugin_folder(__FILE__) . 'admin/help.php'
);

function nginx_cache_admin_menu()
{
    osc_admin_menu_plugins(__('nginx Cache', 'nginx-cache'), osc_route_admin_url('nginx-cache-settings'), 'nginx-cache-menu');
    osc_admin_menu_plugins(__('nginx Cache: Setup', 'nginx-cache'), osc_route_admin_url('nginx-cache-help'), 'nginx-cache-help-menu');
}
function nginx_cache_configure()
{
    osc_redirect_to(osc_route_admin_url('nginx-cache-settings'));
}
osc_add_hook('admin_menu_init', 'nginx_cache_admin_menu');
osc_add_hook('init_admin', array(Plugin::class, 'handleAdminPost'));

// ── How long a page may be held ──────────────────────────────────────────────
// The only thing that lengthens the window. Deliberately gated: Ttl returns core's
// own default until the purge self-test has passed, so an install that is
// misconfigured behaves exactly as it does today rather than a great deal worse.
osc_add_filter('public_cache_max_age', array(Ttl::class, 'forCurrentPage'));

// ── What makes a page stale ──────────────────────────────────────────────────
// The same hook set the Cloudflare plugin uses, which is the tested list of every
// event core actually fires, plus invalidate_item_cache -- the one that carries a
// completed storage offload. Nothing else announces that, and an offload rewrites
// a listing's image URLs, so without it a cached page keeps pointing at local
// files that have been moved to the remote.
osc_add_hook('posted_item', array(Purge::class, 'onItemArray'));
osc_add_hook('edited_item', array(Purge::class, 'onItemArray'));
osc_add_hook('after_delete_item', array(Purge::class, 'onItemDeleted'));
osc_add_hook('invalidate_item_cache', array(Purge::class, 'onItemId'));
foreach (array(
    'enable_item',
    'disable_item',
    'activate_item',
    'deactivate_item',
    'item_premium_on',
    'item_premium_off',
    'item_expiration_updated',
) as $nginx_cache_hook) {
    osc_add_hook($nginx_cache_hook, array(Purge::class, 'onItemId'));
}
unset($nginx_cache_hook);

osc_add_hook('add_category', array(Purge::class, 'onCategory'));
osc_add_hook('after_delete_category', array(Purge::class, 'onCategory'));
osc_add_hook('edit_page', array(Purge::class, 'onPage'));
osc_add_hook('after_delete_page', array(Purge::class, 'onPage'));

// One request may fire several of the hooks above for the same listing. Collect the
// URLs, de-duplicate, and send once the response is out of the way.
osc_add_hook('shutdown_functions', array(Purge::class, 'registerFlush'));

// Anything the origin refused or could not be told about is retried, rather than
// left as a page that stays wrong until its hour is up.
osc_add_hook('cron_hourly', array(Queue::class, 'retry'));
