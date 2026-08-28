<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Render-only. Every POST is handled by Plugin::handleAdminPost() on init_admin,
 * before this page prints. One form, several submit buttons (name="nginx_cache_action").
 */

use mindstellar\nginxcache\Plugin;
use mindstellar\nginxcache\Queue;
use mindstellar\nginxcache\Setup;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$e         = 'osc_esc_html';
$verified  = (bool) osc_get_bool_preference('verified', Plugin::SECTION);
$last      = json_decode((string) Plugin::get('last_test'), true);
$queued    = count(Queue::load());
$suggested = Setup::suggested();
$helpUrl   = osc_route_admin_url('nginx-cache-help');
$selfUrl   = osc_route_admin_url('nginx-cache-settings');
?>
<style>
.ngxc { max-width: 880px; }
.ngxc .card { border-color: var(--bs-border-color); }
.ngxc .card + .card { margin-top: 1rem; }
.ngxc h2 { font-size: 1.5rem; font-weight: 650; margin: 0; letter-spacing: -.01em; }
.ngxc .ngxc-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin:.25rem 0 1.25rem; }
.ngxc .ngxc-title { font-size:1.02rem; font-weight:640; margin:0 0 .35rem; }
.ngxc .ngxc-lead { color: var(--bs-secondary-color); font-size:.9rem; margin:0 0 1rem; }
.ngxc .form-text { font-size:.82rem; }
.ngxc .ngxc-bar { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin-top:1.1rem; }
.ngxc .ngxc-bar .ngxc-spacer { flex:1 1 auto; }
</style>

<div class="ngxc">

  <div class="ngxc-head">
    <h2><?php echo $e(__('nginx Cache', 'nginx-cache')); ?></h2>
    <?php if ($verified) { ?>
      <span class="badge text-bg-success"><?php echo $e(__('Purge verified', 'nginx-cache')); ?></span>
    <?php } else { ?>
      <span class="badge text-bg-secondary"><?php echo $e(__('Not verified — core windows in use', 'nginx-cache')); ?></span>
    <?php } ?>
  </div>

  <?php if (!$verified) { ?>
    <div class="alert alert-secondary" role="status">
      <?php echo $e(__('Nothing is held longer than core already holds it until a purge has been watched to work. Fill in the two settings below and press Test purge.', 'nginx-cache')); ?>
      <a href="<?php echo $e($helpUrl); ?>"><?php echo $e(__('What nginx needs', 'nginx-cache')); ?></a>
    </div>
  <?php } ?>

  <?php if (is_array($last) && isset($last['message'])) { ?>
    <div class="alert <?php echo !empty($last['ok']) ? 'alert-success' : 'alert-danger'; ?>" role="status">
      <?php echo $e((string) $last['message']); ?>
      <div class="form-text mt-1"><?php echo $e(sprintf(__('Last tested %s', 'nginx-cache'), (string) ($last['at'] ?? ''))); ?></div>
    </div>
  <?php } ?>

  <?php /* The route is in the action URL, not hidden fields: a POST body carrying its
           own page/action would be the request re-describing the route it is already on. */ ?>
  <form action="<?php echo $e($selfUrl); ?>" method="post">
    <?php echo osc_csrf_token_form(); ?>

    <div class="card">
      <div class="card-body">
        <p class="ngxc-title"><?php echo $e(__('Where purges go', 'nginx-cache')); ?></p>
        <p class="ngxc-lead">
          <?php echo $e(__('Both halves matter and neither is obvious: the request has to reach nginx from inside, and it has to name the host and scheme the page was filed under. Test purge proves the combination rather than trusting it.', 'nginx-cache')); ?>
        </p>

        <div class="mb-3">
          <label class="form-label" for="ngxc-endpoint"><?php echo $e(__('Purge endpoint', 'nginx-cache')); ?></label>
          <input class="form-control" id="ngxc-endpoint" type="text" name="purge_endpoint"
                 value="<?php echo $e((string) Plugin::get('purge_endpoint')); ?>"
                 placeholder="<?php echo $e($suggested['endpoint']); ?>" />
          <div class="form-text">
            <?php echo $e(sprintf(
                __('An address this server can open itself — never the site\'s public URL, which behind a proxy resolves to the proxy and not back here. Suggested: %s', 'nginx-cache'),
                $suggested['endpoint']
            )); ?>
          </div>
        </div>

        <div class="mb-1">
          <label class="form-label" for="ngxc-host"><?php echo $e(__('Host header', 'nginx-cache')); ?></label>
          <input class="form-control" id="ngxc-host" type="text" name="purge_host"
                 value="<?php echo $e((string) Plugin::get('purge_host')); ?>"
                 placeholder="<?php echo $e($suggested['host']); ?>" />
          <div class="form-text">
            <?php echo $e(sprintf(
                __('The host visitors send, port included — it is part of the cache key, so anything else purges nothing and reports success. Suggested: %s', 'nginx-cache'),
                $suggested['host']
            )); ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <p class="ngxc-title"><?php echo $e(__('How long pages are held', 'nginx-cache')); ?></p>
        <p class="ngxc-lead">
          <?php echo $e(sprintf(
              __('Seconds, against core\'s own thirty. Only a URL a purge can name is held longer: search results, anything with a query string, and every page on an install without permalinks keep the short window whatever is set here. Zero leaves a tier alone. The ceiling is %d — see the note below it.', 'nginx-cache'),
              Plugin::TTL_MAX
          )); ?>
        </p>

        <div class="row g-3">
          <?php foreach (array(
              'ttl_item'      => __('Listings', 'nginx-cache'),
              'ttl_page'      => __('Static pages', 'nginx-cache'),
              'ttl_aggregate' => __('Home, category, profile', 'nginx-cache'),
          ) as $key => $label) { ?>
            <div class="col-sm-4">
              <label class="form-label" for="ngxc-<?php echo $e($key); ?>"><?php echo $e($label); ?></label>
              <input class="form-control" id="ngxc-<?php echo $e($key); ?>" type="number" min="0"
                     max="<?php echo (int) Plugin::TTL_MAX; ?>" step="30" name="<?php echo $e($key); ?>"
                     value="<?php echo (int) Plugin::get($key); ?>" />
            </div>
          <?php } ?>
        </div>

        <div class="form-text mt-3">
          <?php echo $e(sprintf(
              __('%d seconds is not a round number picked for comfort. A cached page carries the security token minted when it was stored, and core stops accepting one two hours after it was issued — so past this, forms on a cached page start answering "your session has expired" to people contacting sellers. Purging keeps working perfectly while that happens, which is why it is a limit here rather than something to discover later.', 'nginx-cache'),
              Plugin::TTL_MAX
          )); ?>
        </div>
      </div>
    </div>

    <?php if ($queued > 0) { ?>
      <div class="card">
        <div class="card-body">
          <p class="ngxc-title"><?php echo $e(__('Purges waiting', 'nginx-cache')); ?></p>
          <p class="ngxc-lead mb-2">
            <?php echo $e(sprintf(
                __('%d page(s) the origin could not be told about. They are retried on every cron run; these pages are stale until then.', 'nginx-cache'),
                $queued
            )); ?>
          </p>
          <button class="btn btn-outline-secondary btn-sm" type="submit" name="nginx_cache_action" value="retry_queue">
            <?php echo $e(__('Retry now', 'nginx-cache')); ?>
          </button>
        </div>
      </div>
    <?php } ?>

    <div class="ngxc-bar">
      <button class="btn btn-primary" type="submit" name="nginx_cache_action" value="save">
        <?php echo $e(__('Save changes', 'nginx-cache')); ?>
      </button>
      <button class="btn btn-outline-primary" type="submit" name="nginx_cache_action" value="test">
        <?php echo $e(__('Test purge', 'nginx-cache')); ?>
      </button>
      <span class="ngxc-spacer"></span>
      <a class="btn btn-link btn-sm" href="<?php echo $e($helpUrl); ?>">
        <?php echo $e(__('What nginx needs', 'nginx-cache')); ?>
      </a>
    </div>
  </form>
</div>
