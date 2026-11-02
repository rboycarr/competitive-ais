<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Pennant's store for feature flags (docs/01, docs/10): one row per flag and scope, the value as JSON. The
 * columns are Pennant's own (vendor/laravel/pennant/database/migrations); the JSON check keeps a hand-edited row from
 * breaking every read. The app account only reads it (read_only): each flag's global row is stored here, off, so the
 * app never inserts one (TASK-0053 #3), and only the audited feature:switch command changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });

        DB::statement('ALTER TABLE features ADD CONSTRAINT features_value_check CHECK (JSON_VALID(value))');

        // Pennant's global scope; a later flag stores its own row in its own migration.
        $now = now();
        DB::table('features')->insert(['name' => 'collection', 'scope' => '__laravel_global', 'value' => 'false', 'created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Refuses while a flag is on (TASK-0053 #5): switch it off first, audited, so the trail matches the state.
     * Dropping the table then leaves every flag off.
     */
    public function down(): void
    {
        if (Schema::hasTable('features') && DB::table('features')->where('value', '<>', 'false')->exists()) {
            throw new RuntimeException('A feature flag is on; switch it off with feature:switch before rolling back.');
        }

        Schema::dropIfExists('features');
    }
};
