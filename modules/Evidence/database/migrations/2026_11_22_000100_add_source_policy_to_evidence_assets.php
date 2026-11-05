<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The source policy that authorized each capture (P1-37, docs/02 §3.5, docs/04 §2). `source_id` names the source the
 * evidence came from (optional: evidence may be uploaded without one) and `source_policy_id` the policy version in
 * force when it was captured. The database holds:
 * - both or neither (`evidence_assets_source_policy_check`, the ERD's CHECK made two-way);
 * - the policy is an approval of the same source: a composite foreign key (source_policy_id, source_id,
 *   source_policy_decision) → source_policies (id, source_id, decision), where `source_policy_decision` is generated
 *   as 'approved' whenever a policy is recorded (the same pattern as `sources.current_policy_id`, P1-31a).
 * That the source was `approved` with that policy in force at capture is checked by EvidenceUploadService under a
 * share lock on the source row. The columns are written once, at upload; making them immutable to direct SQL is part
 * of P1-52.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->after('source_url')->constrained('sources')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('source_policy_id')->nullable()->after('source_id');
            $table->string('source_policy_decision', 12)->nullable()->storedAs("IF(source_policy_id IS NULL, NULL, 'approved')")->after('source_policy_id');
            $table->foreign(['source_policy_id', 'source_id', 'source_policy_decision'], 'evidence_assets_source_policy_foreign')
                ->references(['id', 'source_id', 'decision'])->on('source_policies')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE evidence_assets ADD CONSTRAINT evidence_assets_source_policy_check CHECK ((source_id IS NULL) = (source_policy_id IS NULL))');
    }

    public function down(): void
    {
        // The policy at capture is provenance: refuse rather than drop it with the columns.
        if (DB::table('evidence_assets')->whereNotNull('source_id')->exists()) {
            throw new RuntimeException('Evidence records the source policy that authorized it; export it deliberately before rolling back.');
        }

        DB::statement('ALTER TABLE evidence_assets DROP CHECK evidence_assets_source_policy_check');
        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->dropForeign('evidence_assets_source_policy_foreign');
            $table->dropIndex('evidence_assets_source_policy_foreign'); // created by MySQL for the foreign key
            $table->dropColumn(['source_policy_decision', 'source_policy_id']);
            $table->dropConstrainedForeignId('source_id');
        });
    }
};
