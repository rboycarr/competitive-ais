<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies (P1-23a, docs/02 §3.3): an ISO 4217 subset, seeded here. The app account can only read the table
 * (grant class read_only); another currency is added by a migration.
 */
return new class extends Migration
{
    /** ISO 4217: code => [name, minor units]. */
    private const CURRENCIES = [
        'AED' => ['UAE Dirham', 2], 'AUD' => ['Australian Dollar', 2], 'BHD' => ['Bahraini Dinar', 3], 'CAD' => ['Canadian Dollar', 2],
        'CHF' => ['Swiss Franc', 2], 'CNY' => ['Yuan Renminbi', 2], 'EUR' => ['Euro', 2], 'GBP' => ['Pound Sterling', 2],
        'HKD' => ['Hong Kong Dollar', 2], 'IDR' => ['Rupiah', 2], 'INR' => ['Indian Rupee', 2], 'JPY' => ['Yen', 0],
        'KRW' => ['Won', 0], 'KWD' => ['Kuwaiti Dinar', 3], 'MYR' => ['Malaysian Ringgit', 2], 'NZD' => ['New Zealand Dollar', 2],
        'PHP' => ['Philippine Peso', 2], 'SAR' => ['Saudi Riyal', 2], 'SGD' => ['Singapore Dollar', 2], 'THB' => ['Baht', 2],
        'USD' => ['US Dollar', 2], 'VND' => ['Dong', 0],
    ];

    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->charset('ascii')->collation('ascii_bin')->primary();
            $table->string('name', 100);
            $table->unsignedTinyInteger('minor_units');
        });

        DB::statement("ALTER TABLE currencies
            ADD CONSTRAINT currencies_code_check CHECK (REGEXP_LIKE(code, '^[A-Z]{3}$')),
            ADD CONSTRAINT currencies_minor_units_check CHECK (minor_units <= 4)");

        DB::table('currencies')->insert(array_map(
            fn (string $code, array $currency): array => ['code' => $code, 'name' => $currency[0], 'minor_units' => $currency[1]],
            array_keys(self::CURRENCIES),
            self::CURRENCIES,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
