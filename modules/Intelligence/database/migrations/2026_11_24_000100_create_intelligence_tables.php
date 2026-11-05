<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Intelligence items, their subjects and their claims (P1-40, docs/02 §3.6, ADR-0010). Grant classes
 * (`config/database.php`): items and claims `no_delete` (lifecycle by status: claims are withdrawn, never deleted, so
 * what a reviewer saw stays on record); the subject pivots `mutable` (subjects change while the item is editable, every
 * change audited). Review, publication and supersession columns are created now and written by P1-43 and P1-44.
 * Evidence links (P1-41) and reviews (P1-43) get their own migrations. Times are DATETIME(6) in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_items', function (Blueprint $t) {
            $t->id();
            $t->char('ulid', 26)->unique();
            $t->string('title', 255);
            $t->text('summary'); // Markdown source; shown only through SafeMarkdown
            $t->string('item_type', 20);
            $t->string('classification', 12);
            $t->boolean('high_impact')->default(false);
            $t->string('status', 20)->default('draft');
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('observed_at', 6)->nullable();
            $t->dateTime('published_at', 6)->nullable(); // published by the source
            $t->dateTime('collected_at', 6)->nullable();
            $t->dateTime('verified_at', 6)->nullable();
            $t->dateTime('released_at', 6)->nullable(); // published here (P1-44)
            $t->unsignedSmallInteger('version')->default(1);
            $t->foreignId('supersedes_id')->nullable()->constrained('intelligence_items')->restrictOnDelete();
            $t->dateTime('created_at', 6);
            $t->dateTime('updated_at', 6);
            $t->index(['status', 'updated_at']);
            $t->index(['assigned_reviewer_id', 'status']);
            $t->index('released_at');
        });
        DB::statement("ALTER TABLE intelligence_items
            ADD CONSTRAINT intelligence_items_type_check CHECK (item_type IN ('news', 'product_change', 'pricing_signal', 'strategy', 'leadership', 'partnership', 'regulatory', 'facility', 'financial', 'other')),
            ADD CONSTRAINT intelligence_items_classification_check CHECK (classification IN ('public', 'internal', 'confidential', 'restricted')),
            ADD CONSTRAINT intelligence_items_status_check CHECK (status IN ('draft', 'submitted', 'in_review', 'changes_requested', 'approved', 'published', 'rejected', 'retracted', 'superseded')),
            ADD CONSTRAINT intelligence_items_title_check CHECK (CHAR_LENGTH(TRIM(title)) > 0),
            ADD CONSTRAINT intelligence_items_version_check CHECK (version >= 1)");

        // Subjects (docs/02 §3.6): FK CASCADE on the item side, RESTRICT on the subject side. Competitors and markets
        // are the item's scopes for AccessScope (all-of); a product's competitor is always among the competitors.
        foreach ([
            'competitor_intelligence_item' => ['competitor_id', 'competitors'],
            'intelligence_item_product' => ['product_id', 'products'],
            'intelligence_item_market' => ['market_id', 'markets'],
        ] as $table => [$column, $subjects]) {
            Schema::create($table, function (Blueprint $t) use ($column, $subjects) {
                $t->foreignId('intelligence_item_id')->constrained('intelligence_items')->cascadeOnDelete();
                $t->foreignId($column)->constrained($subjects)->restrictOnDelete();
                $t->primary(['intelligence_item_id', $column]);
                $t->index($column);
            });
        }

        Schema::create('intelligence_claims', function (Blueprint $t) {
            $t->id();
            $t->char('ulid', 26)->unique();
            $t->foreignId('intelligence_item_id')->constrained('intelligence_items')->cascadeOnDelete();
            $t->unsignedSmallInteger('position');
            $t->text('statement');
            $t->string('claim_type', 20);
            $t->char('source_reliability', 1)->collation('ascii_bin');
            $t->unsignedTinyInteger('info_credibility');
            $t->string('status', 10)->default('active');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->dateTime('created_at', 6);
            $t->dateTime('updated_at', 6);
            $t->index(['intelligence_item_id', 'position']);
        });
        DB::statement("ALTER TABLE intelligence_claims
            ADD CONSTRAINT intelligence_claims_type_check CHECK (claim_type IN ('observed_fact', 'reported_claim', 'analyst_inference', 'rumor', 'ai_hypothesis')),
            ADD CONSTRAINT intelligence_claims_reliability_check CHECK (source_reliability IN ('A', 'B', 'C', 'D', 'E', 'F')),
            ADD CONSTRAINT intelligence_claims_credibility_check CHECK (info_credibility BETWEEN 1 AND 6),
            ADD CONSTRAINT intelligence_claims_status_check CHECK (status IN ('active', 'disputed', 'withdrawn')),
            ADD CONSTRAINT intelligence_claims_statement_check CHECK (CHAR_LENGTH(TRIM(statement)) > 0)");
    }

    public function down(): void
    {
        // Intelligence is never deleted (docs/02 §1): refuse while any exists.
        if (Schema::hasTable('intelligence_items') && DB::table('intelligence_items')->exists()) {
            throw new RuntimeException('Intelligence items exist; rolling back would delete them.');
        }
        Schema::dropIfExists('intelligence_claims');
        Schema::dropIfExists('intelligence_item_market');
        Schema::dropIfExists('intelligence_item_product');
        Schema::dropIfExists('competitor_intelligence_item');
        Schema::dropIfExists('intelligence_items');
    }
};
