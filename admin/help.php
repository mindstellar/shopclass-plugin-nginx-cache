<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Render-only. Everything printed here is generated for THIS install — its host, its
 * scheme, its nginx version — rather than a sample to adapt, because every part of this
 * configuration fails quietly when it is subtly wrong.
 */

use mindstellar\nginxcache\Setup;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$e         = 'osc_esc_html';
$suggested = Setup::suggested();
$version   = Setup::nginxVersion();

/** One numbered step: a heading, a sentence, and the block to copy. */
$step = static function ($n, $title, $lead, $code) use ($e) {
    ?>
    <div class="card">
      <div class="card-body">
        <p class="ngxc-title"><span class="ngxc-n"><?php echo (int) $n; ?></span> <?php echo $e($title); ?></p>
        <p class="ngxc-lead"><?php echo $e($lead); ?></p>
        <div class="ngxc-code">
          <button type="button" class="btn btn-sm btn-outline-secondary ngxc-copy"><?php echo $e(__('Copy', 'nginx-cache')); ?></button>
          <pre><code><?php echo $e($code); ?></code></pre>
        </div>
      </div>
    </div>
    <?php
};
?>
<style>
.ngxc { max-width: 880px; }
.ngxc .card { border-color: var(--bs-border-color); }
.ngxc .card + .card { margin-top: 1rem; }
.ngxc h2 { font-size: 1.5rem; font-weight: 650; margin: 0 0 .35rem; letter-spacing: -.01em; }
.ngxc .ngxc-title { font-size:1.02rem; font-weight:640; margin:0 0 .35rem; display:flex; align-items:center; gap:.5rem; }
.ngxc .ngxc-n { display:inline-flex; align-items:center; justify-content:center; width:1.6rem; height:1.6rem; border-radius:50%; background: var(--bs-tertiary-bg); color: var(--bs-secondary-color); font-size:.82rem; font-weight:700; }
.ngxc .ngxc-lead { color: var(--bs-secondary-color); font-size:.9rem; margin:0 0 .85rem; }
.ngxc .ngxc-code { position:relative; }
.ngxc .ngxc-code pre { background: var(--bs-tertiary-bg); border:1px solid var(--bs-border-color); border-radius: var(--bs-border-radius); padding:.85rem 5rem .85rem 1rem; margin:0; overflow-x:auto; }
/* The admin theme styles inline <code> with its own background and padding; inside a
   <pre> that paints a ragged box per line instead of one block. */
.ngxc .ngxc-code code { display:block; background:none; padding:0; font-size:.82rem; line-height:1.6; color: var(--bs-body-color); white-space:pre; }
.ngxc .ngxc-copy { position:absolute; top:.5rem; right:.5rem; opacity:.75; }
.ngxc .ngxc-copy:hover { opacity:1; }
.ngxc dl { margin:0; }
.ngxc dt { font-weight:600; }
.ngxc dd { margin:0 0 .6rem; color: var(--bs-secondary-color); font-size:.9rem; }
.ngxc code.ngxc-v { color: var(--bs-body-color); background: var(--bs-tertiary-bg); padding:.1rem .35rem; border-radius:.25rem; }
</style>

<div class="ngxc">

  <h2><?php echo $e(__('What nginx needs', 'nginx-cache')); ?></h2>
  <p class="ngxc-lead">
    <?php echo $e(sprintf(
        __('Five steps, generated for this install (nginx %s). The bundled Shopclass Docker image ships all of it already; a standalone install adds it by hand. Nothing below changes what a page is filed under — the cache key stays exactly the one core\'s reference config uses.', 'nginx-cache'),
        $version
    )); ?>
  </p>

  <?php $step(
      1,
      __('Build nginx with the purge module', 'nginx-cache'),
      __('The official image does not carry it. It builds as a dynamic module against stock nginx in a two-stage image — one .so, not a switch to OpenResty.', 'nginx-cache'),
      Setup::dockerfile()
  ); ?>

  <?php $step(
      2,
      __('Load it', 'nginx-cache'),
      __('In the main context, above "http {". A conf.d file is already inside http and is too late. If this line is missing, nginx refuses to start on the next step rather than failing quietly — which is the one failure here you cannot miss.', 'nginx-cache'),
      Setup::loadModule()
  ); ?>

  <?php $step(
      3,
      __('Give it somewhere to cache', 'nginx-cache'),
      __('http{} scope. The zone is core\'s own; the only change from the reference config is that entries may idle for a day, since a page held for an hour must not be evicted for going quiet after sixty seconds.', 'nginx-cache'),
      Setup::httpScope()
  ); ?>

  <?php $step(
      4,
      __('Add the purge location', 'nginx-cache'),
      __('server{} scope. $1 is the path being purged and $is_args$args is its query, so the key built here is the same one step 5 stored the page under. The allow list is the whole of the access control: a purge takes no credentials, so this must not be reachable from outside.', 'nginx-cache'),
      Setup::purgeLocation()
  ); ?>

  <?php $step(
      5,
      __('Turn caching on for PHP', 'nginx-cache'),
      __('Inside the existing "location ~ \\.php$", alongside its fastcgi_pass. X-Cache is not decoration: Test purge reads it to tell "cached, then purged" from "never cached", and refuses to lengthen anything without it.', 'nginx-cache'),
      Setup::phpScope()
  ); ?>

  <div class="card">
    <div class="card-body">
      <p class="ngxc-title"><span class="ngxc-n">6</span> <?php echo $e(__('Then fill in the settings', 'nginx-cache')); ?></p>
      <p class="ngxc-lead"><?php echo $e(__('For this install these are the values, and Test purge will confirm them:', 'nginx-cache')); ?></p>
      <dl>
        <dt><?php echo $e(__('Purge endpoint', 'nginx-cache')); ?></dt>
        <dd>
          <code class="ngxc-v"><?php echo $e($suggested['endpoint']); ?></code><br />
          <?php echo $e(__('Where PHP and nginx are separate containers, this is the nginx service name instead of the loopback — for example http://webserver/purge.', 'nginx-cache')); ?>
        </dd>
        <dt><?php echo $e(__('Host header', 'nginx-cache')); ?></dt>
        <dd><code class="ngxc-v"><?php echo $e($suggested['host']); ?></code></dd>
      </dl>
      <a class="btn btn-primary btn-sm" href="<?php echo $e(osc_route_admin_url('nginx-cache-settings')); ?>">
        <?php echo $e(__('Go to settings', 'nginx-cache')); ?>
      </a>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <p class="ngxc-title"><?php echo $e(__('Why this is checked rather than trusted', 'nginx-cache')); ?></p>
      <p class="ngxc-lead mb-0">
        <?php echo $e(__('A purge that does not work fails the same way a purge with nothing to do does: HTTP 412, no error anywhere. Get the host, the port or the scheme wrong and every page goes on being served stale for the whole window — an hour instead of the thirty seconds it would have been without this plugin. That is why nothing is held longer until one round trip has been watched from end to end, and why changing either setting closes the gate again.', 'nginx-cache')); ?>
      </p>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.ngxc-copy').forEach(function (button) {
    button.addEventListener('click', function () {
        var code = button.parentElement.querySelector('code');
        navigator.clipboard.writeText(code.textContent).then(function () {
            var was = button.textContent;
            button.textContent = <?php echo json_encode(__('Copied', 'nginx-cache')); ?>;
            setTimeout(function () { button.textContent = was; }, 1500);
        });
    });
});
</script>
