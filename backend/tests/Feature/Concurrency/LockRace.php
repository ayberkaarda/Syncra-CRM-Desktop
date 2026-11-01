<?php

namespace Tests\Feature\Concurrency;

use RuntimeException;

/**
 * Two writers, two PHP processes, two database connections.
 *
 * A lock-order cycle only shows up when two transactions really interleave,
 * and one process holding one connection cannot do that: the first blocking
 * statement would block the whole test. So each side runs in its own process
 * (race-worker.php) against the committed test database, and file signals line
 * their statements up the way the cycle needs:
 *
 *   - `after`:  once a statement matching the pattern has run, raise a signal;
 *   - `before`: before the first statement matching the pattern, wait for a
 *     signal (bounded by `wait_ms`, and over as soon as the other side ends).
 *
 * With the wrong order the schedule closes the cycle and the server answers one
 * side with 1213 (deadlock) or 1205 (lock wait timeout). With the right order
 * the second writer simply waits for the first at its first lock, its signal
 * never comes, the bounded wait runs out, and both commit.
 */
final class LockRace
{
    /**
     * @param  array<string, mixed>  $a  side spec: call, args, actor, after, before
     * @param  array<string, mixed>  $b
     * @return array{a: array<string, mixed>, b: array<string, mixed>}
     */
    public static function run(array $a, array $b, int $waitMs = 3000): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lock-race-'.bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $processes = [];

            foreach (['a' => $a, 'b' => $b] as $side => $spec) {
                $file = $dir.DIRECTORY_SEPARATOR.$side.'.json';
                file_put_contents($file, json_encode($spec + [
                    'side' => $side,
                    'other' => $side === 'a' ? 'b' : 'a',
                    'dir' => $dir,
                    'wait_ms' => $waitMs,
                    'after' => [],
                    'before' => [],
                    'args' => [],
                    'actor' => null,
                ], JSON_THROW_ON_ERROR));

                $processes[$side] = self::start($file);
            }

            $results = [];

            foreach ($processes as $side => [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                $line = trim((string) strrchr("\n".trim((string) $output), "\n"));
                $decoded = json_decode($line, true);

                if (! is_array($decoded)) {
                    throw new RuntimeException("Worker {$side} gave no result.\n{$output}\n{$errors}");
                }

                $results[$side] = $decoded;
            }

            return $results;
        } finally {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private static function start(string $specFile): array
    {
        $connection = config('database.connections.'.config('database.default'));

        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_COLLATION' => (string) ($connection['collation'] ?? 'utf8mb4_unicode_ci'),
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'BCRYPT_ROUNDS' => '4',
        ]);

        $process = proc_open(
            [PHP_BINARY, __DIR__.DIRECTORY_SEPARATOR.'race-worker.php', $specFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a race worker.');
        }

        fclose($pipes[0]);

        return [$process, $pipes];
    }

    /**
     * Error codes the server used to break a lock cycle, per side.
     *
     * @param  array{a: array<string, mixed>, b: array<string, mixed>}  $results
     * @return array<string, int|null>
     */
    public static function lockErrors(array $results): array
    {
        return array_map(
            fn (array $result): ?int => in_array($result['code'] ?? null, [1205, 1213], true) ? $result['code'] : null,
            $results,
        );
    }
}
