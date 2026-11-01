<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Competitors (P1-20, docs/02 §3.3). Archived, never deleted (the app account has no DELETE: grant class no_delete),
 * so access grants, intelligence and evidence can keep referring to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('slug', 255)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('website', 2048)->nullable();
            $table->char('hq_country', 2)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('lifecycle', 16)->default('active');
            $table->string('tier', 16)->nullable();
            $table->foreignId('assigned_analyst_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('classification', 12)->default('internal');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('lifecycle');
            $table->index('assigned_analyst_id');
            $table->fullText(['name', 'legal_name']); // Phase 1 search fallback (P1-24)
        });

        DB::statement("ALTER TABLE competitors
            ADD CONSTRAINT competitors_lifecycle_check CHECK (lifecycle IN ('active', 'watch', 'dormant', 'archived')),
            ADD CONSTRAINT competitors_tier_check CHECK (tier IS NULL OR tier IN ('primary', 'secondary', 'emerging')),
            ADD CONSTRAINT competitors_classification_check CHECK (classification IN ('public', 'internal', 'confidential', 'restricted')),
            ADD CONSTRAINT competitors_country_check CHECK (hq_country IS NULL OR hq_country REGEXP '^[A-Z]{2}$'),
            ADD CONSTRAINT competitors_website_check CHECK (website IS NULL OR website REGEXP '^https?://'),
            ADD CONSTRAINT competitors_name_check CHECK (CHAR_LENGTH(TRIM(name)) > 0),
            ADD CONSTRAINT competitors_slug_check CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
