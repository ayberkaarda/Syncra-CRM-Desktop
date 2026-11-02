<?php

namespace Tests\Unit;

use App\Support\ImportBatch;
use BadMethodCallException;
use Illuminate\Support\Facades\Redis;
use Tests\Support\InMemoryRedis;
use Tests\TestCase;

/**
 * Pins the semantics of the test-only Redis fake so tests that run against it
 * (when no Redis server is reachable) still mean what they say.
 */
class InMemoryRedisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Always test the fake itself, even when a real server is reachable.
        Redis::swap(new InMemoryRedis);
    }

    public function test_setex_value_expires_after_its_ttl(): void
    {
        Redis::setex('k', 10, 'v');

        $this->assertSame('v', Redis::get('k'));
        $this->assertSame(10, Redis::ttl('k'));

        $this->travel(9)->seconds();
        $this->assertSame('v', Redis::get('k'));
        $this->assertSame(1, Redis::ttl('k'));

        $this->travel(1)->seconds();
        $this->assertNull(Redis::get('k'));
        $this->assertSame(-2, Redis::ttl('k'));
    }

    public function test_setex_overwrites_value_and_resets_ttl(): void
    {
        Redis::setex('k', 5, 'old');
        $this->travel(4)->seconds();
        Redis::setex('k', 5, 'new');
        $this->travel(4)->seconds();

        $this->assertSame('new', Redis::get('k'));
    }

    public function test_non_positive_ttl_is_rejected_like_redis(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Redis::setex('k', 0, 'v');
    }

    public function test_unimplemented_commands_fail_loudly(): void
    {
        $this->expectException(BadMethodCallException::class);

        Redis::hset('h', 'f', 'v');
    }

    public function test_import_batch_round_trips_and_expires_with_its_ttl(): void
    {
        ImportBatch::start('batch-ttl', 1, 3);

        $this->assertNotNull(ImportBatch::find('batch-ttl'));

        $this->travel(ImportBatch::TTL_SECONDS)->seconds();
        $this->assertNull(ImportBatch::find('batch-ttl'));
    }
}
