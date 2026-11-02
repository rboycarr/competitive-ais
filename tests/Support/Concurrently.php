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
 *
 * The parent never waits without a limit (TASK-0046 #1): a task still running at the deadline is killed and reported
 * as a timeout; a task that dies without reporting is reported as such. If forking fails part-way, the children
 * already started are killed and reaped before the error is raised (TASK-0046 #4).
 */
final class Concurrently
{
    public const TIMEOUT = 'timeout';

    public const NO_RESULT = 'no result';

    /**
     * @param  list<callable(): mixed>  $tasks
     * @param  float  $timeout  seconds from the common start until unfinished tasks are killed
     * @return list<array{outcome: string, message: string}> per task, in order: 'ok', the exception class, or one of
     *                                                       TIMEOUT and NO_RESULT
     */
    public static function run(array $tasks, float $timeout = 120.0): array
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
        $unreaped = []; // children not yet reaped: the only process IDs this class may still signal (TASK-0047)

        try {
            foreach ($tasks as $i => $task) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork a task process.');
                }
                if ($pid === 0) {
                    self::child($task, $start, "$dir/$i.json");
                }
                $pids[$i] = $unreaped[$i] = $pid;
            }

            return self::collect($pids, $unreaped, $dir, $start + $timeout);
        } finally {
            // Normally empty: collect() reaps every child. Left only if forking or collecting failed part-way.
            foreach ($unreaped as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    }

    /** In the child: wait for the common start, run the task, report, and end without the parent's shutdown. */
    private static function child(callable $task, float $start, string $file): never
    {
        $result = ['outcome' => 'ok', 'message' => ''];
        try {
            while (microtime(true) < $start) {
                usleep(100);
            }
            $task();
        } catch (Throwable $e) {
            $result = ['outcome' => $e::class, 'message' => $e->getMessage()];
        }
        file_put_contents($file, (string) json_encode($result));
        // No shutdown handlers or destructors: they belong to the parent (PHPUnit, the application).
        posix_kill(posix_getpid(), SIGKILL);
        exit(1); // not reached
    }

    /**
     * Reaps every child, killing those still running at the deadline. Each reaped child leaves $unreaped at once, so
     * no process ID is signalled after it was reaped (it could belong to another process by then).
     *
     * @param  array<int, int>  $pids  task index => process ID
     * @param  array<int, int>  $unreaped  task index => process ID of children not yet reaped
     * @return list<array{outcome: string, message: string}>
     */
    private static function collect(array $pids, array &$unreaped, string $dir, float $deadline): array
    {
        while ($unreaped !== [] && microtime(true) < $deadline) {
            foreach ($unreaped as $i => $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) { // ended (or not ours any more): reaped
                    unset($unreaped[$i]);
                }
            }
            usleep(10_000);
        }
        $timedOut = [];
        foreach ($unreaped as $i => $pid) { // still running at the deadline, and still our child: not yet reaped
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            unset($unreaped[$i]);
            $timedOut[$i] = true;
        }

        $results = [];
        foreach (array_keys($pids) as $i) {
            $json = isset($timedOut[$i]) ? false : @file_get_contents("$dir/$i.json");
            /** @var array{outcome: string, message: string}|null $result */
            $result = is_string($json) ? json_decode($json, true) : null;
            $results[$i] = $result ?? (isset($timedOut[$i])
                ? ['outcome' => self::TIMEOUT, 'message' => 'The task was still running at the deadline and was killed.']
                : ['outcome' => self::NO_RESULT, 'message' => 'The task process ended without reporting.']);
        }

        return array_values($results);
    }
}
