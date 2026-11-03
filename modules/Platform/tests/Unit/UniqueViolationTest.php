<?php

namespace Modules\Platform\Tests\Unit;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Platform\Database\UniqueViolation;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * UniqueViolation reads only the index at the end of MySQL 8.4's own duplicate-key message (TASK-0067 #1, #2): a
 * duplicate value that contains "for key '…'" cannot steer it, and any other format is not a match.
 */
class UniqueViolationTest extends TestCase
{
    public function test_it_reads_the_key_at_the_end_of_the_driver_message(): void
    {
        $e = $this->violation("Duplicate entry 'QQC' for key 'currencies.PRIMARY'");
        $this->assertTrue(UniqueViolation::on($e, 'currencies', 'PRIMARY'));
        $this->assertFalse(UniqueViolation::on($e, 'currencies', 'currencies_name_unique'));
        $this->assertFalse(UniqueViolation::on($e, 'markets', 'PRIMARY'));
    }

    public function test_a_value_cannot_imitate_the_key(): void
    {
        // The value is quoted verbatim: here it ends like an expected key, but the real key comes last.
        $e = $this->violation("Duplicate entry 'Acme for key 'tags.tags_taxonomy_name_unique'' for key 'audit_events.audit_events_ulid_unique'");
        $this->assertFalse(UniqueViolation::on($e, 'tags', 'tags_taxonomy_name_unique'));
        $this->assertTrue(UniqueViolation::on($e, 'audit_events', 'audit_events_ulid_unique'));

        $e = $this->violation("Duplicate entry 'x' for key 'tags.tags_taxonomy_name_unique' and more");
        $this->assertFalse(UniqueViolation::on($e, 'tags', 'tags_taxonomy_name_unique'));
    }

    public function test_other_formats_never_match(): void
    {
        foreach ([
            "Duplicate entry 'x' for key 'tags_taxonomy_name_unique'", // the bare index of servers before 8.0.19
            'UNIQUE constraint failed: tags.taxonomy, tags.name', // another driver
            '',
            null,
        ] as $message) {
            $this->assertFalse(UniqueViolation::on($this->violation($message), 'tags', 'tags_taxonomy_name_unique'), (string) $message);
        }
    }

    private function violation(?string $driverMessage): UniqueConstraintViolationException
    {
        $pdo = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 '.$driverMessage);
        $pdo->errorInfo = $driverMessage === null ? null : ['23000', 1062, $driverMessage];

        return new UniqueConstraintViolationException('mysql', 'insert into tags (taxonomy, name) values (?, ?)', ['segment', 'x'], $pdo);
    }
}
