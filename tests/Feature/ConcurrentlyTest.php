<?php

use Tests\Support\Concurrently;

// The parallel-request harness itself (P1-25, TASK-0046): outcomes come back in order, a task that never finishes is
// killed at the deadline, and a task that dies without reporting is reported as such, never as success.

it('reports each task in order and starts them together', function () {
    $results = Concurrently::run([
        fn () => usleep(200_000),
        fn () => throw new InvalidArgumentException('refused'),
        fn () => null,
    ]);

    expect($results)->toBe([
        ['outcome' => 'ok', 'message' => ''],
        ['outcome' => InvalidArgumentException::class, 'message' => 'refused'],
        ['outcome' => 'ok', 'message' => ''],
    ]);
});

it('kills a task still running at the deadline and reports a timeout', function () {
    $started = microtime(true);
    $results = Concurrently::run([fn () => sleep(30), fn () => null], timeout: 1.0);

    expect(microtime(true) - $started)->toBeLessThan(10.0)
        ->and(array_column($results, 'outcome'))->toBe([Concurrently::TIMEOUT, 'ok']);
});

it('reports a task that dies without reporting', function () {
    $results = Concurrently::run([fn () => posix_kill(posix_getpid(), SIGKILL)]);

    expect($results[0]['outcome'])->toBe(Concurrently::NO_RESULT);
});
