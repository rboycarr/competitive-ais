<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Currencies become editable by taxonomy managers (P1-23b, PO decision O29): grant class no_delete instead of
 * read_only, and the registry name normal form for their names, as for the other registry tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE currencies
                ADD CONSTRAINT currencies_name_check CHECK (CHAR_LENGTH(name) > 0 AND name = TRIM(name)
                    AND name NOT LIKE '%  %' AND NOT REGEXP_LIKE(REPLACE(name, ' ', ''), '[\\s\\p{Z}\\p{Cc}]'))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE currencies DROP CHECK currencies_name_check');
    }
};
