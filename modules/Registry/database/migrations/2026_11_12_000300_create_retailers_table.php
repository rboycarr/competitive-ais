<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retailers (P1-23b, docs/02 §3.3): a retailer trades in one market through one channel. Names in the registry normal
 * form, unique within a market ignoring case and accents; the website, when given, is an http(s) address. Unused
 * retailers may be removed (audited); prices will refer to them with a restricting foreign key (Phase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retailers', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('website', 2048)->nullable();
            $table->timestamps();

            $table->unique(['market_id', 'name']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE retailers
                ADD CONSTRAINT retailers_website_check CHECK (website IS NULL OR website REGEXP '^https?://'),
                ADD CONSTRAINT retailers_name_check CHECK (CHAR_LENGTH(name) > 0 AND name = TRIM(name)
                    AND name NOT LIKE '%  %' AND NOT REGEXP_LIKE(REPLACE(name, ' ', ''), '[\\s\\p{Z}\\p{Cc}]'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('retailers');
    }
};
