<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flags for re-review (P1-48, docs/03 state machine; PO decisions O13 and 2026-11-07: the item keeps its status, a flag
 * is added; no auto-retraction):
 * - `rereview_flags`: why an approved or published item must be looked at again: the evidence its claims cite was
 *   withdrawn or failed an integrity check. One per item, asset and cause; written by the system when the Evidence
 *   outbox event is delivered. Grant class `append_only` (`config/database.php`). FKs RESTRICT: items and assets are
 *   never deleted.
 * - `intelligence_items.rereview_since`: set (to the first open flag's time) while the item has open flags; a
 *   flagged item cannot be published, and a published one shows "Under re-review". Cleared when a director confirms
 *   the item still stands, with a `clear_rereview` review row (rationale required) recording it.
 * Times are DATETIME(6) in UTC.
 */
return new class extends Migration
{
    private const DECISIONS = "'assign', 'reassign', 'approve', 'reject', 'request_changes', 'publish', 'flag_for_rereview'";

    public function up(): void
    {
        Schema::create('rereview_flags', function (Blueprint $t) {
            $t->id();
            $t->char('ulid', 26)->unique();
            $t->foreignId('intelligence_item_id')->constrained('intelligence_items')->restrictOnDelete();
            $t->foreignId('evidence_asset_id')->constrained('evidence_assets')->restrictOnDelete();
            $t->string('cause', 30);
            $t->unsignedSmallInteger('item_version');
            $t->dateTime('raised_at', 6);
            $t->unique(['intelligence_item_id', 'evidence_asset_id', 'cause'], 'rereview_flags_item_asset_cause_unique');
            $t->index('evidence_asset_id');
        });
        DB::statement("ALTER TABLE rereview_flags
            ADD CONSTRAINT rereview_flags_cause_check CHECK (cause IN ('evidence_withdrawn', 'evidence_integrity_failed')),
            ADD CONSTRAINT rereview_flags_version_check CHECK (item_version >= 1)");

        Schema::table('intelligence_items', function (Blueprint $t) {
            $t->dateTime('rereview_since', 6)->nullable()->after('released_at');
        });

        DB::statement('ALTER TABLE verification_reviews DROP CHECK verification_reviews_decision_check, DROP CHECK verification_reviews_rationale_required_check');
        DB::statement('ALTER TABLE verification_reviews
            ADD CONSTRAINT verification_reviews_decision_check CHECK (decision IN ('.self::DECISIONS.", 'clear_rereview')),
            ADD CONSTRAINT verification_reviews_rationale_required_check CHECK (decision NOT IN ('reassign', 'reject', 'request_changes', 'clear_rereview') OR rationale IS NOT NULL)");
    }

    public function down(): void
    {
        // Flags and their clearing are part of the item's record: refuse while any exists.
        if (Schema::hasTable('rereview_flags') && DB::table('rereview_flags')->exists()) {
            throw new RuntimeException('Re-review flags exist; rolling back would delete them.');
        }
        if (DB::table('verification_reviews')->where('decision', 'clear_rereview')->exists()) {
            throw new RuntimeException('Re-review clearances exist; rolling back would make them invalid.');
        }
        DB::statement('ALTER TABLE verification_reviews DROP CHECK verification_reviews_decision_check, DROP CHECK verification_reviews_rationale_required_check');
        DB::statement('ALTER TABLE verification_reviews
            ADD CONSTRAINT verification_reviews_decision_check CHECK (decision IN ('.self::DECISIONS.")),
            ADD CONSTRAINT verification_reviews_rationale_required_check CHECK (decision NOT IN ('reassign', 'reject', 'request_changes') OR rationale IS NOT NULL)");
        if (Schema::hasColumn('intelligence_items', 'rereview_since')) {
            Schema::table('intelligence_items', function (Blueprint $t) {
                $t->dropColumn('rereview_since');
            });
        }
        Schema::dropIfExists('rereview_flags');
    }
};
