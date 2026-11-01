<?php

namespace Modules\Identity\Contracts;

/** What other modules may know about a user: no email, credentials, roles or clearance. */
final readonly class UserSummary
{
    public function __construct(
        public int $id,
        public string $ulid,
        public string $name,
        public bool $active,
    ) {}
}
