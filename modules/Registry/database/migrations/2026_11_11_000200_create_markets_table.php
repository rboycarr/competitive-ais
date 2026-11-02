<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Markets (P1-23a, docs/02 §3.3): unclassified reference data maintained by taxonomy managers. Never deleted (grant
 * class no_delete): market grants (P1-23c) and price observations refer to them. The code is upper case and fixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('code', 20)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name', 255);
            $table->char('country', 2)->charset('ascii')->collation('ascii_bin');
            $table->char('default_currency', 3)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();

            $table->foreign('default_currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE markets
            ADD CONSTRAINT markets_code_check CHECK (REGEXP_LIKE(code, '^[A-Z0-9][A-Z0-9_-]{0,19}$')),
            ADD CONSTRAINT markets_country_check CHECK (REGEXP_LIKE(country, '^[A-Z]{2}$')),
            ADD CONSTRAINT markets_name_check CHECK (CHAR_LENGTH(TRIM(name)) > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('markets');
    }
};
