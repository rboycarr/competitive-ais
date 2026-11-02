<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Market-scoped access grants (P1-23c, docs/02 §3.2, the same way as competitor grants in P1-20a): a typed foreign key
 * to the market (never a polymorphic ID, TASK-0001 #2), RESTRICT on delete (markets are never deleted), and the scope
 * CHECK widened so a grant names exactly the target its scope needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->foreignId('market_id')->nullable()->after('competitor_id')->constrained('markets')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE access_grants
            DROP CHECK access_grants_scope_check,
            ADD CONSTRAINT access_grants_scope_check CHECK (
                (scope_type = 'all' AND competitor_id IS NULL AND market_id IS NULL)
                OR (scope_type = 'competitor' AND competitor_id IS NOT NULL AND market_id IS NULL)
                OR (scope_type = 'market' AND market_id IS NOT NULL AND competitor_id IS NULL))");
    }

    public function down(): void
    {
        // Market grants cannot be expressed without the column; refuse rather than silently widen them to `all`.
        if (DB::table('access_grants')->where('scope_type', 'market')->exists()) {
            throw new RuntimeException('Market-scoped access grants exist; revoke and archive them before rolling back.');
        }

        DB::statement("ALTER TABLE access_grants
            DROP CHECK access_grants_scope_check,
            ADD CONSTRAINT access_grants_scope_check CHECK (
                (scope_type = 'all' AND competitor_id IS NULL)
                OR (scope_type = 'competitor' AND competitor_id IS NOT NULL))");
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('market_id');
        });
    }
};
