<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products, ours or a competitor's (P1-22b, docs/02 §3.3). Retired with a status, never deleted (grant class
 * no_delete): price observations will refer to them. A product's brand belongs to the same owner: the composite
 * foreign key (brand_id, competitor_key) → brands (id, competitor_key) makes the database refuse anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->unique(['id', 'competitor_key'], 'brands_id_owner_unique'); // target of the products' brand-owner key
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('owner_kind', 12);
            $table->foreignId('competitor_id')->nullable()->constrained('competitors')->restrictOnDelete();
            $table->unsignedBigInteger('competitor_key')->storedAs('COALESCE(competitor_id, 0)');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->date('launched_on')->nullable();
            $table->date('discontinued_on')->nullable();
            $table->string('classification', 12)->default('internal');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['brand_id', 'competitor_key'], 'products_brand_owner_foreign')->references(['id', 'competitor_key'])->on('brands')->restrictOnDelete();
            $table->index(['competitor_id', 'status']);
            $table->index('category_id');
        });

        DB::statement("ALTER TABLE products
            ADD CONSTRAINT products_owner_check CHECK ((owner_kind = 'competitor' AND competitor_id IS NOT NULL) OR (owner_kind = 'own' AND competitor_id IS NULL)),
            ADD CONSTRAINT products_status_check CHECK (status IN ('active', 'discontinued', 'rumored')),
            ADD CONSTRAINT products_classification_check CHECK (classification IN ('public', 'internal', 'confidential', 'restricted')),
            ADD CONSTRAINT products_dates_check CHECK (discontinued_on IS NULL OR launched_on IS NULL OR discontinued_on >= launched_on),
            ADD CONSTRAINT products_name_check CHECK (CHAR_LENGTH(TRIM(name)) > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique('brands_id_owner_unique');
        });
    }
};
