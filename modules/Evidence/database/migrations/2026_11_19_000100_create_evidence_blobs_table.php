<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        DB::statement("ALTER TABLE evidence_blobs ADD CONSTRAINT evidence_blobs_sha_check CHECK (sha256 REGEXP '^[0-9a-f]{64}$'), ADD CONSTRAINT evidence_blobs_integrity_check CHECK (integrity_status IN ('ok', 'mismatch'))");
        Schema::table('evidence_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('evidence_blob_id')->nullable()->after('scan_status');
            $table->foreign('evidence_blob_id')->references('id')->on('evidence_blobs')->restrictOnDelete();
            $table->index('evidence_blob_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('evidence_assets') && DB::table('evidence_assets')->whereNotNull('evidence_blob_id')->exists()) {
            throw new RuntimeException('Evidence blobs are referenced; remove references deliberately before rolling back.');
        }
        Schema::table('evidence_assets', fn (Blueprint $table) => $table->dropForeign(['evidence_blob_id']));
        Schema::table('evidence_assets', fn (Blueprint $table) => $table->dropColumn('evidence_blob_id'));
        Schema::dropIfExists('evidence_blobs');
    }
};
