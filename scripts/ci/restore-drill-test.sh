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
mkdir -p backups

# In GitHub Actions, a failure becomes a public annotation with the redacted tail of the output (as in backend.sh).
LOG=$(mktemp)
exec > >(tee "$LOG") 2>&1
annotate() {
  local rc=$?
  if [ $rc -ne 0 ] && [ -n "${GITHUB_ACTIONS:-}" ]; then
    echo "::error title=restore-drill::failed (exit $rc)%0A$(annotation_body "$LOG" .env .secrets/*.env)"
  fi
}
trap annotate EXIT

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
  // An object whose latest entry is a delete marker (no blob row): the restore must rebuild that history too.
  $gone = "drill/deleted-object";
  Modules\Platform\Storage\LockedObjects::write($disk, $gone, "Synthetic restore-drill evidence: removed", "text/plain", $until);
  $disk->delete($gone);
  echo "seeded 3 evidence blobs (5 object versions, 1 delete marker)\n";'
# Sign the seeded audit rows now rather than after the 15-minute lag window.
run auditor php artisan tinker --execute 'config(["audit.lag_minutes" => 0]); usleep(1100000); app(Modules\Platform\Audit\AuditAnchor::class)->checkpoint(); echo "checkpoint signed\n";'

echo "=== Backup"
run backup
name=$(find backups -maxdepth 1 -type d -regextype posix-extended -regex 'backups/[0-9]{8}T[0-9]{6}Z' -printf '%f\n' | sort | tail -1)
grep -q '^object_versions=[1-9]' "backups/$name/backup.info" && pass "backup $name written" || bad "backup holds no object versions"
if grep -rqa 'Synthetic restore-drill evidence' "backups/$name"; then bad "plaintext evidence in the backup"; else pass "backup encrypted"; fi

echo "=== Drill on the intact backup"
if out=$(bash scripts/ops/restore-drill.sh "$name" 2>&1); then pass "drill passed"; else bad "drill failed: $(last_log)"; echo "$out" | tail -25; fi
echo "$out" | grep -E '^=== |Blobs verified|Lock metadata|Restore complete|matches its signed|Restore drill' || true
grep -q 'Blobs verified: 3;' <<<"$out" && pass "3 evidence blobs re-hashed through the restored version IDs" || bad "evidence sample"
grep -q '^delete_markers=1$' "backups/$name/backup.info" && grep -q 'version history matches the manifest' <<<"$out" \
  && pass "delete marker backed up and the restored history matches" || bad "delete-marker round trip"
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
damaged 20000101T000004Z cp objects/1.age objects/999.age
expect_drill_fail 20000101T000004Z "Restore (dump, object versions, evidence version IDs)" "an extra object file"

echo "=== A failed object listing leaves no complete backup (TASK-0099 #1)"
before=$(find backups -maxdepth 1 -type d -regextype posix-extended -regex 'backups/[0-9]{8}T[0-9]{6}Z' | wc -l)
if run -e S3_ENDPOINT=http://seaweedfs:1 backup >/dev/null 2>&1; then bad "backup succeeded without the object store"
elif [ "$(find backups -maxdepth 1 -type d -regextype posix-extended -regex 'backups/[0-9]{8}T[0-9]{6}Z' | wc -l)" = "$before" ]; then
  pass "backup failed and wrote no complete backup"
else bad "a failed backup left a complete-looking directory"; fi

echo "=== The backup key reads and lists only (TASK-0099 #5)"
probe() { run --no-deps --entrypoint bash backup -c ". /opt/backup/lib.sh; $1" >/dev/null 2>&1; }
probe 's3 list-object-versions --bucket evidence --max-items 1' && pass "backup key lists evidence" || bad "backup key cannot list evidence"
probe 's3 put-object --bucket evidence --key drill/probe --body /etc/hostname' && bad "backup key wrote to evidence" || pass "backup key cannot write"
probe 's3 delete-object --bucket audit-anchor --key drill/probe' && bad "backup key deleted in audit-anchor" || pass "backup key cannot delete"
probe 's3 list-objects-v2 --bucket exports' && bad "backup key lists exports" || pass "backup key cannot list exports"
probe 's3 list-objects-v2 --bucket evidence-quarantine' && bad "backup key lists the quarantine" || pass "backup key cannot list the quarantine"

echo "=== Another project's resources are never removed (TASK-0099 #2, TASK-0100)"
# A project named like an older drill, with a container, a network and a volume: a full drill run must leave it.
docker volume create --label com.docker.compose.project=cais-drill not-ours-volume >/dev/null
docker network create --label com.docker.compose.project=cais-drill not-ours-network >/dev/null
docker create --name not-ours-container --label com.docker.compose.project=cais-drill competitive-ais/backup:dev true >/dev/null
bash scripts/ops/restore-drill.sh "$name" >/dev/null 2>&1 || bad "drill failed beside another project: $(last_log)"
docker volume inspect not-ours-volume >/dev/null 2>&1 && docker network inspect not-ours-network >/dev/null 2>&1 \
  && docker inspect not-ours-container >/dev/null 2>&1 && pass "the other project's container, network and volume are untouched" \
  || bad "the drill removed another project's resources"
docker rm not-ours-container >/dev/null; docker network rm not-ours-network >/dev/null; docker volume rm not-ours-volume >/dev/null
[ -z "$(docker ps -aq --filter 'label=com.docker.compose.project' --filter 'name=cais-drill-')" ] \
  && pass "no drill project left behind" || bad "a drill project was left behind"

echo "=== Lock metadata that differs from the source must fail"
locks="backups/$name/drill/locks.tsv"
cp "$locks" "$locks.orig"
awk -F'\t' -v OFS='\t' 'NR == 1 { $4 = ($4 == "COMPLIANCE" ? "GOVERNANCE" : "COMPLIANCE") } 1' "$locks.orig" > "$locks"
if run --no-deps --entrypoint bash backup /opt/backup/check-locks.sh "$name" >/dev/null 2>&1; then bad "a changed lock mode passed"; else pass "a changed lock mode is reported"; fi
mv "$locks.orig" "$locks"

echo "=== The restore refuses the development project"
if out=$(docker compose -f compose.yaml -f docker/backup/compose.drill.yaml run --rm -T --no-deps restore "$name" 2>&1); then
  bad "restore ran against the development project"
elif grep -q 'restores only into a cais-drill-<run> project' <<<"$out"; then pass "restore refused outside a drill project"
else bad "restore failed for another reason: $out"; fi

rm -rf backups/20000101T*
[ $fail -eq 0 ] && echo "Restore drill tests passed." || exit 1
