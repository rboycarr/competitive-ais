<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `source_policies.decided_at` was created as TIMESTAMP(6) in P1-31a; the convention is DATETIME(6) in UTC, never
 * TIMESTAMP (docs/02 §1: the 2038 limit; TASK-0061 #1). The connection's time zone is UTC (config/database.php), so
 * the stored values do not change. Rows are append-only for the app account; this runs as the migrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE source_policies MODIFY decided_at DATETIME(6) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE source_policies MODIFY decided_at TIMESTAMP(6) NOT NULL');
    }
};
