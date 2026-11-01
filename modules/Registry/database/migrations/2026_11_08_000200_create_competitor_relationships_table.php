<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate relationships between competitors (P1-21, docs/02 §3.3). The parent is the owner or principal: the child
 * is its subsidiary, its brand, its joint venture or partner, or its distributor. valid_from is the earliest known
 * date, stored as the start of its precision period; valid_to is inclusive, NULL while open-ended. Ranges for the same
 * pair and type must not overlap: CompetitorRelationshipService checks that under row locks, the UNIQUE is a backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_relationships', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('parent_competitor_id')->constrained('competitors')->restrictOnDelete();
            $table->foreignId('child_competitor_id')->constrained('competitors')->restrictOnDelete();
            $table->string('relation_type', 16);
            $table->date('valid_from');
            $table->string('valid_from_precision', 8)->default('day');
            $table->date('valid_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['parent_competitor_id', 'child_competitor_id', 'relation_type', 'valid_from'], 'competitor_relationships_pair_unique');
            $table->index(['child_competitor_id', 'parent_competitor_id'], 'competitor_relationships_child_index');
        });

        DB::statement("ALTER TABLE competitor_relationships
            ADD CONSTRAINT competitor_relationships_self_check CHECK (parent_competitor_id <> child_competitor_id),
            ADD CONSTRAINT competitor_relationships_type_check CHECK (relation_type IN ('subsidiary', 'brand_owner', 'joint_venture', 'partner', 'distributor')),
            ADD CONSTRAINT competitor_relationships_precision_check CHECK (valid_from_precision IN ('day', 'month', 'year', 'unknown')),
            ADD CONSTRAINT competitor_relationships_period_check CHECK (
                (valid_from_precision <> 'month' OR DAY(valid_from) = 1)
                AND (valid_from_precision <> 'year' OR (MONTH(valid_from) = 1 AND DAY(valid_from) = 1))),
            ADD CONSTRAINT competitor_relationships_validity_check CHECK (valid_to IS NULL OR valid_to >= valid_from)");
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_relationships');
    }
};
