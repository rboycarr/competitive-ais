<?php

namespace Modules\Platform\Audit;

use InvalidArgumentException;

/**
 * Who performed an audited action (docs/02 §3.1: actor_type user, service or system).
 */
final readonly class Actor
{
    private function __construct(
        public string $type,
        public ?int $id,
    ) {}

    public static function user(int $id): self
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A user actor needs a user ID.');
        }

        return new self('user', $id);
    }

    /** Scheduled jobs, console commands, and actions with no authenticated user. */
    public static function system(): self
    {
        return new self('system', null);
    }
}
