<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence assets and their custody trail (P1-32a, docs/02 §3.5, ADR-0007).
 * - evidence_assets (grant class no_delete: rejected or withdrawn, never deleted): one row per upload. The bytes wait
 *   in the quarantine bucket under `quarantine_key` (generated, never the file name) until the scan (P1-32b) moves
 *   them to a content-addressed blob or rejects them. Columns that belong to later stories come with them:
 *   evidence_blob_id with the blobs (P1-32b), source_id and source_policy_id (P1-37), retention and legal hold
 *   (P1-35), scopes (P1-36). Difference from the ERD: `size_bytes`, the uploaded size, shown before a blob exists.
 * - evidence_custody_events (append_only): who did what to an asset, when.
 * The database holds the status rules: a quarantined asset has its quarantine object and a pending (or failed,
 * retryable) scan; an available one scanned clean; a rejected one has a scan result.
 * Times are DATETIME(6) in UTC (docs/02 §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_assets', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('status', 12)->default('quarantined');
            $table->string('scan_status', 8)->default('pending');
            $table->string('scan_engine', 100)->nullable();
            $table->dateTime('scanned_at', 6)->nullable();
            $table->string('quarantine_key', 512)->charset('ascii')->collation('ascii_bin')->nullable()->unique();
            $table->string('original_filename', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->dateTime('captured_at', 6);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('classification', 12)->default('internal');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['status', 'captured_at']);
            $table->index('uploaded_by');
        });

        DB::statement("ALTER TABLE evidence_assets
            ADD CONSTRAINT evidence_assets_status_check CHECK (status IN ('quarantined', 'available', 'rejected', 'withdrawn')),
            ADD CONSTRAINT evidence_assets_scan_status_check CHECK (scan_status IN ('pending', 'clean', 'infected', 'error')),
            ADD CONSTRAINT evidence_assets_classification_check CHECK (classification IN ('public', 'internal', 'confidential', 'restricted')),
            ADD CONSTRAINT evidence_assets_title_check CHECK (CHAR_LENGTH(TRIM(title)) > 0),
            ADD CONSTRAINT evidence_assets_filename_check CHECK (CHAR_LENGTH(TRIM(original_filename)) > 0 AND original_filename NOT REGEXP '[/\\\\\\\\[:cntrl:]]'),
            ADD CONSTRAINT evidence_assets_size_check CHECK (size_bytes > 0),
            ADD CONSTRAINT evidence_assets_source_url_check CHECK (source_url IS NULL OR source_url REGEXP '^https?://[^/@?#]+([/?#]|$)'),
            ADD CONSTRAINT evidence_assets_state_check CHECK (
                (status <> 'quarantined' OR (scan_status IN ('pending', 'error') AND quarantine_key IS NOT NULL))
                AND (status <> 'available' OR scan_status = 'clean')
                AND (status <> 'rejected' OR scan_status <> 'pending')
                AND ((scan_status = 'pending') = (scanned_at IS NULL)))");

        Schema::create('evidence_custody_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evidence_asset_id')->constrained('evidence_assets')->restrictOnDelete();
            $table->string('event', 20);
            $table->string('actor_type', 8);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at', 6);
            $table->json('details')->nullable();

            $table->index(['evidence_asset_id', 'occurred_at']);
        });

        DB::statement("ALTER TABLE evidence_custody_events
            ADD CONSTRAINT evidence_custody_events_event_check CHECK (event IN ('uploaded', 'scanned', 'rejected', 'viewed', 'downloaded', 'linked',
                'unlinked', 'scope_inherited', 'hold_set', 'hold_released', 'retention_changed', 'integrity_verified', 'integrity_failed', 'withdrawn')),
            ADD CONSTRAINT evidence_custody_events_actor_check CHECK ((actor_type = 'user' AND actor_id IS NOT NULL) OR (actor_type = 'system' AND actor_id IS NULL))");
    }

    public function down(): void
    {
        // Evidence and its custody trail are governance records: refuse rather than drop them with the tables.
        if (Schema::hasTable('evidence_assets') && DB::table('evidence_assets')->exists()) {
            throw new RuntimeException('Evidence assets exist; export them and remove them deliberately before rolling back.');
        }

        Schema::dropIfExists('evidence_custody_events');
        Schema::dropIfExists('evidence_assets');
    }
};
