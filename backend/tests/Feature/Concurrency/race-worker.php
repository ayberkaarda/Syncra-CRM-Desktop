<?php

/*
 * One side of a LockRace: boots the application in this process, on this
 * process's own database connection, runs one writer and prints its outcome as
 * a single JSON line. See LockRace for the signal protocol.
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$spec = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$signal = fn (string $name): string => $spec['dir'].DIRECTORY_SEPARATOR.$name.'.signal';
$done = fn (string $side): string => $spec['dir'].DIRECTORY_SEPARATOR.$side.'.done';

$wait = function (string $name) use ($spec, $signal, $done): void {
    $deadline = microtime(true) + $spec['wait_ms'] / 1000;

    while (! file_exists($signal($name)) && ! file_exists($done($spec['other'])) && microtime(true) < $deadline) {
        usleep(5000);
    }
};

$used = [];

DB::beforeExecuting(function (string $sql) use ($spec, $wait, &$used): void {
    foreach ($spec['before'] as $pattern => $name) {
        if (! isset($used['before'.$pattern]) && preg_match($pattern, $sql)) {
            $used['before'.$pattern] = true;
            $wait($name);
        }
    }
});

DB::listen(function (QueryExecuted $query) use ($spec, $signal, &$used): void {
    foreach ($spec['after'] as $pattern => $name) {
        if (! isset($used['after'.$pattern]) && preg_match($pattern, $query->sql)) {
            $used['after'.$pattern] = true;
            touch($signal($name));
        }
    }
});

$outcome = ['ok' => true, 'code' => null, 'error' => null, 'result' => null];

try {
    if ($spec['actor'] !== null) {
        Auth::setUser(User::query()->findOrFail($spec['actor']));
    }

    $outcome['result'] = call_user_func_array($spec['call'], $spec['args']);
} catch (Throwable $e) {
    // A deadlock inside a nested transaction arrives as DeadlockException,
    // with the query exception that carries the server's code behind it.
    $code = null;

    for ($cause = $e; $cause !== null && $code === null; $cause = $cause->getPrevious()) {
        if ($cause instanceof QueryException || $cause instanceof PDOException) {
            $code = isset($cause->errorInfo[1]) ? (int) $cause->errorInfo[1] : null;
        }
    }

    $outcome = ['ok' => false, 'code' => $code, 'error' => $e::class.': '.$e->getMessage(), 'result' => null];
} finally {
    touch($done($spec['side']));
}

echo "\n".json_encode($outcome)."\n";
