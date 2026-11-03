<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source lifecycle history (P1-31b, docs/04 §2, docs/02 §3.4): one append-only row per suspension, reinstatement or
 * retirement (grant class append_only), with a reason code and a note (PO decision O31). The database holds which
 * change goes from which status to which, and which reasons each allows:
 * - suspend: approved → suspended;
 * - reinstate: suspended → policy_review (a director then approves a new policy version, O31);
 * - retire: any status but retired → retired (final).
 * Submitting and deciding are recorded as policy versions and audit events (P1-31a), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->restrictOnDelete();
            $table->string('action', 12);
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->string('reason', 20);
            $table->text('note');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('changed_at', 6); // DATETIME(6), never TIMESTAMP (docs/02 §1, TASK-0061 #1)

            $table->index(['source_id', 'changed_at']);
        });

        DB::statement("ALTER TABLE source_status_changes
            ADD CONSTRAINT source_status_changes_transition_check CHECK (
                (action = 'suspend' AND from_status = 'approved' AND to_status = 'suspended'
                    AND reason IN ('terms_changed', 'access_blocked', 'complaint', 'error_rate', 'other'))
                OR (action = 'reinstate' AND from_status = 'suspended' AND to_status = 'policy_review'
                    AND reason IN ('resolved', 'terms_changed', 'other'))
                OR (action = 'retire' AND from_status IN ('proposed', 'policy_review', 'approved', 'suspended') AND to_status = 'retired'
                    AND reason IN ('no_longer_needed', 'terms_changed', 'complaint', 'other'))),
            ADD CONSTRAINT source_status_changes_note_check CHECK (CHAR_LENGTH(TRIM(note)) > 0)");
    }

    public function down(): void
    {
        // Lifecycle history is a governance record: refuse rather than drop it with the table.
        if (Schema::hasTable('source_status_changes') && DB::table('source_status_changes')->exists()) {
            throw new RuntimeException('Source status changes exist; export them and remove them deliberately before rolling back.');
        }

        Schema::dropIfExists('source_status_changes');
    }
};
