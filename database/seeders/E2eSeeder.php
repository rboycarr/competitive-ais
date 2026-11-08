<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Evidence\Models\EvidenceAsset;
use Modules\Identity\Models\User;
use Modules\Intelligence\Actions\IntelligenceClaimService;
use Modules\Intelligence\Actions\IntelligenceItemService;
use Modules\Intelligence\Actions\IntelligenceWorkflowService;
use Modules\Intelligence\Models\IntelligenceItem;
use Modules\Platform\Classification;
use Modules\Sources\Actions\SourcePolicyService;
use Modules\Sources\Actions\SourceService;
use RuntimeException;

/**
 * Synthetic data for the end-to-end exit journey (P1-47, docs/09 item 9): four users with MFA (analyst, reviewer,
 * director, auditor), an approved source, evidence captured under its policy, one published item (J6 traces it) and
 * one submitted item (J1's decision steps run on it in the browser). Everything goes through the real services, so
 * the audit trail and review records are the ones the app writes.
 *
 * Credentials come from the environment, never the repository: E2E_PASSWORD (16+ characters) and E2E_TOTP_SECRET
 * (base32, 32 characters), the same values the Playwright tests read. Refused in production; skipped if already run.
 *   docker compose run --rm -e E2E_PASSWORD -e E2E_TOTP_SECRET migrator php artisan db:seed --class=E2eSeeder --force
 */
class E2eSeeder extends Seeder
{
    public const USERS = ['analyst' => 'ci_analyst', 'reviewer' => 'ci_analyst', 'director' => 'ci_director', 'auditor' => 'auditor'];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The E2E seeder never runs in production.');
        }
        $password = (string) getenv('E2E_PASSWORD');
        $secret = (string) getenv('E2E_TOTP_SECRET');
        if (strlen($password) < 16 || preg_match('/^[A-Z2-7]{32}$/', $secret) !== 1) {
            throw new RuntimeException('Set E2E_PASSWORD (16+ characters) and E2E_TOTP_SECRET (32 base32 characters).');
        }
        if (User::query()->where('email', 'e2e.analyst@example.test')->exists()) {
            $this->command?->info('E2E data already seeded.');

            return;
        }

        $users = [];
        foreach (self::USERS as $name => $role) {
            $user = new User;
            $user->forceFill([
                'ulid' => (string) Str::ulid(), 'name' => 'E2E '.ucfirst($name), 'email' => "e2e.$name@example.test", 'password' => $password,
                'clearance' => Classification::Confidential, 'status' => 'active', 'email_verified_at' => now(),
                'two_factor_secret' => encrypt(self::totpSecret($secret, $name)), 'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => encrypt((string) json_encode(array_map(fn () => Str::random(10).'-'.Str::random(10), range(1, 8)))),
            ])->save();
            $user->assignRole($role);
            DB::table('access_grants')->insert([
                'ulid' => (string) Str::ulid(), 'user_id' => $user->id, 'scope_type' => 'all', 'max_classification' => 'confidential',
                'granted_by' => $user->id, 'reason' => 'Synthetic E2E grant', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $users[$name] = $user;
        }
        ['analyst' => $analyst, 'reviewer' => $reviewer, 'director' => $director] = $users;

        $acme = (string) Str::ulid();
        DB::table('competitors')->insert([
            'ulid' => $acme, 'name' => 'Acme Synthetic Foods', 'slug' => 'acme-synthetic-foods', 'lifecycle' => 'active',
            'classification' => 'internal', 'created_by' => $analyst->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $source = app(SourceService::class)->propose($analyst, ['name' => 'Synthetic Trade News', 'source_type' => 'website', 'classification' => 'internal']);
        app(SourcePolicyService::class)->submit($analyst, $source);
        $policy = app(SourcePolicyService::class)->decide($director, $source->refresh(), [
            'decision' => 'approved', 'based_on_version' => 0, 'terms_summary' => 'Synthetic terms.', 'terms_reviewed_on' => now('UTC')->subDay()->format('Y-m-d'),
            'robots_decision' => 'not_applicable', 'contains_personal_data' => false,
        ]);

        $published = $this->item($analyst, $acme, 'Acme cuts widget prices', $source->id, $policy->id);
        $workflow = app(IntelligenceWorkflowService::class);
        $workflow->assign($director, $published, ['reviewer' => $reviewer->ulid]);
        $workflow->startReview($reviewer, $published->refresh());
        $workflow->decide($reviewer, $published->refresh(), ['decision' => 'approve', 'version' => $published->version]);
        $workflow->publish($director, $published->refresh(), ['version' => $published->version]);

        $this->item($analyst, $acme, 'Acme opens a new plant', $source->id, $policy->id);
        $this->command?->info('E2E data seeded.');
    }

    /**
     * Each user's own TOTP secret, derived from E2E_TOTP_SECRET: base32 of SHA-1("<secret>:<user>"). Fortify refuses a
     * code that was just used, by any user (CI #234), so the users must not share one. e2e/support.ts derives the same.
     */
    public static function totpSecret(string $secret, string $user): string
    {
        $bits = '';
        foreach (str_split(sha1("$secret:$user", true)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        // 160 bits: exactly 32 five-bit groups, each 0..31.
        return implode('', array_map(fn (string $chunk): string => substr('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', (int) bindec($chunk), 1), str_split($bits, 5)));
    }

    /** A draft with one claim supported by evidence captured from the source, submitted for review. */
    private function item(User $analyst, string $competitor, string $title, int $sourceId, int $policyId): IntelligenceItem
    {
        $item = app(IntelligenceItemService::class)->create($analyst, [
            'title' => $title, 'summary' => 'Synthetic summary for the end-to-end journey.', 'item_type' => 'news',
            'classification' => 'internal', 'high_impact' => false, 'competitors' => [$competitor],
        ]);
        $claims = app(IntelligenceClaimService::class);
        $claim = $claims->add($analyst, $item, ['statement' => "Synthetic claim: $title", 'claim_type' => 'observed_fact', 'source_reliability' => 'B', 'info_credibility' => 2, 'version' => $item->version]);
        $claims->link($analyst, $item->refresh(), $claim->ulid, ['evidence' => $this->evidence($analyst, $title, $sourceId, $policyId)->ulid, 'relation' => 'supports', 'version' => $item->version]);
        app(IntelligenceWorkflowService::class)->submit($analyst, $item->refresh(), ['version' => $item->version]);

        return $item->refresh();
    }

    /** Available evidence captured under the source's policy (synthetic blob row; the bytes are not downloaded). */
    private function evidence(User $uploader, string $title, int $sourceId, int $policyId): EvidenceAsset
    {
        $blob = (int) DB::table('evidence_blobs')->insertGetId([
            'sha256' => hash('sha256', $title), 'storage_disk' => 'evidence', 'storage_key' => 'sha256/e2e/'.Str::random(40),
            'object_version_id' => 'synthetic-version', 'size_bytes' => 10, 'detected_mime' => 'application/pdf', 'first_scan_engine' => 'clamd',
            'first_scanned_at' => now(), 'integrity_status' => 'ok', 'created_at' => now(),
        ]);
        $asset = new EvidenceAsset;
        $asset->forceFill([
            'ulid' => (string) Str::ulid(), 'status' => 'available', 'scan_status' => 'clean', 'scanned_at' => now(), 'evidence_blob_id' => $blob,
            'original_filename' => 'synthetic-report.pdf', 'size_bytes' => 10, 'title' => "Evidence for: $title", 'captured_at' => now()->subDay(),
            'uploaded_by' => $uploader->id, 'classification' => Classification::Internal, 'retention_until' => now('UTC')->addDays(30)->toDateString(),
            'source_id' => $sourceId, 'source_policy_id' => $policyId,
        ])->save();

        return $asset;
    }
}
