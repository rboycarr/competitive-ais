<?php

namespace Modules\Platform\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * docs/02 §1: times are DATETIME(6) in UTC, never TIMESTAMP (the 2038 limit). Laravel's `timestamps()` and some
 * framework tables create TIMESTAMP columns; those that existed when this check was added (TASK-0061 #1) are listed
 * below as known debt, to be converted by their own story. Any other TIMESTAMP column fails, so no new one is added.
 */
class SchemaConventionsTest extends TestCase
{
    use RefreshDatabase;

    /** Known TIMESTAMP columns (2026-11-03). Remove entries as they are converted; never add any. */
    private const KNOWN_TIMESTAMP_COLUMNS = [
        'access_grants.created_at', 'access_grants.updated_at', 'brands.created_at', 'brands.updated_at',
        'channels.created_at', 'channels.updated_at', 'competitor_aliases.created_at', 'competitor_aliases.updated_at',
        'competitor_relationships.created_at', 'competitor_relationships.updated_at', 'competitors.created_at', 'competitors.updated_at',
        'failed_jobs.failed_at', 'features.created_at', 'features.updated_at', 'markets.created_at', 'markets.updated_at',
        'password_reset_tokens.created_at', 'permissions.created_at', 'permissions.updated_at',
        'product_categories.created_at', 'product_categories.updated_at', 'product_variants.created_at', 'product_variants.updated_at',
        'products.created_at', 'products.updated_at', 'regions.created_at', 'regions.updated_at', 'retailers.created_at', 'retailers.updated_at',
        'roles.created_at', 'roles.updated_at', 'sources.created_at', 'sources.updated_at', 'taggables.created_at', 'tags.created_at', 'tags.updated_at',
        'teams.created_at', 'teams.updated_at', 'users.created_at', 'users.email_verified_at', 'users.two_factor_confirmed_at', 'users.updated_at',
    ];

    public function test_no_new_timestamp_columns(): void
    {
        $columns = array_map(fn (object $c): string => implode('.', array_values((array) $c)), DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp' ORDER BY TABLE_NAME, COLUMN_NAME",
        ));

        $this->assertSame([], array_values(array_diff($columns, self::KNOWN_TIMESTAMP_COLUMNS)), 'Use DATETIME(6) ($table->dateTime(..., 6)), never TIMESTAMP (docs/02 §1).');
        // A converted column must leave the list, so it cannot come back unnoticed.
        $this->assertSame([], array_values(array_diff(self::KNOWN_TIMESTAMP_COLUMNS, $columns)), 'Remove converted columns from KNOWN_TIMESTAMP_COLUMNS.');
    }
}
