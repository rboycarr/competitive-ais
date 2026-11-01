<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Competitor-scoped access grants (P1-20a, docs/02 §3.2, PO decision 2026-11-01): a typed foreign key to the
 * competitor (never a polymorphic ID, TASK-0001 #2), RESTRICT on delete (competitors are archived, never deleted),
 * and the scope CHECK widened so the target matches the scope. Market grants follow the market registry the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->foreignId('competitor_id')->nullable()->after('scope_type')->constrained('competitors')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE access_grants
            DROP CHECK access_grants_scope_check,
            ADD CONSTRAINT access_grants_scope_check CHECK (
                (scope_type = 'all' AND competitor_id IS NULL)
                OR (scope_type = 'competitor' AND competitor_id IS NOT NULL))");
    }

    public function down(): void
    {
        // Competitor grants cannot be expressed without the column; refuse rather than silently widen them to `all`.
        if (DB::table('access_grants')->where('scope_type', 'competitor')->exists()) {
            throw new RuntimeException('Competitor-scoped access grants exist; revoke and archive them before rolling back.');
        }

        DB::statement("ALTER TABLE access_grants
            DROP CHECK access_grants_scope_check,
            ADD CONSTRAINT access_grants_scope_check CHECK (scope_type IN ('all'))");
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('competitor_id');
        });
    }
};
