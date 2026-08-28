<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\nginxcache;

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
        // TODO(phase 2): load the item and delegate to onItemArray(). A deleted listing
        // will not load; that path is onItemDeleted().
    }

    /**
     * @param int   $itemId
     * @param array $item    the row as it was before deletion
     */
    public static function onItemDeleted($itemId, $item = null): void
    {
        // TODO(phase 2): the item is gone, so its URLs must come from the snapshot the
        // hook carries rather than a lookup.
    }

    public static function onCategory($categoryId): void
    {
        // TODO(phase 2)
    }

    public static function onPage($pageId): void
    {
        // TODO(phase 2)
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
        $urls = array();

        // TODO(phase 2): canonical URL + locale variants + homepage, category and
        // seller profile, with osc_item_url_ns() as the fallback when the title is
        // missing. `?comments-page=N` variants are not enumerable and stay uncovered.

        return (array) osc_apply_filter('nginx_cache_item_urls', $urls, $item);
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
