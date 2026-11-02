<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regions of a market (P1-23a, docs/02 §3.3): a tree whose levels deepen strictly (region › province › city ›
 * district), held by the database itself. `level_rank` (1–4) is generated from `level`; a child stores its parent's
 * rank, and the composite foreign key (parent_id, market_id, parent_level_rank) → regions (id, market_id, level_rank)
 * makes the parent exist in the same market at exactly that rank, while a CHECK makes the child's rank higher.
 * Ranks rise along every path, so there are no cycles. Names are unique among siblings (NULL-safe key), ignoring case
 * and accents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('parent_key')->storedAs('COALESCE(parent_id, 0)');
            $table->string('name', 255);
            $table->string('level', 12);
            $table->unsignedTinyInteger('level_rank')->nullable()->storedAs( // NULL only for an unknown level, which the CHECK refuses
                "CASE level WHEN 'region' THEN 1 WHEN 'province' THEN 2 WHEN 'city' THEN 3 WHEN 'district' THEN 4 END");
            $table->unsignedTinyInteger('parent_level_rank')->nullable();
            $table->timestamps();

            $table->unique(['market_id', 'parent_key', 'name']);
            $table->unique(['id', 'market_id', 'level_rank'], 'regions_id_market_rank_unique'); // target of the parent key
            $table->foreign(['parent_id', 'market_id', 'parent_level_rank'], 'regions_parent_foreign')
                ->references(['id', 'market_id', 'level_rank'])->on('regions')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE regions
                ADD CONSTRAINT regions_level_check CHECK (level IN ('region', 'province', 'city', 'district')),
                ADD CONSTRAINT regions_parent_check CHECK ((parent_id IS NULL AND parent_level_rank IS NULL)
                    OR (parent_id IS NOT NULL AND parent_level_rank IS NOT NULL AND level_rank > parent_level_rank)),
                ADD CONSTRAINT regions_name_check CHECK (CHAR_LENGTH(name) > 0 AND name = TRIM(name)
                    AND name NOT LIKE '%  %' AND NOT REGEXP_LIKE(REPLACE(name, ' ', ''), '[\\s\\p{Z}\\p{Cc}]'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
