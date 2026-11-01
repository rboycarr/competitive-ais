<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brands (P1-22a, docs/02 §3.3): a competitor's, or ours when competitor_id is NULL. Unique per owner through the
 * NULL-safe generated key (TASK-0001 #6); the collation makes that ignore case and accents. Names are stored in the
 * registry normal form (Modules\Registry\Support\Names).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('competitor_id')->nullable()->constrained('competitors')->restrictOnDelete();
            $table->unsignedBigInteger('competitor_key')->storedAs('COALESCE(competitor_id, 0)');
            $table->string('name', 255);
            $table->timestamps();

            $table->unique(['competitor_key', 'name']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE brands
                ADD CONSTRAINT brands_name_check CHECK (CHAR_LENGTH(name) > 0 AND name = TRIM(name)
                    AND name NOT LIKE '%  %' AND NOT REGEXP_LIKE(REPLACE(name, ' ', ''), '[\\s\\p{Z}\\p{Cc}]'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
