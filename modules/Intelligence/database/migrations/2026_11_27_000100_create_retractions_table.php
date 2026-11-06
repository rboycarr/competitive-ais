<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retractions (P1-44, docs/02 §3.6): why a published item was withdrawn, by whom and when, and optionally the published
 * item that replaces it. One per item (an item is retracted once, and never un-retracted). Grant class `append_only`
 * (`config/database.php`): the record is never changed or removed. FKs RESTRICT: items are never deleted. Times are
 * DATETIME(6) in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retractions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intelligence_item_id')->unique()->constrained('intelligence_items')->restrictOnDelete();
            $t->text('reason');
            $t->foreignId('retracted_by')->constrained('users')->restrictOnDelete();
            $t->dateTime('retracted_at', 6);
            $t->foreignId('replacement_item_id')->nullable()->constrained('intelligence_items')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE retractions
            ADD CONSTRAINT retractions_reason_check CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
            ADD CONSTRAINT retractions_replacement_check CHECK (replacement_item_id IS NULL OR replacement_item_id <> intelligence_item_id)');
    }

    public function down(): void
    {
        // A retraction is part of the item's record: refuse while any exists.
        if (Schema::hasTable('retractions') && DB::table('retractions')->exists()) {
            throw new RuntimeException('Retractions exist; rolling back would delete them.');
        }
        Schema::dropIfExists('retractions');
    }
};
