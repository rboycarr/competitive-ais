<?php

namespace Modules\Platform\Tests\Feature;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Modules\Platform\Audit\AuditActions;
use Modules\Platform\Audit\AuditAnchor;
use Modules\Platform\Audit\AuditCheckpointer;
use Modules\Platform\Audit\AuditHasher;
use Modules\Platform\Audit\AuditLogger;
use Modules\Platform\Audit\AuditSigner;
use Modules\Platform\Audit\AuditVerifier;
use Modules\Platform\Storage\LockedObjects;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-50 / ADR-0006 / docs/07 T21 / docs/09 #18: checkpoints are signed with an Ed25519 key that is not in the database
 * and exported to the locked audit-anchor bucket; verification trusts the signed manifests, so rewriting audit rows and
 * checkpoints together is detected. Checkpoints closed before P1-50 are checked, then signed (PO decision O45); a late
 * commit is re-anchored only on purpose; long transactions are alerted. The bucket is a fake disk (synthetic seeds),
 * except where the S3 adapter is driven through the SDK's mock handler. MySQL 8.4 as the real accounts.
 */
class AuditAnchorTest extends TestCase
{
    use DatabaseMigrations;

    private const SEED = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(AuditActions::class)->define('probe.recorded', ['note' => 'text']);
        config(['audit.anchor.signing_seed' => self::SEED, 'audit.anchor.trusted_keys' => '', 'audit.anchor.lock_mode' => 'GOVERNANCE']);
        Storage::fake('audit-anchor');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        // The P1-50 migration refuses rollback while supplementary checkpoints exist; DatabaseMigrations rolls back.
        $migrator = DB::connection('migrator');
        $supplements = $migrator->table('audit_checkpoints')->where('revision', '>', 1)->pluck('id');
        $migrator->table('audit_anchors')->whereIn('checkpoint_id', $supplements)->delete();
        $migrator->table('audit_checkpoints')->whereIn('id', $supplements)->delete();

        parent::tearDown();
    }

    public function test_each_checkpoint_is_signed_exported_and_recorded(): void
    {
        $this->recordAt(-60, 'a');
        $this->recordAt(-59, 'b');
        $result = $this->anchor()->checkpoint();
        $checkpoint = $result['checkpoint'];
        $this->assertNotNull($checkpoint);
        $this->assertSame(1, $result['anchored']);

        $key = AuditAnchor::objectKey(1, $checkpoint['from'], $checkpoint['to'], 1);
        $this->assertSame('g1/00000000000000000001-00000000000000000002-r00001.json', $key);
        $body = (string) Storage::disk('audit-anchor')->get($key);
        $object = json_decode($body, true, 4, JSON_THROW_ON_ERROR);

        // Checked with the seed directly, not through AuditSigner.
        /** @var non-empty-string $seed */
        $seed = hex2bin(self::SEED);
        /** @var non-empty-string $publicKey */
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        /** @var non-empty-string $signature */
        $signature = hex2bin($object['signature']);
        $this->assertSame(['v' => 1, 'generation' => 1, 'revision' => 1, 'from' => 1, 'to' => 2, 'count' => 2, 'root' => $checkpoint['root']],
            array_slice($object['manifest'], 0, 7));
        $this->assertSame(substr(hash('sha256', $publicKey), 0, 16), $object['manifest']['key_id']);
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, AuditAnchor::encode($object['manifest']), $publicKey));

        $anchor = DB::table('audit_anchors')->sole();
        $this->assertSame([$object['manifest']['key_id'], $object['signature'], $key, hash('sha256', $body)],
            [$anchor->key_id, $anchor->signature, $anchor->object_key, $anchor->manifest_sha256]);

        $this->assertSame([], $this->verify());
        $this->assertSame(['anchored' => 0, 'checkpoint' => null], $this->anchor()->checkpoint(), 'Nothing new to close or export.');
    }

    public function test_rewriting_rows_and_their_checkpoint_together_is_detected(): void
    {
        $ulid = $this->recordAt(-60, 'original');
        $this->recordAt(-59, 'second');
        $this->anchor()->checkpoint();

        // A database-level attacker edits a row, recomputes its hash, and recomputes the checkpoint's root (T21).
        $hasher = $this->app->make(AuditHasher::class);
        $row = (array) DB::table('audit_events')->where('ulid', $ulid)->sole();
        $row['context'] = ['note' => 'forged'];
        DB::connection('migrator')->table('audit_events')->where('ulid', $ulid)->update(['context' => json_encode(['note' => 'forged']), 'row_hash' => $hasher->rowHash($row)]); // @phpstan-ignore argument.type
        $rows = array_values(DB::table('audit_events')->orderBy('id')->get(['id', 'row_hash'])->map(fn ($r): array => ['id' => (int) $r->id, 'row_hash' => (string) $r->row_hash])->all());
        DB::connection('migrator')->table('audit_checkpoints')->update(['merkle_root' => $hasher->merkleRoot($rows)]);

        $this->assertSame(['root_mismatch', 'checkpoint_mismatch'], array_column($this->verify(), 'type'));
    }

    public function test_an_altered_manifest_is_rejected(): void
    {
        $this->recordAt(-60, 'a');
        $checkpoint = $this->anchor()->checkpoint()['checkpoint'];
        $this->assertNotNull($checkpoint);

        $key = AuditAnchor::objectKey(1, $checkpoint['from'], $checkpoint['to'], 1);
        $object = json_decode((string) Storage::disk('audit-anchor')->get($key), true, 4, JSON_THROW_ON_ERROR);
        $object['manifest']['root'] = str_repeat('f', 64); // in range, so only the signature catches it
        Storage::disk('audit-anchor')->put($key, AuditAnchor::encode($object));

        $issues = $this->verify();
        $this->assertSame(['manifest_invalid', 'manifest_missing', 'anchor_stale'], array_column($issues, 'type'));
        $this->assertStringContainsString('signature does not verify', $issues[0]['detail']);
    }

    public function test_a_manifest_signed_by_an_untrusted_key_is_rejected(): void
    {
        $this->recordAt(-60, 'a');
        $checkpoint = $this->anchor()->checkpoint()['checkpoint'];
        $this->assertNotNull($checkpoint);

        // Someone with write access to the bucket, but not the signing key, adds a "revision 2" that counts more rows.
        $pair = sodium_crypto_sign_keypair();
        $manifest = ['v' => 1, 'generation' => 1, 'revision' => 2, 'from' => $checkpoint['from'], 'to' => $checkpoint['to'], 'count' => 5,
            'root' => str_repeat('0', 64), 'watermark_at' => '2026-11-07 00:00:00.000000', 'closed_at' => '2026-11-07 00:00:00.000000',
            'key_id' => AuditSigner::keyIdOf(sodium_crypto_sign_publickey($pair))];
        $signature = bin2hex(sodium_crypto_sign_detached(AuditAnchor::encode($manifest), sodium_crypto_sign_secretkey($pair)));
        Storage::disk('audit-anchor')->put(AuditAnchor::objectKey(1, $checkpoint['from'], $checkpoint['to'], 2), AuditAnchor::encode(['manifest' => $manifest, 'signature' => $signature]));
        Storage::disk('audit-anchor')->put('g1/not-a-manifest.json', '{"hello":');

        $this->assertSame(['manifest_invalid', 'manifest_invalid'], array_column($this->verify(), 'type'), 'The genuine revision 1 still covers the range.');
    }

    public function test_manifests_of_an_earlier_key_verify_once_it_is_trusted(): void
    {
        $this->recordAt(-60, 'a');
        $this->anchor()->checkpoint();
        $oldPublicKey = $this->app->make(AuditSigner::class)->publicKeyHex();

        config(['audit.anchor.signing_seed' => str_repeat('7c', 32)]); // rotated
        $this->assertContains('manifest_invalid', array_column($this->verify(), 'type'));

        config(['audit.anchor.trusted_keys' => " $oldPublicKey "]);
        $this->recordAt(-40, 'b');
        $this->anchor()->checkpoint();
        $this->assertSame([], $this->verify());
        $this->assertCount(2, DB::table('audit_anchors')->distinct()->pluck('key_id'));

        config(['audit.anchor.trusted_keys' => 'not-a-key']);
        $this->assertThrows(fn () => $this->verify(), RuntimeException::class, 'AUDIT_TRUSTED_KEYS');
    }

    public function test_checkpoints_closed_before_signing_are_checked_then_signed(): void
    {
        $this->recordAt(-60, 'a');
        $this->assertNotNull($this->app->make(AuditCheckpointer::class)->close()); // as before P1-50: no manifest
        $this->assertSame(1, $this->anchor()->anchorPending());
        $this->assertSame([], $this->verify());

        $ulid = $this->recordAt(-50, 'b');
        $this->assertNotNull($this->app->make(AuditCheckpointer::class)->close());
        DB::connection('migrator')->table('audit_events')->where('ulid', $ulid)->update(['context' => json_encode(['note' => 'forged'])]);

        $this->assertThrows(fn () => $this->anchor()->anchorPending(), RuntimeException::class, 'no longer matches its rows, so it is not signed');
        $this->assertSame(1, DB::table('audit_anchors')->count());
        $this->assertCount(1, Storage::disk('audit-anchor')->files('g1'));
    }

    public function test_a_late_commit_is_reanchored_only_on_purpose(): void
    {
        $this->signedRangeWithGap();
        $this->lateCommit(2);
        $this->assertSame(['late_commit'], array_column($this->verify(), 'type'));

        $this->assertSame(1, Artisan::call('audit:reanchor', ['from' => '1', '--late' => '2']));
        $this->assertStringContainsString('Give the reason', Artisan::output());
        $this->assertSame(1, Artisan::call('audit:reanchor', ['from' => '1', '--reason' => 'x']));
        $this->assertStringContainsString('Name the late-committed audit IDs', Artisan::output());
        $this->assertSame(2, Artisan::call('audit:reanchor', ['from' => 'one', '--late' => '2', '--reason' => 'x']));
        $this->assertSame(2, Artisan::call('audit:reanchor', ['from' => '1', '--late' => '2,x', '--reason' => 'x']));
        $this->assertSame(1, Artisan::call('audit:reanchor', ['from' => '999', '--late' => '2', '--reason' => 'x']));
        $this->assertStringContainsString('No signed manifest', Artisan::output());
        $this->assertSame(1, Artisan::call('audit:reanchor', ['from' => '1', '--late' => '7', '--reason' => 'x']));
        $this->assertStringContainsString('not rows of this range', Artisan::output());
        // Naming the wrong row: without row 1 the range no longer matches what was signed.
        $this->assertSame(1, Artisan::call('audit:reanchor', ['from' => '1', '--late' => '1', '--reason' => 'x']));
        $this->assertStringContainsString('does not match its signed manifest', Artisan::output());
        $this->assertSame(1, DB::table('audit_checkpoints')->count());

        $this->assertSame(0, Artisan::call('audit:reanchor', ['from' => '1', '--late' => '2', '--reason' => 'Batch import held its transaction open; row 2 checked against the import log.']));
        $supplement = DB::table('audit_checkpoints')->where('revision', 2)->sole();
        $this->assertSame([1, 3, 3], [(int) $supplement->from_audit_id, (int) $supplement->to_audit_id, (int) $supplement->event_count]);
        $this->assertSame((int) DB::table('audit_checkpoints')->where('revision', 1)->value('id'), (int) $supplement->supplements_id);
        $this->assertTrue(Storage::disk('audit-anchor')->exists(AuditAnchor::objectKey(1, 1, 3, 2)));
        $this->assertSame([], $this->verify());

        $audit = DB::table('audit_events')->where('action', 'audit.reanchored')->sole();
        $this->assertEquals(['count' => 3, 'from' => 1, 'generation' => 1, 'late_ids' => [2], 'previous_count' => 2, 'reason' => 'Batch import held its transaction open; row 2 checked against the import log.', 'revision' => 2, 'to' => 3],
            json_decode((string) $audit->context, true));
        $this->assertSame('system', $audit->actor_type);

        $this->assertSame(1, Artisan::call('audit:reanchor', ['from' => '1', '--late' => '2', '--reason' => 'again']));
        $this->assertStringContainsString('does not match its signed manifest (r2)', Artisan::output());

        // A deletion is never re-anchored.
        DB::connection('migrator')->table('audit_events')->where('id', 2)->delete();
        $this->assertSame(['rows_missing'], array_column($this->verify(), 'type'));
        $this->assertSame(1, Artisan::call('audit:reanchor', ['from' => '1', '--late' => '3', '--reason' => 'hide it']));
        $this->assertSame(2, DB::table('audit_checkpoints')->count());
    }

    public function test_a_forged_supplement_written_into_the_database_is_never_signed(): void
    {
        $this->signedRangeWithGap();
        $this->lateCommit(2); // a fabricated row with a valid hash (TASK-0096 #1)

        // ... and a supplementary checkpoint that counts it, written straight into the table.
        $rows = array_values(DB::table('audit_events')->orderBy('id')->get(['id', 'row_hash'])->map(fn ($r): array => ['id' => (int) $r->id, 'row_hash' => (string) $r->row_hash])->all());
        $signed = DB::table('audit_checkpoints')->sole();
        DB::connection('migrator')->table('audit_checkpoints')->insert(['generation' => 1, 'from_audit_id' => 1, 'to_audit_id' => 3, 'revision' => 2,
            'supplements_id' => $signed->id, 'event_count' => 3, 'merkle_root' => $this->app->make(AuditHasher::class)->merkleRoot($rows),
            'watermark_at' => $signed->watermark_at, 'created_at' => $signed->created_at]);

        $this->assertSame(['anchored' => 0, 'checkpoint' => null], $this->anchor()->checkpoint());
        $this->assertFalse(Storage::disk('audit-anchor')->exists(AuditAnchor::objectKey(1, 1, 3, 2)));
        $this->assertSame(['late_commit', 'unsigned_supplement'], array_column($this->verify(), 'type'));
        $this->assertSame('critical', $this->verify()[1]['severity']);
    }

    public function test_a_checkpoint_row_that_does_not_continue_the_signed_ranges_is_not_signed(): void
    {
        $this->recordAt(-60, 'a');
        $this->recordAt(-59, 'b');
        $this->anchor()->checkpoint(); // signs 1-2
        $this->recordAt(-50, 'c');

        // Someone inserts a checkpoint over rows 2-3, overlapping the signed range (TASK-0096 #3).
        $rows = array_values(DB::table('audit_events')->where('id', '>=', 2)->orderBy('id')->get(['id', 'row_hash'])->map(fn ($r): array => ['id' => (int) $r->id, 'row_hash' => (string) $r->row_hash])->all());
        $signed = DB::table('audit_checkpoints')->sole();
        DB::table('audit_checkpoints')->insert(['generation' => 1, 'from_audit_id' => 2, 'to_audit_id' => 3, 'event_count' => 2,
            'merkle_root' => $this->app->make(AuditHasher::class)->merkleRoot($rows), 'watermark_at' => $signed->watermark_at, 'created_at' => $signed->created_at]);

        $this->assertThrows(fn () => $this->anchor()->anchorPending(), RuntimeException::class, 'but the signed ranges end at 2, so it is not signed');
        $this->assertCount(1, Storage::disk('audit-anchor')->files('g1'));
    }

    public function test_a_changed_or_deleted_export_record_is_reported_and_recorded_again_visibly(): void
    {
        $this->recordAt(-60, 'a');
        $this->anchor()->checkpoint();

        DB::connection('migrator')->table('audit_anchors')->update(['signature' => str_repeat('0', 128)]);
        $this->assertSame(['anchor_record_mismatch'], array_column($this->verify(), 'type'));

        DB::connection('migrator')->table('audit_anchors')->delete();
        $this->assertSame(['anchor_record_mismatch'], array_column($this->verify(), 'type'));
        $this->assertStringContainsString('missing', $this->verify()[0]['detail']);

        $log = Log::spy();
        $this->assertSame(1, $this->anchor()->anchorPending());
        $log->shouldHaveReceived('warning')->once()->with('Audit manifest already exported; recording its anchor again', Mockery::type('array'));
        $this->assertSame([], $this->verify());
    }

    public function test_verification_needs_only_the_public_keys(): void
    {
        $this->recordAt(-60, 'a');
        $this->anchor()->checkpoint();
        $publicKey = $this->app->make(AuditSigner::class)->publicKeyHex();

        config(['audit.anchor.signing_seed' => null, 'audit.anchor.trusted_keys' => $publicKey]);
        $this->assertSame([], $this->verify());
        $this->assertThrows(fn () => $this->anchor()->checkpoint(), RuntimeException::class, 'auditor service');
    }

    public function test_a_signed_manifest_with_values_out_of_range_is_rejected(): void
    {
        $this->recordAt(-60, 'a');
        $this->anchor()->checkpoint();

        $signer = $this->app->make(AuditSigner::class);
        $manifest = ['v' => 1, 'generation' => 1, 'revision' => 2, 'from' => 1, 'to' => 1, 'count' => 5, 'root' => str_repeat('a', 64),
            'watermark_at' => '2026-11-07 00:00:00.000000', 'closed_at' => '2026-11-07 00:00:00.000000', 'key_id' => $signer->keyId()];
        Storage::disk('audit-anchor')->put(AuditAnchor::objectKey(1, 1, 1, 2), AuditAnchor::encode(['manifest' => $manifest, 'signature' => $signer->sign(AuditAnchor::encode($manifest))]));

        $issues = $this->verify();
        $this->assertSame(['manifest_invalid'], array_column($issues, 'type'));
        $this->assertStringContainsString('values out of range', $issues[0]['detail']);
    }

    public function test_the_scheduler_watches_that_anchoring_keeps_running(): void
    {
        $this->assertSame(0, $this->freshness());
        $this->recordAt(-20, 'recent');
        $this->assertSame(0, $this->freshness());

        $this->recordAt(-60, 'old');
        $log = Log::spy();
        $this->assertSame(1, $this->freshness());
        $log->shouldHaveReceived('critical')->once()->with('Audit rows are not being anchored; check the auditor service', Mockery::type('array'));

        $this->anchor()->checkpoint();
        $this->assertSame(0, $this->freshness());
    }

    public function test_unexported_checkpoints_and_unsigned_rows_are_reported_once_stale(): void
    {
        $this->recordAt(-20, 'a');
        $this->assertNotNull($this->app->make(AuditCheckpointer::class)->close());
        $this->assertSame([], $this->verify(), 'Not stale yet.');

        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertSame(['unanchored', 'anchor_stale'], array_column($this->verify(), 'type'));

        $this->anchor()->anchorPending();
        $this->assertSame([], $this->verify());
    }

    public function test_nothing_is_closed_without_the_key_and_production_needs_compliance_mode(): void
    {
        $this->recordAt(-60, 'a');

        config(['audit.anchor.signing_seed' => null]);
        $this->assertThrows(fn () => $this->anchor()->checkpoint(), RuntimeException::class, 'auditor service');
        $this->assertThrows(fn () => $this->verify(), RuntimeException::class, 'auditor service');
        config(['audit.anchor.signing_seed' => 'not-hex']);
        $this->assertThrows(fn () => $this->anchor()->checkpoint(), RuntimeException::class, '64 lowercase hex');
        $this->assertSame(0, DB::table('audit_checkpoints')->count(), 'Nothing closed that could not be signed.');

        config(['audit.anchor.signing_seed' => self::SEED, 'audit.anchor.lock_mode' => 'NONE']);
        $this->assertThrows(fn () => $this->anchor()->checkpoint(), RuntimeException::class, 'COMPLIANCE or GOVERNANCE');

        config(['audit.anchor.lock_mode' => 'GOVERNANCE']);
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->assertThrows(fn () => $this->anchor()->checkpoint(), RuntimeException::class, 'compliance mode');
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        $this->assertSame(0, DB::table('audit_checkpoints')->count());
    }

    public function test_the_auditor_loop_runs_once_and_only_the_freshness_watch_is_on_the_app_schedule(): void
    {
        $ulid = $this->recordAt(-60, 'a');
        $this->assertSame(0, Artisan::call('audit:work', ['--once' => true]));
        $this->assertSame(1, DB::table('audit_anchors')->count());

        DB::connection('migrator')->table('audit_events')->where('ulid', $ulid)->update(['action' => 'probe.forged']);
        $this->assertSame(1, Artisan::call('audit:work', ['--once' => true]), 'Verification found an issue.');

        $scheduled = collect($this->app->make(Schedule::class)->events())->map(fn ($event): string => $event->command.' '.$event->description);
        $this->assertNotEmpty($scheduled);
        $audit = $scheduled->filter(fn (string $line): bool => str_contains(strtolower($line), 'audit'))->values();
        $this->assertCount(1, $audit, 'The scheduler has no signing key: it only watches the auditor.');
        $this->assertStringContainsString('audit:freshness', (string) $audit->first());
    }

    public function test_long_transactions_are_alerted_when_they_commit(): void
    {
        $log = Log::spy();

        DB::transaction(fn () => DB::transaction(fn () => Carbon::setTestNow(now()->addSeconds(299))));
        Carbon::setTestNow();
        try {
            DB::transaction(function () {
                Carbon::setTestNow(now()->addSeconds(900));
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        Carbon::setTestNow();
        $log->shouldNotHaveReceived('critical');

        DB::transaction(function () {
            DB::transaction(fn () => null);
            Carbon::setTestNow(now()->addSeconds(301));
        });
        $log->shouldHaveReceived('critical')->once()->with('Long database transaction committed', Mockery::on(
            fn (array $context): bool => $context['connection'] === 'mysql' && $context['seconds'] >= 301 && $context['limit'] === 300,
        ));
    }

    public function test_the_schema_keeps_revisions_and_anchors_consistent(): void
    {
        $this->recordAt(-60, 'a');
        $this->assertNotNull($this->app->make(AuditCheckpointer::class)->close()); // not exported yet
        $checkpoint = DB::table('audit_checkpoints')->sole();

        $supplement = ['generation' => 1, 'from_audit_id' => 1, 'to_audit_id' => 1, 'event_count' => 1, 'merkle_root' => $checkpoint->merkle_root,
            'watermark_at' => $checkpoint->watermark_at, 'created_at' => $checkpoint->created_at];
        foreach ([['revision' => 2, 'supplements_id' => null], ['revision' => 1, 'supplements_id' => $checkpoint->id], ['revision' => 0, 'supplements_id' => null]] as $bad) {
            $this->assertThrows(fn () => DB::table('audit_checkpoints')->insert($supplement + $bad), QueryException::class);
        }
        $good = ['checkpoint_id' => $checkpoint->id, 'key_id' => str_repeat('a', 16), 'signature' => str_repeat('b', 128),
            'object_key' => 'g1/x', 'manifest_sha256' => str_repeat('c', 64), 'anchored_at' => $checkpoint->created_at];
        foreach (['key_id' => 'XYZ', 'signature' => 'nope', 'manifest_sha256' => str_repeat('C', 64)] as $column => $value) {
            $this->assertThrows(fn () => DB::table('audit_anchors')->insert([$column => $value] + $good), QueryException::class);
        }
        $this->assertSame(1, $this->anchor()->anchorPending());
        $this->assertThrows(fn () => DB::table('audit_anchors')->update(['key_id' => str_repeat('0', 16)]), QueryException::class); // append-only
        $this->assertThrows(fn () => DB::table('audit_anchors')->delete(), QueryException::class);
        $this->assertSame(1, DB::table('audit_anchors')->count());
    }

    public function test_manifests_are_written_to_s3_with_the_lock_mode_given(): void
    {
        config(['filesystems.disks.audit-anchor.key' => 'synthetic-key', 'filesystems.disks.audit-anchor.secret' => 'synthetic-secret']);
        Storage::forgetDisk('audit-anchor');
        $disk = Storage::disk('audit-anchor');
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $requests = [];
        $disk->getClient()->getHandlerList()->setHandler(new MockHandler([
            function (CommandInterface $command) use (&$requests): Result {
                $requests[] = [$command->getName(), $command['Key'], $command['ObjectLockMode'], (string) $command['ObjectLockRetainUntilDate']];

                return new Result(['VersionId' => 'v1']);
            },
        ]));

        $until = CarbonImmutable::parse('2033-11-07 00:00:00', 'UTC');
        $this->assertSame('v1', LockedObjects::write($disk, 'g1/m.json', '{"manifest":{}}', 'application/json', $until, 'COMPLIANCE'));
        $this->assertSame([['PutObject', 'g1/m.json', 'COMPLIANCE', '2033-11-07T00:00:00Z']], $requests);
    }

    private function anchor(): AuditAnchor
    {
        return $this->app->make(AuditAnchor::class);
    }

    /**
     * @return list<array{type: string, severity: string, detail: string}>
     *
     * @phpstan-impure
     */
    private function verify(): array
    {
        return $this->app->make(AuditVerifier::class)->verify();
    }

    /** @phpstan-impure */
    private function freshness(): int
    {
        return Artisan::call('audit:freshness');
    }

    /** Records an event as if it happened $minutes from now (negative = in the past). */
    private function recordAt(int $minutes, string $note): string
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
        try {
            return DB::transaction(fn () => $this->app->make(AuditLogger::class)->record('probe.recorded', null, ['note' => $note]));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Rows 1 and 3 (ID 2 rolled back), signed as one range 1-3 that counts 2. */
    private function signedRangeWithGap(): void
    {
        $this->recordAt(-60, 'first');
        try {
            DB::transaction(function () {
                $this->app->make(AuditLogger::class)->record('probe.recorded', null, ['note' => 'rolled back']);
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        $this->recordAt(-58, 'third');
        $checkpoint = $this->anchor()->checkpoint()['checkpoint'];
        $this->assertNotNull($checkpoint);
        $this->assertSame([1, 3, 2], [$checkpoint['from'], $checkpoint['to'], $checkpoint['count']]);
    }

    /** A valid row committed with ID $id after its range was signed, as a long transaction would. */
    private function lateCommit(int $id): void
    {
        $row = ['ulid' => (string) Str::ulid(), 'occurred_at' => now('UTC')->subMinutes(59)->format('Y-m-d H:i:s.u'),
            'actor_type' => 'system', 'actor_id' => null, 'action' => 'probe.recorded', 'subject_type' => null, 'subject_id' => null,
            'subject_ulid' => null, 'classification' => 'internal', 'request_id' => null, 'context' => null];
        DB::connection('migrator')->table('audit_events')->insert(['id' => $id] + $row + ['row_hash' => $this->app->make(AuditHasher::class)->rowHash($row)]);
    }
}
