<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal hold on evidence (P1-35, docs/02 §3.5, ADR-0007). `evidence_assets.legal_hold` is a flag, not a status, set and
 * released by evidence.hold holders with a reason (PO decisions O35, O36, 2026-11-05). Only available evidence can be
 * held (O36), so the database refuses a hold on any other status: an asset must be released before it can leave
 * `available`. `evidence_blobs.legal_hold` becomes the OR over its assets and is pushed to the object version as an
 * S3 legal hold, which keeps the version from being deleted whatever its retention date. Per-asset retention dates
 * come with the source policy (P1-37) and the deletion job (P1-38).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->boolean('legal_hold')->default(false)->after('evidence_blob_id');
        });
        DB::statement("ALTER TABLE evidence_assets ADD CONSTRAINT evidence_assets_hold_check CHECK (legal_hold = 0 OR status = 'available')");
    }

    public function down(): void
    {
        // A hold is a legal instruction: refuse rather than drop it with the column.
        if (DB::table('evidence_assets')->where('legal_hold', true)->exists()) {
            throw new RuntimeException('Evidence is on legal hold; release every hold deliberately before rolling back.');
        }

        DB::statement('ALTER TABLE evidence_assets DROP CHECK evidence_assets_hold_check');
        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->dropColumn('legal_hold');
        });
    }
};
