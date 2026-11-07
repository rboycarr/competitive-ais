<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Signed audit anchors (P1-50, ADR-0006, docs/02 §3.1).
 *
 * - audit_checkpoints.revision / supplements_id: a late commit inside a closed range is re-anchored by a supplementary
 *   checkpoint of the same range and generation (revision 2, 3, ...), which names the checkpoint it supplements. The
 *   unique keys move to (generation, from_audit_id, revision) and (to_audit_id, revision).
 * - audit_anchors: one row per checkpoint whose signed manifest is in the audit-anchor bucket, written after the export
 *   succeeded. Checkpoint rows are append-only, so the export is recorded beside them rather than on them; a checkpoint
 *   without an anchor row is exported by the next run (this also signs the checkpoints closed before P1-50, PO
 *   decision O45).
 *
 * Both tables are append-only for the app account (config('database.grants.append_only')).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_checkpoints', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision')->default(1)->after('to_audit_id');
            $table->foreignId('supplements_id')->nullable()->after('revision')->constrained('audit_checkpoints')->restrictOnDelete();
        });

        Schema::table('audit_checkpoints', function (Blueprint $table) {
            $table->unique(['generation', 'from_audit_id', 'revision']);
            $table->unique(['to_audit_id', 'revision']);
            $table->dropUnique(['generation', 'from_audit_id']);
            $table->dropUnique(['to_audit_id']);
        });

        DB::statement('ALTER TABLE audit_checkpoints
            ADD CONSTRAINT audit_checkpoints_revision_check CHECK (revision >= 1 AND (revision = 1) = (supplements_id IS NULL))');

        Schema::create('audit_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkpoint_id')->unique()->constrained('audit_checkpoints')->restrictOnDelete();
            $table->char('key_id', 16)->charset('ascii')->collation('ascii_bin');
            $table->char('signature', 128)->charset('ascii')->collation('ascii_bin');
            $table->string('object_key', 255)->charset('ascii')->collation('ascii_bin')->unique();
            $table->char('manifest_sha256', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('anchored_at', 6);
        });

        DB::statement("ALTER TABLE audit_anchors
            ADD CONSTRAINT audit_anchors_key_id_check CHECK (key_id REGEXP '^[0-9a-f]{16}$'),
            ADD CONSTRAINT audit_anchors_signature_check CHECK (signature REGEXP '^[0-9a-f]{128}$'),
            ADD CONSTRAINT audit_anchors_manifest_check CHECK (manifest_sha256 REGEXP '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        // A supplementary checkpoint shares its range with the one it supplements, which the old unique keys forbid.
        if (DB::table('audit_checkpoints')->where('revision', '>', 1)->exists()) {
            throw new RuntimeException('Supplementary audit checkpoints exist; this migration cannot be rolled back.');
        }

        // The manifests stay in the audit-anchor bucket; without these rows the next run exports them again (same bytes).
        Schema::dropIfExists('audit_anchors');

        DB::statement('ALTER TABLE audit_checkpoints DROP CHECK audit_checkpoints_revision_check');
        Schema::table('audit_checkpoints', function (Blueprint $table) {
            $table->unique(['generation', 'from_audit_id']);
            $table->unique(['to_audit_id']);
            $table->dropUnique(['generation', 'from_audit_id', 'revision']);
            $table->dropUnique(['to_audit_id', 'revision']);
            $table->dropConstrainedForeignId('supplements_id');
            $table->dropColumn('revision');
        });
    }
};
