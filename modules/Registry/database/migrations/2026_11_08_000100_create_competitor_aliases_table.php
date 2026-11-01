<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Competitor aliases (P1-21, docs/02 §3.3): pure child rows of a competitor (CASCADE). Stored normalized (trimmed,
 * single spaces, no control characters); the table collation (utf8mb4_0900_ai_ci) makes the per-competitor UNIQUE
 * case- and accent-insensitive, so "Acme" and "ACMÉ" are the same alias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_id')->constrained('competitors')->cascadeOnDelete();
            $table->string('alias', 255);
            $table->string('alias_type', 16);
            $table->timestamps();

            $table->unique(['competitor_id', 'alias']);
            $table->index('alias'); // lookup by alias (docs/03 CompetitorLookup)
        });

        DB::statement("ALTER TABLE competitor_aliases
            ADD CONSTRAINT competitor_aliases_type_check CHECK (alias_type IN ('brand', 'trade', 'former', 'abbreviation', 'local')),
            ADD CONSTRAINT competitor_aliases_alias_check CHECK (CHAR_LENGTH(alias) > 0 AND alias = TRIM(alias)
                AND alias NOT LIKE '%  %' AND NOT REGEXP_LIKE(alias, '[[:cntrl:]]'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_aliases');
    }
};
