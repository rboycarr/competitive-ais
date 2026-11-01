<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations (P1-10, docs/02 §3.2). Only the SHA-256 of the token is stored. An invitation ends in exactly one way:
 * accepted, revoked (also when replaced by a newer one), or expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('email');
            $table->string('role_name', 125);
            $table->string('clearance', 12);
            $table->char('token_hash', 64)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('expires_at', 6);
            $table->dateTime('accepted_at', 6)->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index('email');
        });

        DB::statement("ALTER TABLE user_invitations
            ADD CONSTRAINT user_invitations_clearance_check CHECK (clearance IN ('public', 'internal', 'confidential', 'restricted')),
            ADD CONSTRAINT user_invitations_token_hash_check CHECK (token_hash REGEXP '^[0-9a-f]{64}$'),
            ADD CONSTRAINT user_invitations_one_outcome_check CHECK (accepted_at IS NULL OR revoked_at IS NULL),
            ADD CONSTRAINT user_invitations_accepted_user_check CHECK ((accepted_at IS NULL) = (accepted_user_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
