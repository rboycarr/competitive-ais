#!/usr/bin/env bash
# Backup and restore drill in CI (P1-51; docs/09 item 21). Seeds synthetic evidence (two versions of one object) and
# signed audit rows, takes a backup, runs the drill (must pass), then checks that a damaged backup, a lock mismatch
# and a restore aimed at the development project all fail. Needs: the php and backup images, composer install,
# APP_KEY, scripts/ops/backup-keys.sh, and `docker compose up -d --wait mysql valkey seaweedfs mailpit`.
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$ROOT"
. scripts/ci/lib.sh
mask_secrets .env .secrets/*.env

fail=0
pass() { echo "ok: $*"; }
bad() { echo "FAIL: $*" >&2; fail=1; }
run() { docker compose run --rm -T "$@"; }
last_log() { tail -1 backups/restore-drills.log; }

echo "=== Seed (synthetic data only)"
run migrator php artisan migrate --force --no-interaction
run migrator php artisan db:apply-grants --no-interaction
run migrator php artisan feature:switch collection on --force
run migrator php artisan feature:switch collection off --force
run php php artisan tinker --execute '
  $disk = Illuminate\Support\Facades\Storage::disk("evidence");
  $until = Carbon\CarbonImmutable::now()->addDay();
  foreach (["alpha", "beta", "gamma"] as $word) {
      $bytes = "Synthetic restore-drill evidence: $word";
      $sha = hash("sha256", $bytes);
      $key = "sha256/".substr($sha, 0, 2)."/".substr($sha, 2, 2)."/".$sha;
      $version = Modules\Platform\Storage\LockedObjects::write($disk, $key, $bytes, "text/plain", $until);
      Illuminate\Support\Facades\DB::insert("INSERT INTO evidence_blobs (sha256, storage_disk, storage_key, object_version_id, size_bytes, detected_mime, first_scan_engine, first_scanned_at, retention_until, legal_hold, integrity_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)",
          [$sha, "evidence", $key, $version, strlen($bytes), "text/plain", "drill-seed", now(), $until, "ok", now()]);
      // A second, newer version of the first object: the row keeps pointing at the older one.
      if ($word === "alpha") { Modules\Platform\Storage\LockedObjects::write($disk, $key, $bytes, "text/plain", $until); }
  }
  echo "seeded 3 evidence blobs (4 object versions)\n";'
# Sign the seeded audit rows now rather than after the 15-minute lag window.
run auditor php artisan tinker --execute 'config(["audit.lag_minutes" => 0]); usleep(1100000); app(Modules\Platform\Audit\AuditAnchor::class)->checkpoint(); echo "checkpoint signed\n";'

echo "=== Backup"
run backup
name=$(find backups -maxdepth 1 -type d -regextype posix-extended -regex 'backups/[0-9]{8}T[0-9]{6}Z' -printf '%f\n' | sort | tail -1)
grep -q '^object_versions=[1-9]' "backups/$name/backup.info" && pass "backup $name written" || bad "backup holds no object versions"
if grep -rqa 'Synthetic restore-drill evidence' "backups/$name"; then bad "plaintext evidence in the backup"; else pass "backup encrypted"; fi

echo "=== Drill on the intact backup"
if out=$(bash scripts/ops/restore-drill.sh "$name" 2>&1); then pass "drill passed"; else bad "drill failed"; fi
echo "$out" | grep -E '^=== |Blobs verified|Lock metadata|Restore complete|matches its signed|Restore drill' || true
grep -q 'Blobs verified: 3;' <<<"$out" && pass "3 evidence blobs re-hashed through the restored version IDs" || bad "evidence sample"
grep -q 'matches its signed manifests' <<<"$out" \
  && pass "audit trail verified against the restored manifests" || bad "audit verification"

# A copy of the intact backup under another name, changed by the given command.
damaged() {
  local copy=$1; shift
  rm -rf "backups/$copy"; cp -r "backups/$name" "backups/$copy"; rm -rf "backups/$copy/drill"
  ( cd "backups/$copy" && "$@" )
}
expect_drill_fail() {   # backup name, expected failed step, label
  if bash scripts/ops/restore-drill.sh "$1" >/dev/null 2>&1; then bad "$3: the drill passed"
  elif last_log | grep -q "failed_step=$2"; then pass "$3: refused at '$2'"
  else bad "$3: failed elsewhere: $(last_log)"; fi
}

echo "=== Damaged backups must fail"
total=$(grep '^object_versions=' "backups/$name/backup.info" | cut -d= -f2)
damaged 20000101T000001Z rm objects/1.age
expect_drill_fail 20000101T000001Z "Restore (dump, object versions, evidence version IDs)" "a missing object version"
# Object 1 is evidence, the last one an audit manifest: valid age files with different bytes.
damaged 20000101T000002Z cp "objects/$total.age" objects/1.age
expect_drill_fail 20000101T000002Z "Restore (dump, object versions, evidence version IDs)" "swapped object bytes"
damaged 20000101T000003Z sh -c 'head -c 200 mysql.sql.age > t && mv t mysql.sql.age'
expect_drill_fail 20000101T000003Z "Restore (dump, object versions, evidence version IDs)" "a truncated database dump"

echo "=== Lock metadata that differs from the source must fail"
locks="backups/$name/drill/locks.tsv"
cp "$locks" "$locks.orig"
awk -F'\t' -v OFS='\t' 'NR == 1 { $4 = ($4 == "COMPLIANCE" ? "GOVERNANCE" : "COMPLIANCE") } 1' "$locks.orig" > "$locks"
if run --no-deps --entrypoint bash backup /opt/backup/check-locks.sh "$name" >/dev/null 2>&1; then bad "a changed lock mode passed"; else pass "a changed lock mode is reported"; fi
mv "$locks.orig" "$locks"

echo "=== The restore refuses the development project"
if out=$(docker compose -f compose.yaml -f docker/backup/compose.drill.yaml run --rm -T --no-deps restore "$name" 2>&1); then
  bad "restore ran against the development project"
elif grep -q 'restores only into the cais-drill project' <<<"$out"; then pass "restore refused outside cais-drill"
else bad "restore failed for another reason: $out"; fi

rm -rf backups/20000101T*
[ $fail -eq 0 ] && echo "Restore drill tests passed." || exit 1
