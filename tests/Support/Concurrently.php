<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs tasks at the same time in separate processes (P1-25), so registry rules are tested against real parallel
 * transactions rather than one connection. Each task gets its own database connections: the parent closes its own
 * before forking, so no socket is shared (the test environment keeps cache, session and queue in memory). All tasks
 * start at the same instant; each reports "ok" or the exception it ended with. The data a task needs must be
 * committed (DatabaseMigrations, not RefreshDatabase).
 */
final class Concurrently
{
    /**
     * @param  list<callable(): mixed>  $tasks
     * @return list<array{outcome: string, message: string}> per task, in order: 'ok' or the exception class
     */
    public static function run(array $tasks): array
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('Parallel tests need the pcntl extension.');
        }
        foreach (array_keys((array) config('database.connections')) as $connection) {
            DB::disconnect((string) $connection);
        }

        $dir = sys_get_temp_dir().'/cais-concurrently-'.bin2hex(random_bytes(6));
        if (! mkdir($dir, 0700)) {
            throw new RuntimeException("Cannot create $dir.");
        }
        $start = microtime(true) + 0.5; // every child is forked well before this
        $pids = [];
        foreach ($tasks as $i => $task) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork a task process.');
            }
            if ($pid === 0) {
                $result = ['outcome' => 'ok', 'message' => ''];
                try {
                    while (microtime(true) < $start) {
                        usleep(100);
                    }
                    $task();
                } catch (Throwable $e) {
                    $result = ['outcome' => $e::class, 'message' => $e->getMessage()];
                }
                file_put_contents("$dir/$i.json", (string) json_encode($result));
                // No shutdown handlers or destructors: they belong to the parent (PHPUnit, the application).
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[$i] = $pid;
        }

        $results = [];
        foreach ($pids as $i => $pid) {
            pcntl_waitpid($pid, $status);
            $json = @file_get_contents("$dir/$i.json");
            /** @var array{outcome: string, message: string}|null $result */
            $result = is_string($json) ? json_decode($json, true) : null;
            $results[$i] = $result ?? ['outcome' => 'no result', 'message' => 'The task process ended without reporting.'];
            @unlink("$dir/$i.json");
        }
        @rmdir($dir);

        return array_values($results);
    }
}
