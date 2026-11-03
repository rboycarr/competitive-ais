<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content-addressed evidence storage (P1-32b, docs/02 §3.5, ADR-0007). `evidence_blobs` holds **only bytes that scanned
 * clean**: a row is created after the virus scan and the type check both pass, by an atomic insert-or-reload on
 * `sha256`, so the same bytes uploaded twice share one blob and one object in the locked `evidence` bucket. Grant class
 * `no_delete` (`config/database.php`): a blob is retained while any asset references it; deleting one only when no
 * asset does is a dedicated job (P1-38), not yet implemented.
 * `retention_until` and `legal_hold` start from `evidence.blob_retention_days` and `false`; from P1-35 they become the
 * MAX and OR over referencing assets. `integrity_status` and `last_verified_at` are for the periodic re-hash job
 * (P1-38, docs/01 F ADR-0007), not yet implemented; every new blob starts `ok`, unverified.
 * `evidence_assets.evidence_blob_id` is added here: an asset has one exactly when it is `available` (the state rule
 * already in 2026_11_18_000100 covers the rest). Times are DATETIME(6) in UTC (docs/02 §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_blobs', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('storage_disk', 32);
            $table->string('storage_key', 512)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('object_version_id', 128)->nullable()->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('size_bytes');
            $table->string('detected_mime', 127);
            $table->string('first_scan_engine', 100);
            $table->dateTime('first_scanned_at', 6);
            $table->date('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->string('integrity_status', 10)->default('ok');
            $table->dateTime('last_verified_at', 6)->nullable();
            $table->dateTime('created_at', 6);
        });

        DB::statement("ALTER TABLE evidence_blobs
            ADD CONSTRAINT evidence_blobs_sha_check CHECK (sha256 REGEXP '^[0-9a-f]{64}$'),
            ADD CONSTRAINT evidence_blobs_size_check CHECK (size_bytes > 0),
            ADD CONSTRAINT evidence_blobs_mime_check CHECK (CHAR_LENGTH(TRIM(detected_mime)) > 0),
            ADD CONSTRAINT evidence_blobs_integrity_check CHECK (integrity_status IN ('ok', 'mismatch'))");

        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->foreignId('evidence_blob_id')->nullable()->after('scan_status')->constrained('evidence_blobs')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE evidence_assets
            ADD CONSTRAINT evidence_assets_blob_check CHECK ((status = 'available') = (evidence_blob_id IS NOT NULL))");
    }

    public function down(): void
    {
        // Blobs are governance records: refuse rather than drop them with the table.
        if (Schema::hasTable('evidence_blobs') && DB::table('evidence_blobs')->exists()) {
            throw new RuntimeException('Evidence blobs exist; export them and remove them deliberately before rolling back.');
        }

        DB::statement('ALTER TABLE evidence_assets DROP CHECK evidence_assets_blob_check');
        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->dropForeign(['evidence_blob_id']);
            $table->dropColumn('evidence_blob_id'); // drops the FK's own index with it
        });
        Schema::dropIfExists('evidence_blobs');
    }
};
