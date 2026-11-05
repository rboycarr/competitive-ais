<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-asset retention (P1-38, docs/02 §3.5, ADR-0007; PO decisions O33, O39). `retention_until` is the last day (UTC)
 * the asset's bytes must be kept: the upload day plus the `retention_days` of the source policy recorded at capture
 * (P1-37), or `evidence.default_retention_days` (O33) for evidence without a source or whose policy sets none. Days
 * count from the upload (O39), so the date is never in the past and its object-lock date is always valid. A blob is
 * kept until the latest date of the assets that reference it (EvidenceScanService, EvidenceBlobReconciler).
 * Existing assets are filled by the same rule from their `created_at`; blobs already stored are brought up to their
 * assets' dates by `evidence:reconcile-blobs`, which extends the object lock (it never shortens it).
 * The database holds that the date is not before the upload day. Rolling back drops only this derived date: running
 * the migration again derives it again from the same policy and creation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->date('retention_until')->nullable()->after('legal_hold');
        });

        DB::update(
            'UPDATE evidence_assets a LEFT JOIN source_policies p ON p.id = a.source_policy_id
             SET a.retention_until = DATE_ADD(DATE(a.created_at), INTERVAL COALESCE(p.retention_days, ?) DAY)',
            [(int) config('evidence.default_retention_days')],
        );

        DB::statement('ALTER TABLE evidence_assets MODIFY retention_until DATE NOT NULL');
        // At least the upload day (not after it: an upload across midnight may date its retention from the day before).
        DB::statement('ALTER TABLE evidence_assets ADD CONSTRAINT evidence_assets_retention_check CHECK (retention_until >= CAST(created_at AS DATE))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE evidence_assets DROP CHECK evidence_assets_retention_check');
        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->dropColumn('retention_until');
        });
    }
};
