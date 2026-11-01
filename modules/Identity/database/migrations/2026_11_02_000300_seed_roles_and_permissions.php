<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1 roles and permissions (docs/07 §3 matrix), as reference data in a migration so every environment has the
 * same set. Later changes go through role.manage (P1-13) and are audited. Scope rules from the matrix footnotes
 * (own drafts, not own items, clearance and grants) are enforced by policies and AccessScope, not here.
 * The service-account role has no web permissions; its API abilities come with Sanctum tokens.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'user.view', 'user.manage', 'team.manage', 'role.manage', 'grant.manage', 'token.manage', 'audit.view',
        'competitor.view', 'competitor.manage', 'product.view', 'product.manage', 'taxonomy.manage',
        'source.view', 'source.propose', 'source.approve', 'source.manage',
        'evidence.view', 'evidence.upload', 'evidence.download', 'evidence.hold',
        'intelligence.view', 'intelligence.create', 'intelligence.submit', 'intelligence.review',
        'intelligence.publish', 'intelligence.retract',
    ];

    private const ROLES = [
        'super_admin' => ['user.view', 'user.manage', 'team.manage', 'role.manage', 'grant.manage', 'token.manage', 'audit.view', 'taxonomy.manage'],
        'ci_director' => ['user.view', 'grant.manage', 'token.manage', 'audit.view', 'competitor.view', 'competitor.manage',
            'product.view', 'product.manage', 'taxonomy.manage', 'source.view', 'source.propose', 'source.approve', 'source.manage',
            'evidence.view', 'evidence.upload', 'evidence.download', 'evidence.hold', 'intelligence.view', 'intelligence.create',
            'intelligence.submit', 'intelligence.review', 'intelligence.publish', 'intelligence.retract'],
        'ci_analyst' => ['token.manage', 'competitor.view', 'competitor.manage', 'product.view', 'product.manage', 'source.view',
            'source.propose', 'evidence.view', 'evidence.upload', 'evidence.download', 'intelligence.view', 'intelligence.create',
            'intelligence.submit', 'intelligence.review'],
        'data_collector' => ['competitor.view', 'product.view', 'source.view', 'evidence.view', 'evidence.upload', 'intelligence.view', 'intelligence.create'],
        'sales_field' => ['competitor.view', 'product.view', 'intelligence.view'],
        'exec_reader' => ['competitor.view', 'product.view', 'intelligence.view'],
        'auditor' => ['user.view', 'audit.view', 'competitor.view', 'product.view', 'source.view', 'evidence.view', 'evidence.download', 'intelligence.view'],
        'service_account' => [],
    ];

    public function up(): void
    {
        $now = now('UTC');
        DB::table('permissions')->insert(array_map(
            fn (string $name): array => ['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now],
            self::PERMISSIONS,
        ));
        $permissionIds = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');

        foreach (self::ROLES as $role => $permissions) {
            $roleId = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            if ($permissions !== []) {
                DB::table('role_has_permissions')->insert(array_map(
                    fn (string $permission): array => ['permission_id' => $permissionIds[$permission], 'role_id' => $roleId],
                    $permissions,
                ));
            }
        }

        // Raw inserts bypass spatie's model events, so its permission cache is cleared explicitly.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')->whereIn('name', array_keys(self::ROLES))->where('guard_name', 'web')->delete();
        DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
