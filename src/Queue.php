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
 * Sized accordingly: a bounded list, not a work pipeline. It deliberately does not copy
 * StorageQueue's worker locking, eight-step backoff or dead-letter ceiling -- one outage
 * produces a handful of URLs, and a purge older than the longest configured TTL has
 * nothing left to purge.
 */
class Queue
{
    public static function add(string $url): void
    {
        // TODO(phase 2): append to a bounded store, dropping the oldest past a cap so a
        // long outage cannot grow it without limit.
    }

    /**
     * Retried from the generic `cron` hook, not `cron_hourly`: the retry has to run more
     * often than the shortest window it protects, and the default window is an hour. On
     * the hourly tier an entry would usually expire on its own before the retry fired,
     * which would make this pointless.
     */
    public static function retry(): void
    {
        // TODO(phase 2): drain through Client::purge(), dropping entries that succeed or
        // come back 412, and discarding anything already older than the longest TTL.
    }
}
