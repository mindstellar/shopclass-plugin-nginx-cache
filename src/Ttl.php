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
 * How long the shared cache may hold the page being rendered.
 *
 * Filters core's `public_cache_max_age`. Two rules decide the answer:
 *
 * 1. Nothing is lengthened until the purge self-test has passed. A long window with a
 *    purge that does not work is strictly worse than the short window it replaced --
 *    the failure is invisible and lasts an hour instead of thirty seconds.
 * 2. A page may only be held longer than core's default if the set of URLs holding it
 *    can be named, because that is what a purge needs. An item, a static page, the
 *    homepage, a category and a seller's profile can all be named. A search with
 *    parameters cannot: every keyword, filter, sort and page number is its own entry,
 *    and a newly posted listing has to show up in those. Search keeps core's default.
 */
class Ttl
{
    /**
     * @param int $ttl core's default, in seconds
     *
     * @return int
     */
    public static function forCurrentPage($ttl): int
    {
        $ttl = (int) $ttl;

        if (!osc_get_bool_preference('verified', Plugin::SECTION)) {
            return $ttl;
        }

        if (osc_is_ad_page()) {
            return self::pref('ttl_item', $ttl);
        }
        if (osc_is_static_page()) {
            return self::pref('ttl_page', $ttl);
        }
        // Home, a plain category browse, and a public profile are nameable and so are
        // purged by Purge::itemUrls(); a search carrying its own parameters is not.
        if (osc_is_home_page() || self::isPlainCategory() || osc_is_public_profile()) {
            return self::pref('ttl_aggregate', $ttl);
        }

        return $ttl;
    }

    private static function pref(string $key, int $fallback): int
    {
        $value = (int) Plugin::get($key);

        // Never shorten. This filter exists to lengthen; a smaller configured value
        // than core's own default is a misconfiguration, not an instruction.
        return $value > $fallback ? $value : $fallback;
    }

    /**
     * A category browse with no search terms on it -- the URL is derived from the
     * category alone, so it can be named and purged.
     */
    private static function isPlainCategory(): bool
    {
        // TODO(phase 2): true when this is a search page whose only parameter is the
        // category, false as soon as a keyword, price bound, sort or region is present.
        return false;
    }
}
