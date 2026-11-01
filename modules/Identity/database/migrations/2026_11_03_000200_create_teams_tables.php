<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teams (P1-13, docs/02 §3.2). Access grants can target a team (P1-14). Users are never deleted (deactivated
 * instead), so the cascades on users only matter for test cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6);

            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
