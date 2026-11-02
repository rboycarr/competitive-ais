<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product variants (P1-22b, docs/02 §3.3): a pack of a product, e.g. 150 g × 1. Retired with a status, never deleted
 * (grant class no_delete): price observations will refer to them. The SKU is unique per product (case- and
 * accent-insensitive, stored in the registry name normal form); a GTIN has 8, 12, 13 or 14 digits (the service also
 * checks its check digit). `attributes` (docs/02) is added with its first use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('gtin', 14)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('name', 255);
            $table->decimal('pack_size', 14, 4);
            $table->string('pack_unit', 8);
            $table->unsignedSmallInteger('pack_count')->default(1);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['product_id', 'sku']);
            $table->index('gtin');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_variants
                ADD CONSTRAINT product_variants_sku_check CHECK (CHAR_LENGTH(sku) > 0 AND sku = TRIM(sku)
                    AND sku NOT LIKE '%  %' AND NOT REGEXP_LIKE(REPLACE(sku, ' ', ''), '[\\s\\p{Z}\\p{Cc}]')),
                ADD CONSTRAINT product_variants_gtin_check CHECK (gtin IS NULL OR REGEXP_LIKE(gtin, '^([0-9]{8}|[0-9]{12,14})$')),
                ADD CONSTRAINT product_variants_pack_check CHECK (pack_size > 0 AND pack_count >= 1
                    AND pack_unit IN ('mg', 'g', 'kg', 'ml', 'l', 'unit', 'pack')),
                ADD CONSTRAINT product_variants_status_check CHECK (status IN ('active', 'discontinued')),
                ADD CONSTRAINT product_variants_name_check CHECK (CHAR_LENGTH(TRIM(name)) > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
