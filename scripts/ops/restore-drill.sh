#!/usr/bin/env bash
# Restore drill (P1-51, PO decisions O46-O49; docs/10 §4). Restores a backup into a separate Compose project of its
# own, cais-drill-<UTC time>-<pid>, checks it, appends the result to backups/restore-drills.log and removes that project.
#   bash scripts/ops/restore-drill.sh [<backup name>] [--keep]
# Without a name it drills the newest complete backup. --keep leaves the drill project running for inspection; the
# script prints the command that removes it.
# Checks: dump and every object version restored (SHA-256 against the manifest); evidence pointed at the restored
# versions; the restored manifest's lock mode, retain-until and legal hold for every version match the source store
# (retention may only have grown since); account grants identical to the source; no pending migration; the app
# answers /up; feature flags valid; an evidence hash sample re-hashed from the restored store; the audit trail
# verified against the restored signed manifests.
# Only this run's project is ever created or removed: its name is new for every run, and the script refuses to start
# if anything already carries it (TASK-0099 #2, TASK-0100).
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$ROOT"

PROJECT=cais-drill-$(date -u +%Y%m%d%H%M%S)-$$
[ "$PROJECT" != "$(docker compose config --format json | jq -r .name)" ] || { echo "The drill project must not be the development project." >&2; exit 1; }
drill() { docker compose -p "$PROJECT" -f compose.yaml -f docker/backup/compose.drill.yaml "$@"; }
source_dc() { docker compose "$@"; }

name="" keep=0
for arg in "$@"; do
  case $arg in --keep) keep=1 ;; *) name=$arg ;; esac
done
if [ -z "$name" ]; then
  name=$(find backups -maxdepth 1 -type d -regextype posix-extended -regex 'backups/[0-9]{8}T[0-9]{6}Z' -printf '%f\n' 2>/dev/null | sort | tail -1)
  [ -n "$name" ] || { echo "No complete backup in backups/; run: docker compose run --rm backup" >&2; exit 1; }
fi
[[ $name =~ ^[0-9]{8}T[0-9]{6}Z$ ]] && [ -f "backups/$name/backup.info" ] || { echo "backups/$name is not a complete backup." >&2; exit 1; }

# One drill at a time (TASK-0099 #7).
exec 9> backups/.restore-drill.lock
flock -n 9 || { echo "Another restore drill is running." >&2; exit 1; }

label="label=com.docker.compose.project=$PROJECT"
[ -z "$(docker ps -aq --filter "$label")$(docker volume ls -q --filter "$label")$(docker network ls -q --filter "$label")" ] \
  || { echo "Something already uses the project name $PROJECT; nothing removed." >&2; exit 1; }

started=$(date -u +%s) step="start" result=FAIL
log_result() {
  local rc=$?
  [ $rc -eq 0 ] && result=PASS
  mkdir -p backups
  printf '%s\tbackup=%s\tresult=%s\tfailed_step=%s\tseconds=%s\tcommit=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$name" "$result" \
    "$([ "$result" = PASS ] && echo - || echo "$step")" "$(( $(date -u +%s) - started ))" "$(git rev-parse --short HEAD)" >> backups/restore-drills.log
  if [ $keep -eq 0 ]; then drill down -v >/dev/null 2>&1 || true
  else echo "Kept. Remove with: docker compose -p $PROJECT -f compose.yaml -f docker/backup/compose.drill.yaml down -v"; fi
  echo; echo "Restore drill $result (backup $name, project $PROJECT); logged in backups/restore-drills.log"
}
trap log_result EXIT
step() { step=$*; echo; echo "=== $*"; }

step "Fresh drill project ($PROJECT)"
drill up -d --wait mysql seaweedfs valkey mailpit
drill run --rm seaweedfs-init

step "Restore (dump, object versions, evidence version IDs)"
drill run --rm restore "$name"

step "Lock metadata matches the source store"
# The backup key reads retention and legal hold in the source; compare with what the backup recorded (O49).
source_dc run --rm --no-deps -T --entrypoint bash backup /opt/backup/check-locks.sh "$name"

step "Account grants match the source"
# Global grants and grants on the main database (the test database is not backed up).
grants() {
  "$@" exec -T mysql bash -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; for u in "$DB_MIGRATOR_USERNAME" "$CAIS_APP_USER" "$CAIS_RETENTION_USER" "$CAIS_READONLY_USER"; do mysql -uroot -N -e "SHOW GRANTS FOR \`$u\`@\`%\`" | grep -E "ON (\*\.\*|\`$MYSQL_DATABASE\`)" | sort; done'
}
drill exec -T mysql bash /docker-entrypoint-initdb.d/10-accounts.sh
drill run --rm -T migrator php artisan db:apply-grants --no-interaction
diff <(grants source_dc) <(grants drill) || { echo "Grants differ between the source and the restore (above)." >&2; exit 1; }

step "Migrations: nothing pending"
status=$(drill run --rm -T migrator php artisan migrate:status --no-interaction)
echo "$status" | tail -3
! grep -q Pending <<<"$status" || { echo "Pending migrations after the restore." >&2; exit 1; }

step "App answers /up; feature flags valid"
drill up -d --wait php nginx
drill exec -T nginx wget -q -O /dev/null http://127.0.0.1/up
drill run --rm -T php php artisan feature:status

step "Evidence hash sample from the restored store"
sample=${DRILL_EVIDENCE_SAMPLE:-25}
blobs=$(drill exec -T mysql bash -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -e "SELECT COUNT(*) FROM \`$MYSQL_DATABASE\`.evidence_blobs WHERE integrity_status = '"'ok'"'"')
out=$(drill run --rm -T php php artisan evidence:verify-integrity --limit="$sample") || { echo "$out"; exit 1; }
echo "$out"
want=$(( blobs < sample ? blobs : sample ))
grep -q "Blobs verified: $want;" <<<"$out" || { echo "Expected $want blobs re-hashed from the restored store." >&2; exit 1; }

step "Audit trail against the restored signed manifests"
drill run --rm -T auditor php artisan audit:checkpoint
drill run --rm -T auditor php artisan audit:verify
