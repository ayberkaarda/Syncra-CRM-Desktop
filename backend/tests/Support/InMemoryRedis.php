<?php

namespace Tests\Support;

use BadMethodCallException;
use Illuminate\Support\Carbon;

/**
 * Minimal in-memory stand-in for the Redis facade, used only by the test
 * suite when no Redis server is reachable (see FakesRedisWhenUnavailable).
 *
 * It implements exactly the commands the application calls through
 * `Illuminate\Support\Facades\Redis` (today only App\Support\ImportBatch:
 * GET and SETEX), plus TTL/DEL so tests can inspect what was written.
 * Expiry follows Redis semantics: SETEX overwrites value and TTL, an expired
 * key reads as null. Time comes from Carbon, so `$this->travel()` expires keys.
 *
 * Any other command throws, so new Redis usage in app code cannot silently
 * pass against this fake: add the command here (with real semantics) or run
 * the suite against a real Redis server.
 */
class InMemoryRedis
{
    /** @var array<string, array{value: string, expires_at: int|null}> */
    private array $data = [];

    public function get(string $key): ?string
    {
        return $this->live($key)['value'] ?? null;
    }

    public function setex(string $key, int $seconds, mixed $value): bool
    {
        if ($seconds <= 0) {
            // Real Redis rejects this ("invalid expire time"); fail loudly.
            throw new \InvalidArgumentException("SETEX {$key}: invalid expire time {$seconds}");
        }

        $this->data[$key] = [
            'value' => (string) $value,
            'expires_at' => Carbon::now()->getTimestamp() + $seconds,
        ];

        return true;
    }

    /** Seconds left, -1 without expiry, -2 when the key does not exist. */
    public function ttl(string $key): int
    {
        $entry = $this->live($key);

        if ($entry === null) {
            return -2;
        }

        return $entry['expires_at'] === null
            ? -1
            : $entry['expires_at'] - Carbon::now()->getTimestamp();
    }

    public function del(string ...$keys): int
    {
        $deleted = 0;

        foreach ($keys as $key) {
            if ($this->live($key) !== null) {
                unset($this->data[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Mirrors RedisManager::connection() so `Redis::connection()->get()` also works. */
    public function connection(?string $name = null): static
    {
        return $this;
    }

    /**
     * Return type is `mixed`, not `never`: tests may still call
     * `Redis::shouldReceive()`, and Mockery cannot mock a `never` method.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        throw new BadMethodCallException(
            "Redis command [{$method}] is not implemented by the test fake ".
            'Tests\\Support\\InMemoryRedis. Implement it there or run the suite with a Redis server.'
        );
    }

    /**
     * @return array{value: string, expires_at: int|null}|null
     */
    private function live(string $key): ?array
    {
        $entry = $this->data[$key] ?? null;

        if ($entry !== null && $entry['expires_at'] !== null
            && $entry['expires_at'] <= Carbon::now()->getTimestamp()) {
            unset($this->data[$key]);

            return null;
        }

        return $entry;
    }
}
