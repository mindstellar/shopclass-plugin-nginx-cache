<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\nginxcache;

use Category;
use Item;
use Page;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * Turns a content-change event into the set of URLs whose cached copies are now wrong,
 * and hands them to the client.
 *
 * The URL set is built from core helpers only -- nothing about a site's permalink
 * structure is assumed here. Search results are deliberately absent: they are unbounded
 * and cannot be enumerated, which is exactly why Ttl leaves them on core's short window.
 *
 * URLs are collected through the request and sent once at shutdown. A single save can
 * fire several of these hooks for the same listing, and each would otherwise be its own
 * round trip while the seller waits.
 */
class Purge
{
    /** @var array<string, true> URLs collected this request, de-duplicated by key. */
    private static $pending = array();

    /** Set by the hooks older cores fire instead of page_cache_purge; flushed once. */
    private static $purgeAllRequested = false;

    /** Core events that clear the whole cache when core itself does not signal them. */
    public const LEGACY_HOOKS = array(
        'theme_activate',
        'after_plugin_activate',
        'after_plugin_deactivate',
        'admin_form_after_save',
    );

    /**
     * The hooks that lead to a purge of everything.
     *
     * Cores with osc_purge_page_cache() fire page_cache_purge once per request for every
     * site-wide change. Older ones do not, so the plugin listens to the main ones itself;
     * doing both would purge twice.
     *
     * @return array<string, callable> hook => callback
     */
    public static function purgeAllHooks(bool $coreSignals): array
    {
        $hooks = array('page_cache_purge' => array(self::class, 'onPurgeAll'));
        if (!$coreSignals) {
            foreach (self::LEGACY_HOOKS as $hook) {
                $hooks[$hook] = array(self::class, 'requestPurgeAll');
            }
        }

        return $hooks;
    }

    /** A PURGE that reached PHP means nginx has no purge_all line; refuse it with 405. */
    public static function isStrayPurge(string $method): bool
    {
        return strtoupper($method) === 'PURGE';
    }

    /**
     * Whether core clears the whole cache itself: the Docker image 6.4.2+ with
     * OSC_MICROCACHE=1 sends one PURGE to a loopback server that has a purge_all rule.
     */
    public static function coreHandlesPurgeAll(): bool
    {
        return function_exists('osc_purge_page_cache') && (string) getenv('OSC_PAGE_CACHE_PURGE_URL') !== '';
    }

    /**
     * Purge everything, or as much as can be named when nginx will not.
     *
     * @param array $reasons why core asked; not used, every reason gets the same purge
     *
     * @return bool whether the whole cache was cleared
     */
    public static function onPurgeAll($reasons = array()): bool
    {
        if (self::coreHandlesPurgeAll()) {
            // Core purges the whole zone after this hook; per-URL purges are superseded.
            self::$pending = array();
            Queue::clear();

            return true;
        }

        $status = Client::purgeEverything();
        if (Client::purgeAllSettled($status)) {
            // Everything collected this request, and everything queued, is gone with it.
            self::$pending = array();
            Queue::clear();

            return true;
        }

        self::collect(self::nameableUrls());
        self::flush();
        Queue::add(Queue::EVERYTHING);

        return false;
    }

    /** @param mixed ...$args whatever the legacy hook passes */
    public static function requestPurgeAll(...$args): void
    {
        self::$purgeAllRequested = true;
    }

    /**
     * The pages a site-wide change touches that have a URL a purge can name: home, every
     * enabled category, every static page (not the e-mail templates) and the sitemap.
     *
     * @return string[]
     */
    public static function nameableUrls(): array
    {
        $urls = array(osc_base_url());

        foreach (Category::newInstance()->listEnabled() as $category) {
            if (!empty($category['pk_i_id'])) {
                $urls[] = osc_search_url(array('sCategory' => (int) $category['pk_i_id']));
            }
        }

        foreach (Page::newInstance()->listAll(0) as $page) {
            $urls = array_merge($urls, self::pageUrlsFor((array) $page));
        }

        if (function_exists('osc_sitemap_robots_line')
            && preg_match('~^Sitemap:\s*(\S+)~', (string) osc_sitemap_robots_line(), $m)) {
            $urls[] = $m[1];
        }

        return array_values(array_unique($urls));
    }

    /** @param array $item full item array — posted_item, edited_item */
    public static function onItemArray($item): void
    {
        if (is_array($item) && !empty($item['pk_i_id'])) {
            self::collect(self::itemUrls($item));
        }
    }

    /** @param int $itemId a bare id — the state-change hooks and invalidate_item_cache */
    public static function onItemId($itemId): void
    {
        $item = self::loadItem((int) $itemId);
        if ($item !== null) {
            self::collect(self::itemUrls($item));
        }
    }

    /**
     * @param int   $itemId
     * @param array $item    the row as it was before deletion
     */
    public static function onItemDeleted($itemId, $item = null): void
    {
        if (is_array($item) && !empty($item['pk_i_id'])) {
            self::collect(self::itemUrls($item));

            return;
        }

        // No snapshot: the row is gone, so the friendly URL -- which is built from the
        // title, the city and the category path -- can no longer be reconstructed. The
        // aggregates and the non-friendly URL still can.
        $id = (int) $itemId;
        if ($id > 0) {
            self::collect(array(osc_base_url(), osc_item_url_ns($id)));
        }
    }

    public static function onCategory($categoryId): void
    {
        $id = (int) $categoryId;
        if ($id <= 0) {
            return;
        }

        self::collect(array(osc_base_url(), osc_search_url(array('sCategory' => $id))));
    }

    public static function onPage($pageId): void
    {
        $id = (int) $pageId;
        if ($id <= 0) {
            return;
        }

        self::collect(array_merge(array(osc_base_url()), self::pageUrls($id)));
    }

    /**
     * Every URL an item is reachable at.
     *
     * Mirrors the Cloudflare plugin's list, which is the tested one: the listing itself
     * and a copy per locale where permalinks carry it, plus the nameable aggregates the
     * listing appears on. Extendable, because a theme may add item routes core knows
     * nothing about.
     *
     * @return string[]
     */
    public static function itemUrls(array $item): array
    {
        $urls   = array(osc_base_url());
        $itemId = (int) ($item['pk_i_id'] ?? 0);
        $catId  = (int) ($item['fk_i_category_id'] ?? 0);
        $userId = (int) ($item['fk_i_user_id'] ?? 0);

        if ($catId > 0) {
            $urls[] = osc_search_url(array('sCategory' => $catId));
        }
        if ($userId > 0) {
            $urls[] = osc_user_public_profile_url($userId);
        }

        if ($itemId <= 0) {
            // A row that did not load. Its aggregates are still worth purging; a URL
            // built from an id of 0 is not.
            return (array) osc_apply_filter('nginx_cache_item_urls', $urls, $item);
        }

        if (!empty($item['s_title']) && is_string($item['s_title'])) {
            $urls[] = osc_item_url_from_item($item);

            // A locale prefix is only in the permalink when there is more than one
            // locale to distinguish; with one, the prefixed URL is not a page anyone
            // requests and purging it would just be a round trip for a 412.
            $locales = self::locales();
            if (count($locales) > 1) {
                foreach ($locales as $locale) {
                    $urls[] = osc_item_url_from_item($item, $locale);
                }
            }
        } else {
            $urls[] = osc_item_url_ns($itemId);
        }

        return (array) osc_apply_filter('nginx_cache_item_urls', $urls, $item);
    }

    /**
     * A static page's own URLs, rebuilt from the row rather than from loop context.
     *
     * @return string[]
     */
    private static function pageUrls(int $id): array
    {
        $page = Page::newInstance()->findByPrimaryKey($id);

        return is_array($page) ? self::pageUrlsFor($page) : array();
    }

    /** @return string[] */
    private static function pageUrlsFor(array $page): array
    {
        $id = (int) ($page['pk_i_id'] ?? 0);
        if ($id <= 0) {
            return array();
        }

        if (!osc_rewrite_enabled()) {
            return array(osc_base_url(true) . '?page=page&id=' . $id);
        }

        $slug = urlencode((string) ($page['s_internal_name'] ?? ''));
        $tail = str_replace(
            array('{PAGE_ID}', '{PAGE_SLUG}', '{PAGE_TITLE}'),
            array((string) $id, $slug, $slug),
            (string) osc_get_preference('rewrite_page_url')
        );

        $urls    = array(osc_base_url() . $tail);
        $locales = self::locales();
        if (count($locales) > 1) {
            foreach ($locales as $locale) {
                $urls[] = osc_base_url() . $locale . '/' . $tail;
            }
        }

        return $urls;
    }

    private static function loadItem(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $item = Item::newInstance()->findByPrimaryKey($id);

        return (is_array($item) && $item !== array()) ? $item : null;
    }

    /** @return string[] enabled locale codes */
    private static function locales(): array
    {
        $out = array();
        foreach (osc_get_locales() as $locale) {
            if (!empty($locale['pk_c_code'])) {
                $out[] = (string) $locale['pk_c_code'];
            }
        }

        return $out;
    }

    /** @param string[] $urls */
    public static function collect(array $urls): void
    {
        foreach ($urls as $url) {
            if (is_string($url) && $url !== '') {
                self::$pending[$url] = true;
            }
        }
    }

    /**
     * @param array $functions shutdown callbacks core is about to register
     *
     * @return array
     */
    public static function registerFlush($functions)
    {
        $functions[] = array(self::class, 'flush');

        return $functions;
    }

    public static function flush(): void
    {
        if (self::$purgeAllRequested) {
            self::$purgeAllRequested = false;
            if (self::onPurgeAll(array('legacy'))) {
                return;
            }
        }

        if (self::$pending === array()) {
            return;
        }

        $urls = array_keys(self::$pending);
        self::$pending = array();

        // The transport is a filter so a site can send the same list somewhere else --
        // a second proxy, a different cache -- without replacing this plugin.
        $urls = (array) osc_apply_filter('nginx_cache_purge_urls', $urls);

        Client::purge($urls);
    }
}
