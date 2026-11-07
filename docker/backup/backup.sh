#!/bin/bash
# Encrypted backup (P1-51, PO decisions O47 and O48; docs/10 §4). Runs in the `backup` tool service:
#   docker compose run --rm backup
# Writes backups/<UTC time>/ (renamed from .partial only when complete):
#   mysql.sql.age       mysqldump of the main database (read-only account, one consistent snapshot)
#   manifest.tsv.age    one line per object version: n, bucket, key, version ID, latest, last modified, SHA-256,
#                       size, lock mode, retain-until, legal hold (or a delete marker)
#   objects/<n>.age     the bytes of version n
#   backup.info         counts only, plaintext
# Everything is encrypted to BACKUP_AGE_RECIPIENT (a public key); this service cannot decrypt any of it.
set -euo pipefail
umask 077

: "${DB_DATABASE:?}" "${DB_READONLY_USERNAME:?}" "${DB_READONLY_PASSWORD:?}"
: "${S3_BACKUP_ACCESS_KEY:?}" "${S3_BACKUP_SECRET_KEY:?}" "${BACKUP_AGE_RECIPIENT:?}"
[[ $BACKUP_AGE_RECIPIENT =~ ^age1[02-9ac-hj-np-z]{58}$ ]] \
  || { echo "backup.sh: BACKUP_AGE_RECIPIENT is not an age public key (run scripts/ops/backup-keys.sh)" >&2; exit 1; }

BUCKETS=("${EVIDENCE_BUCKET:-evidence}" "${AUDIT_ANCHOR_BUCKET:-audit-anchor}")
. /opt/backup/lib.sh
enc() { age -r "$BACKUP_AGE_RECIPIENT" -o "$1"; }

stamp=$(date -u +%Y%m%dT%H%M%SZ)
out=/backups/$stamp.partial
mkdir -p "$out/objects"
trap 'rm -rf "$out" /work/obj /work/manifest.tsv' ERR

echo "=== MySQL: $DB_DATABASE"
MYSQL_PWD="$DB_READONLY_PASSWORD" mysqldump -h mysql -u "$DB_READONLY_USERNAME" --single-transaction --no-tablespaces \
  --set-gtid-purged=OFF --hex-blob --skip-dump-date "$DB_DATABASE" | enc "$out/mysql.sql.age"

n=0 versions=0 markers=0 bytes=0
: > /work/manifest.tsv
for bucket in "${BUCKETS[@]}"; do
  echo "=== Object store: $bucket"
  # Oldest first per key, so a restore recreates the versions in their original order.
  listing=$(s3 list-object-versions --bucket "$bucket" --output text \
    --query '[Versions[].[`v`,Key,VersionId,IsLatest,LastModified], DeleteMarkers[].[`d`,Key,VersionId,IsLatest,LastModified]][]' \
    | grep -v '^None$' | sort -t$'\t' -k2,2 -k5,5 || true)
  while IFS=$'\t' read -r kind key version latest modified; do
    [ -n "$kind" ] || continue
    [[ $key =~ ^[A-Za-z0-9][A-Za-z0-9/._-]*$ ]] || { echo "backup.sh: unexpected object key in $bucket" >&2; exit 1; }
    n=$((n + 1))
    if [ "$kind" = d ]; then
      printf '%s\t%s\t%s\t%s\t%s\t%s\tdelete-marker\t0\tNone\tNone\tNone\n' "$n" "$bucket" "$key" "$version" "$latest" "$modified" >> /work/manifest.tsv
      markers=$((markers + 1)); continue
    fi
    s3 get-object --bucket "$bucket" --key "$key" --version-id "$version" /work/obj >/dev/null
    sha=$(sha256sum /work/obj | cut -d' ' -f1); size=$(stat -c %s /work/obj)
    retention=$(lock_field retention "$bucket" "$key" "$version" 'Retention.[Mode,RetainUntilDate]' $'None\tNone')
    hold=$(lock_field legal-hold "$bucket" "$key" "$version" 'LegalHold.Status' None)
    enc "$out/objects/$n.age" < /work/obj; rm -f /work/obj
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$n" "$bucket" "$key" "$version" "$latest" "$modified" "$sha" "$size" "$retention" "$hold" >> /work/manifest.tsv
    versions=$((versions + 1)); bytes=$((bytes + size))
  done <<< "$listing"
done
enc "$out/manifest.tsv.age" < /work/manifest.tsv; rm -f /work/manifest.tsv

printf 'created_at=%s\ndatabase=%s\nbuckets=%s\nobject_versions=%s\ndelete_markers=%s\nobject_bytes=%s\n' \
  "$stamp" "$DB_DATABASE" "${BUCKETS[*]}" "$versions" "$markers" "$bytes" > "$out/backup.info"
mv "$out" "/backups/$stamp"
echo "Backup complete: backups/$stamp ($versions object versions, $markers delete markers, $bytes bytes)"
