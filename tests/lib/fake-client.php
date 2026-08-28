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

    /** @var array<string, int> url => status, or "host|url" => status to answer per host */
    public static $answers = array();

    /** @var array<int, array{target:string, host:string}> every probe, in order */
    public static $probeCalls = array();

    /** @var array<int, array{status:int, cache:string}> answers to hand back, in order */
    public static $probes = array();

    /** @var string[] the Host each purge presented */
    public static $purgeHosts = array();

    public static function isSettled(int $status): bool
    {
        return $status === 200 || $status === self::NOT_CACHED;
    }

    public static function purgeOne(string $url, ?string $endpoint = null, ?string $host = null): int
    {
        self::$calls[]      = $url;
        self::$purgeHosts[] = (string) $host;

        return self::$answers[$host . '|' . $url] ?? self::$answers[$url] ?? 0;
    }

    /** @return array{status:int, cache:string} */
    public static function probe(string $target, string $host): array
    {
        self::$probeCalls[] = array('target' => $target, 'host' => $host);

        return array_shift(self::$probes) ?: array('status' => 0, 'cache' => '');
    }

    public static function reset(): void
    {
        self::$calls = self::$answers = self::$probeCalls = self::$probes = self::$purgeHosts = array();
    }

    /** @return array<string, int> host => status, the same shape the real client returns */
    public static function purgeAll(string $url): array
    {
        $statuses = array();
        foreach (Plugin::purgeHosts() as $host) {
            $statuses[$host] = self::purgeOne($url, null, $host);
        }

        return $statuses;
    }

    /** @param array<string, int> $statuses */
    public static function allSettled(array $statuses): bool
    {
        foreach ($statuses as $status) {
            if (!self::isSettled($status)) {
                return false;
            }
        }

        return $statuses !== array();
    }

    /** Never called from the retry path — calling it there would re-queue a failure. */
    public static function purge(array $urls): array
    {
        self::$calls[] = 'PURGE-BATCH';

        return array();
    }
}
