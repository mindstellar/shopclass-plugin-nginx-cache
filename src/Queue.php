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
 * Purges the origin could not be told about, kept for the hourly retry.
 *
 * Without this a restart or a momentary refusal leaves a page wrong until its window
 * expires -- an hour, not the thirty seconds it would have been. Dropping a failed purge
 * is the one thing that makes the longer window worse than no plugin at all.
 */
class Queue
{
    public static function add(string $url): void
    {
        // TODO(phase 2): append to a bounded store. A preference is enough -- the queue
        // should only ever hold what one outage produced, and anything older than the
        // longest TTL is pointless to send.
    }

    public static function retry(): void
    {
        // TODO(phase 2): drain through Client::purge(), dropping entries that succeed or
        // come back 412, and give up on anything past the longest configured TTL.
    }
}
