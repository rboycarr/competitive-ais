<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Pennant's store for feature flags (docs/01, docs/10): one row per flag and scope, the value as JSON. The
 * columns are Pennant's own (vendor/laravel/pennant/database/migrations); the JSON check keeps a hand-edited row from
 * breaking every read. Read and switched only through Modules\Platform\Support\Features.
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
    }

    /**
     * Dropping the table switches every flag off (the default), which is the safe direction.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
