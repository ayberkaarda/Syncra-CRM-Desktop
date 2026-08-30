<?php

namespace Tests\Unit\Automation;

use App\Services\Automation\AutomationExecutionGuard;
use PHPUnit\Framework\TestCase;

/**
 * Faz 14 / İz F — `AutomationExecutionGuard`'ın çıplak sözleşmesi: iç içe (re-entrant)
 * bir `run()` çağrısı NO-OP'tur, dış çağrı normal çalışır ve bayrak her koşulda (istisna
 * dahil) `finally` ile geri iner.
 */
class AutomationExecutionGuardTest extends TestCase
{
    public function test_nested_run_calls_are_suppressed(): void
    {
        $outerCalls = 0;
        $innerCalls = 0;

        AutomationExecutionGuard::run(function () use (&$outerCalls, &$innerCalls) {
            $outerCalls++;
            $this->assertTrue(AutomationExecutionGuard::isRunning());

            AutomationExecutionGuard::run(function () use (&$innerCalls) {
                $innerCalls++;
            });
        });

        $this->assertSame(1, $outerCalls);
        $this->assertSame(0, $innerCalls, 'İç içe çağrı çalıştırılmamalıydı.');
        $this->assertFalse(AutomationExecutionGuard::isRunning());
    }

    public function test_run_executes_normally_when_not_nested(): void
    {
        $calls = 0;

        AutomationExecutionGuard::run(function () use (&$calls) {
            $calls++;
        });
        AutomationExecutionGuard::run(function () use (&$calls) {
            $calls++;
        });

        $this->assertSame(2, $calls);
    }

    public function test_guard_is_released_even_when_the_callback_throws(): void
    {
        try {
            AutomationExecutionGuard::run(function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('İstisna fırlatılmalıydı.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertFalse(AutomationExecutionGuard::isRunning());

        // Bayrak GERÇEKTEN iniyor mu — bir sonraki run() normal çalışmalı.
        $calls = 0;
        AutomationExecutionGuard::run(function () use (&$calls) {
            $calls++;
        });
        $this->assertSame(1, $calls);
    }
}
