#!/bin/bash
# Restore a backup (P1-51, PO decisions O46-O49). Runs in the `restore` service of docker/backup/compose.drill.yaml:
#   restore.sh <backup directory name> [--disaster-recovery]
# Refuses unless the target database is empty, and unless the Compose project is cais-drill, or --disaster-recovery
# is given with RESTORE_CONFIRM set to the project's name.
#  1. Loads the MySQL dump into the (empty) main database.
#  2. Replays every object version in its original order and checks each one's SHA-256 against the manifest. A
#     drill locks each restored version for one day in governance mode (O49); --disaster-recovery re-applies the
#     original mode, retain-until (if still in the future) and legal hold instead.
#  3. Points evidence_blobs.object_version_id at the restored versions (a restore creates new version IDs).
#  4. Writes /backups/<name>/drill/locks.tsv (bucket, key, original version, lock mode, retain-until, legal hold;
#     nothing secret) for the drill to compare against the source store.
set -euo pipefail
umask 077

name=${1:-}; mode=${2:-drill}
[[ $name =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || { echo "restore.sh: give a backup directory name, e.g. 20261108T060000Z" >&2; exit 2; }
dir=/backups/$name
[ -f "$dir/backup.info" ] || { echo "restore.sh: $dir is not a complete backup" >&2; exit 1; }
: "${RESTORE_PROJECT:?}" "${DB_DATABASE:?}" "${MYSQL_ROOT_PASSWORD:?}" "${BACKUP_AGE_IDENTITY:?}"
: "${S3_ADMIN_ACCESS_KEY:?}" "${S3_ADMIN_SECRET_KEY:?}"
case $mode in
  drill) [ "$RESTORE_PROJECT" = cais-drill ] || { echo "restore.sh: a drill restores only into the cais-drill project" >&2; exit 1; } ;;
  --disaster-recovery) [ "${RESTORE_CONFIRM:-}" = "$RESTORE_PROJECT" ] \
    || { echo "restore.sh: set RESTORE_CONFIRM=$RESTORE_PROJECT to restore into that project" >&2; exit 1; } ;;
  *) echo "restore.sh: unknown option $mode" >&2; exit 2 ;;
esac

export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
sql() { mysql -h mysql -uroot --batch --skip-column-names "$@"; }
tables=$(sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_DATABASE'")
[ "$tables" = 0 ] || { echo "restore.sh: $DB_DATABASE already has $tables tables; restore only into an empty database" >&2; exit 1; }

printf '%s\n' "$BACKUP_AGE_IDENTITY" > /work/identity
dec() { age -d -i /work/identity "$1"; }
export AWS_ACCESS_KEY_ID="$S3_ADMIN_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$S3_ADMIN_SECRET_KEY"
export AWS_DEFAULT_REGION=us-east-1 AWS_EC2_METADATA_DISABLED=true AWS_CONFIG_FILE=/work/aws-config
printf '[default]\nrequest_checksum_calculation = when_required\nresponse_checksum_validation = when_required\ns3 =\n    addressing_style = path\n' > "$AWS_CONFIG_FILE"
s3() { aws --endpoint-url "${S3_ENDPOINT:-http://seaweedfs:8333}" s3api "$@"; }

echo "=== MySQL: load the dump into $DB_DATABASE"
dec "$dir/mysql.sql.age" | sql "$DB_DATABASE"

echo "=== Object store: replay the versions"
dec "$dir/manifest.tsv.age" > /work/manifest.tsv
entries=$(wc -l < /work/manifest.tsv)
files=$(find "$dir/objects" -name '*.age' | wc -l)
expected=$(awk -F'\t' '$7 != "delete-marker"' /work/manifest.tsv | wc -l)
[ "$files" = "$expected" ] || { echo "restore.sh: the manifest lists $expected object versions but the backup holds $files" >&2; exit 1; }
mkdir -p "$dir/drill"
: > "$dir/drill/locks.tsv"; : > /work/versions.tsv
drill_until=$(date -u -d '+1 day' +%Y-%m-%dT%H:%M:%SZ)
now=$(date -u +%s)
i=0 restored=0
while IFS=$'\t' read -r n bucket key version latest modified sha size lmode luntil hold; do
  i=$((i + 1))
  [ "$n" = "$i" ] || { echo "restore.sh: manifest line $i is numbered $n" >&2; exit 1; }
  # Keys and version IDs go into SQL below: plain characters only.
  [[ $key =~ ^[A-Za-z0-9][A-Za-z0-9/._-]*$ && $version =~ ^[A-Za-z0-9._-]+$ ]] \
    || { echo "restore.sh: manifest line $i has an unexpected key or version ID" >&2; exit 1; }
  if [ "$sha" = delete-marker ]; then
    s3 delete-object --bucket "$bucket" --key "$key" >/dev/null
    continue
  fi
  [ -f "$dir/objects/$n.age" ] || { echo "restore.sh: object $n ($bucket/$key) is missing from the backup" >&2; exit 1; }
  dec "$dir/objects/$n.age" > /work/obj
  [ "$(sha256sum /work/obj | cut -d' ' -f1)" = "$sha" ] \
    || { echo "restore.sh: object $n ($bucket/$key) does not match its SHA-256 in the manifest" >&2; exit 1; }
  [ "$(stat -c %s /work/obj)" = "$size" ] || { echo "restore.sh: object $n ($bucket/$key) has the wrong size" >&2; exit 1; }
  lock=()
  if [ "$lmode" != None ]; then
    if [ "$mode" = drill ]; then
      lock=(--object-lock-mode GOVERNANCE --object-lock-retain-until-date "$drill_until")
    elif [ "$(date -u -d "$luntil" +%s)" -gt "$now" ]; then
      lock=(--object-lock-mode "$lmode" --object-lock-retain-until-date "$luntil")
    fi
  fi
  [ "$mode" != drill ] && [ "$hold" = ON ] && lock+=(--object-lock-legal-hold-status ON)
  new=$(s3 put-object --bucket "$bucket" --key "$key" --body /work/obj "${lock[@]}" --query VersionId --output text)
  rm -f /work/obj
  [[ $new =~ ^[A-Za-z0-9._-]+$ && $new != None ]] || { echo "restore.sh: the store returned no version ID for $bucket/$key" >&2; exit 1; }
  printf '%s\t%s\t%s\t%s\n' "$bucket" "$key" "$version" "$new" >> /work/versions.tsv
  restored=$((restored + 1))
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$bucket" "$key" "$version" "$lmode" "$luntil" "$hold" >> "$dir/drill/locks.tsv"
done < /work/manifest.tsv
[ "$restored" = "$expected" ] || { echo "restore.sh: restored $restored of $expected object versions" >&2; exit 1; }
echo "replayed $entries manifest entries ($restored object versions)"

echo "=== MySQL: point evidence at the restored versions"
{
  echo "CREATE TEMPORARY TABLE restored_versions (storage_key VARCHAR(512) NOT NULL, old_id VARCHAR(255) NOT NULL, new_id VARCHAR(255) NOT NULL, PRIMARY KEY (storage_key, old_id));"
  awk -F'\t' -v b="${EVIDENCE_BUCKET:-evidence}" '$1 == b { printf "INSERT INTO restored_versions VALUES (\x27%s\x27, \x27%s\x27, \x27%s\x27);\n", $2, $3, $4 }' /work/versions.tsv
  echo "SELECT COUNT(*) FROM evidence_blobs b LEFT JOIN restored_versions r ON r.storage_key = b.storage_key AND r.old_id = b.object_version_id WHERE b.object_version_id IS NOT NULL AND r.new_id IS NULL;"
  echo "UPDATE evidence_blobs b JOIN restored_versions r ON r.storage_key = b.storage_key AND r.old_id = b.object_version_id SET b.object_version_id = r.new_id;"
} > /work/remap.sql
missing=$(sql "$DB_DATABASE" < /work/remap.sql)
[ "$missing" = 0 ] || { echo "restore.sh: $missing evidence blobs reference a version that is not in the backup" >&2; exit 1; }
echo "Restore complete: $name into project $RESTORE_PROJECT ($mode)"
