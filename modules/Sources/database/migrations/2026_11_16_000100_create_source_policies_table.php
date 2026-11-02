<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source policy decisions (P1-31a, docs/02 §3.4, docs/04 §2): one append-only version per decision (grant class
 * append_only), and `sources.current_policy_id`, the approved policy in force. The database holds the review rules:
 * - the decider is never the proposer: each version copies the source's `proposed_by` through a composite foreign key
 *   (so the copy cannot differ, and the source's proposer cannot change once a decision exists) and a CHECK compares it
 *   with `decided_by`;
 * - the current policy is an approval of the same source: a composite foreign key (current_policy_id, id,
 *   current_policy_decision) → (id, source_id, decision), where `current_policy_decision` is generated as 'approved'
 *   whenever a policy is set;
 * - an approved or suspended source has a current policy;
 * - an approval records its terms review; a rejection or a request for information says why; personal data needs its
 *   lawful basis.
 * Differences from the ERD: `proposed_by` (above); `terms_reviewed_on` is a DATE (the ERD's terms_reviewed_at);
 * `personal_data_basis` holds the lawful basis note (docs/04 §2); `decided_at` only, since a version is never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->unique(['id', 'proposed_by'], 'sources_id_proposed_by_unique');
        });

        Schema::create('source_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedSmallInteger('version');
            $table->unsignedBigInteger('proposed_by');
            $table->string('decision', 12);
            $table->string('terms_url', 2048)->nullable();
            $table->text('terms_summary')->nullable();
            $table->date('terms_reviewed_on')->nullable();
            $table->string('robots_decision', 16);
            $table->string('license', 255)->nullable();
            $table->string('allowed_cadence', 50)->nullable();
            $table->unsignedInteger('rate_budget_per_hour')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->boolean('contains_personal_data');
            $table->text('personal_data_basis')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', 6);

            $table->unique(['source_id', 'version']);
            $table->unique(['id', 'source_id', 'decision'], 'source_policies_id_source_decision_unique');
            $table->foreign(['source_id', 'proposed_by'], 'source_policies_source_proposer_foreign')
                ->references(['id', 'proposed_by'])->on('sources')->restrictOnDelete()->restrictOnUpdate();
        });

        $blank = fn (string $column): string => "($column IS NULL OR CHAR_LENGTH(TRIM($column)) = 0)";
        DB::statement("ALTER TABLE source_policies
            ADD CONSTRAINT source_policies_decision_check CHECK (decision IN ('approved', 'rejected', 'needs_info')),
            ADD CONSTRAINT source_policies_robots_check CHECK (robots_decision IN ('allowed', 'disallowed', 'not_applicable', 'not_reviewed')),
            ADD CONSTRAINT source_policies_version_check CHECK (version >= 1),
            ADD CONSTRAINT source_policies_decider_check CHECK (decided_by <> proposed_by),
            ADD CONSTRAINT source_policies_terms_url_check CHECK (terms_url IS NULL OR (terms_url REGEXP '^https?://[^/@?#]+(/|$)' AND terms_url NOT REGEXP '[?#]')),
            ADD CONSTRAINT source_policies_limits_check CHECK ((rate_budget_per_hour IS NULL OR rate_budget_per_hour > 0) AND (retention_days IS NULL OR retention_days > 0)),
            ADD CONSTRAINT source_policies_approval_check CHECK (decision <> 'approved' OR (terms_reviewed_on IS NOT NULL AND NOT {$blank('terms_summary')})),
            ADD CONSTRAINT source_policies_reason_check CHECK (decision = 'approved' OR NOT {$blank('notes')}),
            ADD CONSTRAINT source_policies_personal_data_check CHECK (contains_personal_data = 0 OR NOT {$blank('personal_data_basis')})");

        Schema::table('sources', function (Blueprint $table) {
            $table->unsignedBigInteger('current_policy_id')->nullable()->after('status');
            $table->string('current_policy_decision', 12)->nullable()->storedAs("IF(current_policy_id IS NULL, NULL, 'approved')")->after('current_policy_id');
            $table->foreign(['current_policy_id', 'id', 'current_policy_decision'], 'sources_current_policy_foreign')
                ->references(['id', 'source_id', 'decision'])->on('source_policies')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE sources ADD CONSTRAINT sources_current_policy_check
            CHECK (status NOT IN ('approved', 'suspended') OR current_policy_id IS NOT NULL)");
    }

    public function down(): void
    {
        // Policy versions are governance records: refuse rather than drop them with the table.
        if (Schema::hasTable('source_policies') && DB::table('source_policies')->exists()) {
            throw new RuntimeException('Source policy decisions exist; export them and remove them deliberately before rolling back.');
        }

        DB::statement('ALTER TABLE sources DROP CHECK sources_current_policy_check');
        Schema::table('sources', function (Blueprint $table) {
            $table->dropForeign('sources_current_policy_foreign');
            $table->dropIndex('sources_current_policy_foreign'); // created by MySQL for the foreign key
            $table->dropColumn(['current_policy_decision', 'current_policy_id']);
        });
        Schema::dropIfExists('source_policies');
        Schema::table('sources', function (Blueprint $table) {
            $table->dropUnique('sources_id_proposed_by_unique');
        });
    }
};
