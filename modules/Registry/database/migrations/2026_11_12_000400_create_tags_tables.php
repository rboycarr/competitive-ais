<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags (P1-23b, docs/02 §3.3): a tag belongs to a taxonomy (a lower-case code such as `segment`) and its name is
 * unique there, ignoring case and accents. `taggables` links tags to records; the only type so far is `competitor`
 * (CHECK). The link stays polymorphic as specified, but `competitor_id` is generated from it and has a foreign key, so
 * a competitor link always points at an existing competitor. A tag in use is not removed (foreign key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('taxonomy', 50)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 255);
            $table->timestamps();

            $table->unique(['taxonomy', 'name']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tags
                ADD CONSTRAINT tags_taxonomy_check CHECK (REGEXP_LIKE(taxonomy, '^[a-z][a-z0-9_]{0,49}$', 'c')),
                ADD CONSTRAINT tags_name_check CHECK (CHAR_LENGTH(name) > 0 AND name = TRIM(name)
                    AND name NOT LIKE '%  %' AND NOT REGEXP_LIKE(REPLACE(name, ' ', ''), '[\\s\\p{Z}\\p{Cc}]'))
            SQL);

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained('tags')->restrictOnDelete();
            $table->string('taggable_type', 30)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('taggable_id');
            $table->unsignedBigInteger('competitor_id')->nullable()->storedAs("CASE WHEN taggable_type = 'competitor' THEN taggable_id END");
            $table->timestamp('created_at')->nullable();

            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id']);
            $table->foreign('competitor_id')->references('id')->on('competitors')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE taggables ADD CONSTRAINT taggables_type_check CHECK (taggable_type IN ('competitor'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
