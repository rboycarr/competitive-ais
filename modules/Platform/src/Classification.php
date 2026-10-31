<?php

namespace Modules\Platform;

/**
 * Information classification, ordered public < internal < confidential < restricted (docs/03 Platform, skills/06).
 */
enum Classification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Restricted = 'restricted';

    public function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Internal => 1,
            self::Confidential => 2,
            self::Restricted => 3,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
