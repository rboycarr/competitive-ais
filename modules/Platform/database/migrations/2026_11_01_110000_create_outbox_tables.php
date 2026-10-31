<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (P1-06, docs/01 F3, docs/02 §3.1). outbox_events is mutable for the app account (the relay
 * updates delivery state); outbox_receipts is append-only and makes handlers idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('aggregate_type', 100);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 100);
            $table->json('payload')->nullable();
            $table->dateTime('available_at', 6);
            // Lease of the relay run that claimed the event; handlers run outside the claiming transaction.
            $table->dateTime('claimed_until', 6)->nullable();
            $table->dateTime('dispatched_at', 6)->nullable();
            // Dead letter: set after the last allowed attempt; never retried automatically.
            $table->dateTime('failed_at', 6)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['dispatched_at', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        DB::statement('ALTER TABLE outbox_events
            ADD CONSTRAINT outbox_events_final_state_check CHECK (dispatched_at IS NULL OR failed_at IS NULL)');

        Schema::create('outbox_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbox_event_id')->constrained('outbox_events')->restrictOnDelete();
            $table->string('handler', 150);
            $table->dateTime('handled_at', 6);

            $table->unique(['outbox_event_id', 'handler']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_receipts');
        Schema::dropIfExists('outbox_events');
    }
};
