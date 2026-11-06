<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Claim evidence links (P1-41, docs/02 §3.6): a claim cites an evidence asset as supporting, contradicting or giving
 * context, with an optional locator (page, section or timecode) and excerpt. FK CASCADE on the claim side (claims are
 * never deleted, so in practice never), RESTRICT on the evidence side (evidence that is cited is never removed). Grant
 * class `mutable` (`config/database.php`): links are added and removed while the item is editable, each change
 * audited and recorded in the asset's custody trail. Times are DATETIME(6) in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_evidence_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intelligence_claim_id')->constrained('intelligence_claims')->cascadeOnDelete();
            $t->foreignId('evidence_asset_id')->constrained('evidence_assets')->restrictOnDelete();
            $t->string('relation', 12);
            $t->string('locator', 255)->nullable();
            $t->text('excerpt')->nullable();
            $t->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $t->dateTime('created_at', 6);
            // Named: the generated name is longer than MySQL's 64 characters.
            $t->unique(['intelligence_claim_id', 'evidence_asset_id', 'relation'], 'claim_evidence_links_claim_asset_relation_unique');
        });
        DB::statement("ALTER TABLE claim_evidence_links
            ADD CONSTRAINT claim_evidence_links_relation_check CHECK (relation IN ('supports', 'contradicts', 'context')),
            ADD CONSTRAINT claim_evidence_links_locator_check CHECK (locator IS NULL OR CHAR_LENGTH(TRIM(locator)) > 0),
            ADD CONSTRAINT claim_evidence_links_excerpt_check CHECK (excerpt IS NULL OR CHAR_LENGTH(TRIM(excerpt)) > 0)");
    }

    public function down(): void
    {
        // What a claim cited is part of the item's record: refuse while any link exists.
        if (Schema::hasTable('claim_evidence_links') && DB::table('claim_evidence_links')->exists()) {
            throw new RuntimeException('Claim evidence links exist; rolling back would delete them.');
        }
        Schema::dropIfExists('claim_evidence_links');
    }
};
