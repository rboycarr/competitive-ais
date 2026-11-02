<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sources (P1-30, docs/02 §3.4, docs/04 §2). Retired, never deleted (grant class no_delete): policy versions and
 * evidence will refer to them. Two differences from the ERD: `proposed_by` records who proposed the source, so the
 * policy review (P1-31) can refuse the proposer as approver; `current_policy_id` comes with `source_policies` in P1-31.
 * The base URL is stored and shown, never fetched; `host` is its domain (lower case, punycode), set by the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name', 255);
            $table->string('source_type', 20);
            $table->string('base_url', 2048)->nullable();
            $table->string('host', 253)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('method', 16)->default('manual');
            $table->string('status', 16)->default('proposed');
            $table->char('default_reliability', 1)->charset('ascii')->collation('ascii_bin')->default('F');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->string('classification', 12)->default('internal');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('host');
            $table->index('status');
        });

        DB::statement("ALTER TABLE sources
            ADD CONSTRAINT sources_type_check CHECK (source_type IN ('website', 'rss', 'api', 'licensed_feed', 'public_filing', 'social', 'internal', 'field', 'other')),
            ADD CONSTRAINT sources_method_check CHECK (method IN ('manual', 'rss', 'api', 'http_fetch', 'browser')),
            ADD CONSTRAINT sources_status_check CHECK (status IN ('proposed', 'policy_review', 'approved', 'suspended', 'retired')),
            ADD CONSTRAINT sources_reliability_check CHECK (default_reliability IN ('A', 'B', 'C', 'D', 'E', 'F')),
            ADD CONSTRAINT sources_classification_check CHECK (classification IN ('public', 'internal', 'confidential', 'restricted')),
            ADD CONSTRAINT sources_name_check CHECK (CHAR_LENGTH(TRIM(name)) > 0),
            ADD CONSTRAINT sources_base_url_check CHECK (base_url IS NULL OR base_url REGEXP '^https?://'),
            ADD CONSTRAINT sources_host_check CHECK ((base_url IS NULL) = (host IS NULL) AND (host IS NULL OR host REGEXP '^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$')),
            ADD CONSTRAINT sources_collection_url_check CHECK (method = 'manual' OR base_url IS NOT NULL)");
    }

    public function down(): void
    {
        // Sources are governance records: refuse rather than drop them with the table.
        if (DB::table('sources')->exists()) {
            throw new RuntimeException('Sources exist; export them and remove them deliberately before rolling back.');
        }

        Schema::dropIfExists('sources');
    }
};
