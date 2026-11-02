<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales channels (P1-23b, docs/02 §3.3): unclassified reference data maintained by taxonomy managers. Names in the
 * registry normal form, unique ignoring case and accents. A channel that retailers use is not removed (foreign key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('channel_type', 20);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE channels
                ADD CONSTRAINT channels_type_check CHECK (channel_type IN ('modern_trade', 'general_trade', 'ecommerce', 'horeca', 'wholesale', 'direct', 'other')),
                ADD CONSTRAINT channels_name_check CHECK (CHAR_LENGTH(name) > 0 AND name = TRIM(name)
                    AND name NOT LIKE '%  %' AND NOT REGEXP_LIKE(REPLACE(name, ' ', ''), '[\\s\\p{Z}\\p{Cc}]'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
