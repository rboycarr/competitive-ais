<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access grants (P1-14, docs/02 §3.2): what a user or team may see beyond their roles, up to a classification, until
 * an optional expiry. Revoked, never deleted (the app account has no DELETE on this table, config/database.php).
 *
 * Only scope `all` exists until the registry adds competitors and markets (Sprint 1.2, PO decision 2026-11-01): that
 * story adds `competitor_id` and `market_id` with their foreign keys and widens the scope CHECK, so no unchecked
 * target ID is ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('scope_type', 16);
            $table->string('max_classification', 12);
            $table->boolean('can_export')->default(false);
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['team_id', 'revoked_at']);
        });

        DB::statement("ALTER TABLE access_grants
            ADD CONSTRAINT access_grants_grantee_check CHECK ((user_id IS NULL) <> (team_id IS NULL)),
            ADD CONSTRAINT access_grants_scope_check CHECK (scope_type IN ('all')),
            ADD CONSTRAINT access_grants_classification_check CHECK (max_classification IN ('public', 'internal', 'confidential', 'restricted')),
            ADD CONSTRAINT access_grants_revocation_check CHECK ((revoked_at IS NULL) = (revoked_by IS NULL)),
            ADD CONSTRAINT access_grants_reason_check CHECK (CHAR_LENGTH(TRIM(reason)) >= 3)");
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
