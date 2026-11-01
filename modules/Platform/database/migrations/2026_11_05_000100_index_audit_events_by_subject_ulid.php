<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit viewer (P1-16) finds a record's trail by its public ULID; without this index that is a full scan of an
 * ever-growing table. An index only: no row changes, so row hashes and checkpoints are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->index(['subject_ulid', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['subject_ulid', 'occurred_at']);
        });
    }
};
