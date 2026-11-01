<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product category tree (P1-22a, docs/02 §3.3). `path` lists the ancestors' IDs ("/" for a top-level category,
 * "/12/45/" for a child of 45 under 12), so it is known before the row exists; `depth` is the number of ancestors,
 * at most 5 (six levels). CHECKs keep path, depth and parent consistent; CategoryService keeps the tree free of
 * cycles. Names are unique among siblings (NULL-safe generated key, TASK-0001 #6), ignoring case and accents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->unsignedBigInteger('parent_key')->storedAs('COALESCE(parent_id, 0)');
            $table->string('name', 255);
            $table->string('path', 1000)->charset('ascii')->collation('ascii_bin');
            $table->unsignedTinyInteger('depth');
            $table->timestamps();

            $table->unique(['parent_key', 'name']);
            $table->index('path');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_categories
                ADD CONSTRAINT product_categories_name_check CHECK (CHAR_LENGTH(name) > 0 AND name = TRIM(name)
                    AND name NOT LIKE '%  %' AND NOT REGEXP_LIKE(REPLACE(name, ' ', ''), '[\\s\\p{Z}\\p{Cc}]')),
                ADD CONSTRAINT product_categories_path_check CHECK (REGEXP_LIKE(path, '^/([0-9]+/)*$')
                    AND depth = CHAR_LENGTH(path) - CHAR_LENGTH(REPLACE(path, '/', '')) - 1 AND depth <= 5),
                ADD CONSTRAINT product_categories_parent_check CHECK ((parent_id IS NULL AND path = '/')
                    OR (parent_id IS NOT NULL AND path LIKE CONCAT('%/', parent_id, '/')))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
