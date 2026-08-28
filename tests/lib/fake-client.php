<?php
/*
 * This file is part of the nginx Cache plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\nginxcache;

/**
 * Stands in for the real transport: records what it was asked to purge and answers from
 * a script. Loaded *instead of* src/Client.php, so a test using it must not require that.
 */
class Client
{
    public const NOT_CACHED = 412;

    /** @var string[] */
    public static $calls = array();

    /** @var array<string, int> url => status to answer with */
    public static $answers = array();

    public static function isSettled(int $status): bool
    {
        return $status === 200 || $status === self::NOT_CACHED;
    }

    public static function purgeOne(string $url, ?string $endpoint = null, ?string $host = null): int
    {
        self::$calls[] = $url;

        return self::$answers[$url] ?? 0;
    }

    /** Never called from the retry path — calling it there would re-queue a failure. */
    public static function purge(array $urls): array
    {
        self::$calls[] = 'PURGE-BATCH';

        return array();
    }
}
