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
 * Purges the origin could not be told about. **A fallback, not the delivery path.**
 *
 * Purging is immediate: Purge::flush() sends every URL in the same request that changed
 * the content, so a listing edit is visible on the next request rather than the next cron
 * tick. Nothing about correctness runs through here.
 *
 * This exists only for what that attempt could not deliver -- the origin down, a reload
 * mid-request, a refused connection -- because dropping those would leave a page wrong for
 * a whole hour rather than the thirty seconds it would have been without the plugin.
 *
 * Sized accordingly: a bounded list in one preference row, not a work pipeline. It
 * deliberately does not copy StorageQueue's worker locking, eight-step backoff or
 * dead-letter ceiling -- one outage produces a handful of URLs, and a purge older than the
 * longest configured TTL has nothing left to purge.
 */
class Queue
{
    /** Enough for any plausible outage. Past it the oldest entry is dropped. */
    public const CAP = 100;

    public const KEY = 'queue';

    /**
     * Two requests failing at the same moment can overwrite each other's entry here.
     * That is accepted rather than locked around: the loss is one retry of one URL whose
     * page expires on its own within the hour, and the alternative is a lock on the write
     * path of every listing save to protect a case that only arises while the origin is
     * already refusing connections.
     */
    public static function add(string $url): void
    {
        if ($url === '') {
            return;
        }

        $entries = self::load();

        // Keep the first failure's timestamp: age is measured from when the page went
        // wrong, not from the last attempt to fix it.
        if (!isset($entries[$url])) {
            $entries[$url] = time();
        }

        if (count($entries) > self::CAP) {
            $entries = array_slice($entries, -self::CAP, null, true);
        }

        self::save($entries);
    }

    /**
     * Retried from the generic `cron` hook, not `cron_hourly`: the retry has to run more
     * often than the shortest window it protects, and the default window is an hour. On
     * the hourly tier an entry would usually expire on its own before the retry fired,
     * which would make this pointless.
     */
    public static function retry(): void
    {
        $entries = self::load();
        if ($entries === array()) {
            return;
        }

        $cutoff = time() - self::maxAge();
        $keep   = array();

        foreach ($entries as $url => $failedAt) {
            // Older than the longest window the plugin hands out: whatever was cached
            // when this failed has expired on its own, so there is nothing to purge.
            if ($failedAt < $cutoff) {
                continue;
            }

            // purgeAll, not purge: this is the retry path, and Client::purge would put a
            // still-failing URL straight back into the queue it just came out of. Every
            // host is re-sent; one already purged answers 412, which counts as done.
            if (Client::allSettled(Client::purgeAll($url))) {
                continue;
            }

            $keep[$url] = $failedAt;
        }

        self::save($keep);
    }

    /** @return array<string, int> url => unix time of the first failure */
    public static function load(): array
    {
        $raw = (string) Plugin::get(self::KEY);
        if ($raw === '') {
            return array();
        }

        $entries = json_decode($raw, true);
        if (!is_array($entries)) {
            return array();
        }

        $out = array();
        foreach ($entries as $url => $failedAt) {
            if (is_string($url) && $url !== '' && is_numeric($failedAt)) {
                $out[$url] = (int) $failedAt;
            }
        }

        return $out;
    }

    /** @param array<string, int> $entries */
    private static function save(array $entries): void
    {
        osc_set_preference(self::KEY, $entries === array() ? '' : (string) json_encode($entries), Plugin::SECTION);
    }

    /** The longest window the plugin will hand out; past it an entry has expired anyway. */
    private static function maxAge(): int
    {
        return max(
            (int) Plugin::get('ttl_item'),
            (int) Plugin::get('ttl_page'),
            (int) Plugin::get('ttl_aggregate'),
            60
        );
    }
}
