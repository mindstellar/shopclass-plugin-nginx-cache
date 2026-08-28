<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\nginxcache;

use Params;

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

        // Zero is whoever ran before this saying the page must not be held at all. This
        // filter lengthens a window; it does not open one that was closed.
        if ($ttl <= 0) {
            return $ttl;
        }

        if (!osc_get_bool_preference('verified', Plugin::SECTION)) {
            return $ttl;
        }

        if (!self::isCanonicalUrl()) {
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

    /**
     * True when the URL being served is the one a purge will name.
     *
     * The page type is not enough on its own. `/?utm_source=newsletter` is the home page
     * by every helper core has, and `?comments-page=2` is the listing -- but each is its
     * own cache entry, and Purge names neither. Holding those for an hour would be
     * holding pages nothing can clear.
     *
     * With permalinks off every page is a query URL, including the canonical ones, so
     * nothing is lengthened at all there. That is deliberate: the alternative is matching
     * a query string against a rebuilt canonical one, parameter order included, and
     * getting that subtly wrong is a stale page nobody can explain.
     */
    private static function isCanonicalUrl(): bool
    {
        return ($_SERVER['QUERY_STRING'] ?? '') === '' && osc_rewrite_enabled();
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
     *
     * Stricter than core's own osc_is_search_category_page(), which also counts a page
     * number and a sort order as "the category page". Those are separate cache entries
     * and Purge names neither, so they stay on the short window: this returns true only
     * for the one URL a purge will actually reach.
     */
    private static function isPlainCategory(): bool
    {
        if (!osc_is_search_page()) {
            return false;
        }

        $params = Params::getParamsAsArray();
        unset($params['page']);

        return count($params) === 1
            && isset($params['sCategory'])
            && is_string($params['sCategory'])
            && $params['sCategory'] !== ''
            && strpos($params['sCategory'], ',') === false;
    }
}
