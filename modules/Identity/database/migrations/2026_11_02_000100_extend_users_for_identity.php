<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Identity fields on users (P1-10, docs/02 §3.2). MFA columns follow with P1-11.
 * status: invited, active, suspended, deactivated. clearance: the classification an account may read up to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable()->after('id');
            $table->string('clearance', 12)->default('internal')->after('password');
            $table->string('status', 16)->default('active')->after('clearance');
            $table->string('timezone', 64)->default('UTC')->after('status');
            $table->string('locale', 10)->nullable()->after('timezone');
            $table->dateTime('last_login_at', 6)->nullable()->after('locale');
            $table->index('status');
        });

        // Existing rows (none outside development) get a ULID before the column becomes required.
        DB::table('users')->whereNull('ulid')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['ulid' => (string) Str::ulid()]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable(false)->change();
            $table->unique('ulid');
        });

        DB::statement("ALTER TABLE users
            ADD CONSTRAINT users_status_check CHECK (status IN ('invited', 'active', 'suspended', 'deactivated')),
            ADD CONSTRAINT users_clearance_check CHECK (clearance IN ('public', 'internal', 'confidential', 'restricted'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK users_status_check, DROP CHECK users_clearance_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['ulid']);
            $table->dropIndex(['status']);
            $table->dropColumn(['ulid', 'clearance', 'status', 'timezone', 'locale', 'last_login_at']);
        });
    }
};
