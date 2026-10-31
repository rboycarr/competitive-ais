<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail (P1-05, ADR-0006, docs/02 §3.1). All three tables are append-only for the app account
 * (config('database.grants.append_only')); the retention account may delete audit_event_network rows only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->dateTime('occurred_at', 6);
            $table->string('actor_type', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->char('subject_ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('classification', 12);
            $table->char('request_id', 26)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->json('context')->nullable();
            $table->char('row_hash', 64)->charset('ascii')->collation('ascii_bin');

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index('occurred_at');
        });

        DB::statement("ALTER TABLE audit_events
            ADD CONSTRAINT audit_events_actor_type_check CHECK (actor_type IN ('user', 'service', 'system')),
            ADD CONSTRAINT audit_events_user_actor_check CHECK (actor_type <> 'user' OR actor_id IS NOT NULL),
            ADD CONSTRAINT audit_events_classification_check CHECK (classification IN ('public', 'internal', 'confidential', 'restricted')),
            ADD CONSTRAINT audit_events_subject_check CHECK ((subject_type IS NULL) = (subject_id IS NULL)),
            ADD CONSTRAINT audit_events_row_hash_check CHECK (row_hash REGEXP '^[0-9a-f]{64}$')");

        // Personal data, outside the hashed row so it can be deleted at end of retention (TASK-0001 #8).
        Schema::create('audit_event_network', function (Blueprint $table) {
            $table->foreignId('audit_event_id')->primary()->constrained('audit_events')->restrictOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->char('user_agent_hash', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->dateTime('created_at', 6);

            $table->index('created_at');
        });

        Schema::create('audit_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('generation');
            $table->unsignedBigInteger('from_audit_id');
            $table->unsignedBigInteger('to_audit_id')->unique();
            $table->unsignedInteger('event_count');
            $table->char('merkle_root', 64)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('watermark_at', 6);
            $table->dateTime('created_at', 6);

            // Two concurrent runs cannot both close a range starting at the same row.
            $table->unique(['generation', 'from_audit_id']);
        });

        DB::statement("ALTER TABLE audit_checkpoints
            ADD CONSTRAINT audit_checkpoints_range_check CHECK (from_audit_id >= 1 AND from_audit_id <= to_audit_id),
            ADD CONSTRAINT audit_checkpoints_root_check CHECK (merkle_root REGEXP '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        // Destructive for audit data: migrate:rollback/reset are prohibited in production (PlatformServiceProvider).
        Schema::dropIfExists('audit_checkpoints');
        Schema::dropIfExists('audit_event_network');
        Schema::dropIfExists('audit_events');
    }
};
