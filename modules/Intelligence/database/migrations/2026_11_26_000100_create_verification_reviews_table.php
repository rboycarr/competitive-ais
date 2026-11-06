<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The verification queue (P1-43, docs/02 §3.6): `verification_reviews`, the append-only record of every assignment and
 * decision (grant class `append_only`, `config/database.php`), each bound to the item's version and a SHA-256 of its
 * content at that version; and `intelligence_items.submitted_at`, when the current submission was made (the queue's
 * age). `assign` (a first assignment) and `assignee_id` (who was assigned) are additions to docs/02: the specified
 * `reviewer_id` is whoever recorded the row. Times are DATETIME(6) in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_reviews', function (Blueprint $t) {
            $t->id();
            $t->char('ulid', 26)->unique();
            // RESTRICT: items are never deleted, and a review must never vanish with one.
            $t->foreignId('intelligence_item_id')->constrained('intelligence_items')->restrictOnDelete();
            $t->unsignedSmallInteger('item_version');
            $t->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('decision', 20);
            $t->text('rationale')->nullable();
            $t->char('content_hash', 64)->charset('ascii')->collation('ascii_bin');
            $t->dateTime('decided_at', 6);
            $t->index(['intelligence_item_id', 'item_version', 'decision'], 'verification_reviews_item_version_decision_index');
        });
        DB::statement("ALTER TABLE verification_reviews
            ADD CONSTRAINT verification_reviews_decision_check CHECK (decision IN ('assign', 'reassign', 'approve', 'reject', 'request_changes', 'publish', 'flag_for_rereview')),
            ADD CONSTRAINT verification_reviews_assignee_check CHECK ((decision IN ('assign', 'reassign')) = (assignee_id IS NOT NULL)),
            ADD CONSTRAINT verification_reviews_rationale_check CHECK (rationale IS NULL OR CHAR_LENGTH(TRIM(rationale)) > 0),
            ADD CONSTRAINT verification_reviews_rationale_required_check CHECK (decision NOT IN ('reassign', 'reject', 'request_changes') OR rationale IS NOT NULL),
            ADD CONSTRAINT verification_reviews_hash_check CHECK (content_hash REGEXP '^[0-9a-f]{64}$'),
            ADD CONSTRAINT verification_reviews_version_check CHECK (item_version >= 1)");

        Schema::table('intelligence_items', function (Blueprint $t) {
            $t->dateTime('submitted_at', 6)->nullable()->after('collected_at');
            $t->index(['status', 'submitted_at']);
        });
        // Items already waiting (submitted before this column) are aged from their last change.
        DB::table('intelligence_items')->whereIn('status', ['submitted', 'in_review'])->update(['submitted_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // Reviews are the item's verification history: refuse while any exists.
        if (Schema::hasTable('verification_reviews') && DB::table('verification_reviews')->exists()) {
            throw new RuntimeException('Verification reviews exist; rolling back would delete them.');
        }
        Schema::dropIfExists('verification_reviews');
        if (Schema::hasColumn('intelligence_items', 'submitted_at')) {
            Schema::table('intelligence_items', function (Blueprint $t) {
                $t->dropIndex(['status', 'submitted_at']);
                $t->dropColumn('submitted_at');
            });
        }
    }
};
