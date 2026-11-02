<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Lets the suite run on a machine without a Redis server.
 *
 * If a Redis server answers on REDIS_HOST:REDIS_PORT (probed once per PHP
 * process, 0.2 s connect timeout), nothing is changed and tests use it, as
 * in CI (.github/workflows/desktop-ci.yml provides redis:7). Otherwise the
 * Redis facade is swapped for a fresh Tests\Support\InMemoryRedis per test.
 *
 * Set TESTS_REDIS_FAKE=false to disable the fallback (tests then hit the
 * configured client directly and fail without a server).
 *
 * Only the facade is replaced. Queue/cache/session are not affected: the
 * suite runs them on sync/array drivers (phpunit.xml).
 */
trait FakesRedisWhenUnavailable
{
    private static ?bool $redisServerReachable = null;

    protected function setUpFakesRedisWhenUnavailable(): void
    {
        if (filter_var(env('TESTS_REDIS_FAKE', true), FILTER_VALIDATE_BOOL) === false) {
            return;
        }

        self::$redisServerReachable ??= self::probeRedisServer(
            (string) config('database.redis.default.host', '127.0.0.1'),
            (int) config('database.redis.default.port', 6379),
        );

        if (! self::$redisServerReachable) {
            Redis::swap(new InMemoryRedis);
        }
    }

    private static function probeRedisServer(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
