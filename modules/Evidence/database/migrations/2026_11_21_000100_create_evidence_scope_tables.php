<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence scopes (P1-36a, docs/02 §3.5, docs/07 §3, TASK-0001 #1): the competitors and markets an asset is about.
 * AccessScope reads them with all-of semantics: a viewer needs grants covering every competitor and every market on the
 * asset. Grant class `append_only` (`config/database.php`): removing a scope would widen who sees the evidence, so the
 * app account may only add one. `origin` is `declared` (at upload, now) or `inherited` (when the asset is linked to a
 * claim, P1-36b with P1-41). FK CASCADE on the asset side (assets are never deleted by the app), RESTRICT on the
 * subject side (a competitor or market with evidence about it cannot be deleted). Times are DATETIME(6) in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['evidence_asset_competitor' => ['competitor_id', 'competitors'], 'evidence_asset_market' => ['market_id', 'markets']] as $table => [$column, $subjects]) {
            Schema::create($table, function (Blueprint $t) use ($column, $subjects) {
                $t->foreignId('evidence_asset_id')->constrained('evidence_assets')->cascadeOnDelete();
                $t->foreignId($column)->constrained($subjects)->restrictOnDelete();
                $t->string('origin', 10);
                $t->foreignId('added_by')->constrained('users')->restrictOnDelete();
                $t->dateTime('created_at', 6);
                $t->primary(['evidence_asset_id', $column]);
                $t->index($column);
            });
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_origin_check CHECK (origin IN ('declared', 'inherited'))");
        }
    }

    public function down(): void
    {
        // Dropping scopes would widen who sees the evidence: refuse while any exist.
        foreach (['evidence_asset_competitor', 'evidence_asset_market'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Evidence scopes exist; rolling back would widen who sees that evidence.');
            }
        }
        Schema::dropIfExists('evidence_asset_market');
        Schema::dropIfExists('evidence_asset_competitor');
    }
};
