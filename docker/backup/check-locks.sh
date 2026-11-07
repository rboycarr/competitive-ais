#!/bin/bash
# Restore drill, lock check (P1-51, PO decision O49). Runs in the `backup` service against the SOURCE store:
#   check-locks.sh <backup name>
# Reads backups/<name>/drill/locks.tsv (written by restore.sh from the decrypted manifest; no secrets) and, for every
# version, compares the recorded lock mode and retain-until with the source's current ones. The mode must be equal
# and the retain-until may only have grown (retention is extended, never shortened). A legal hold that changed since
# the backup is reported, not failed: holds are placed and released in normal operation.
set -euo pipefail
umask 077
name=${1:-}
[[ $name =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || { echo "check-locks.sh: give a backup name" >&2; exit 2; }
locks=/backups/$name/drill/locks.tsv
[ -f "$locks" ] || { echo "check-locks.sh: $locks is missing; run the restore first" >&2; exit 1; }
. /opt/backup/lib.sh

checked=0 locked=0 problems=0 holds=0
while IFS=$'\t' read -r bucket key version mode until hold; do
  [ -n "$bucket" ] || continue
  checked=$((checked + 1))
  current=$(lock_field retention "$bucket" "$key" "$version" 'Retention.[Mode,RetainUntilDate]' $'None\tNone') \
    || { echo "  $bucket/$key ($version): cannot read it in the source" >&2; problems=$((problems + 1)); continue; }
  cmode=${current%%$'\t'*} cuntil=${current#*$'\t'}
  if [ "$mode" != "$cmode" ]; then
    echo "  $bucket/$key ($version): lock mode $mode in the backup, $cmode in the source" >&2; problems=$((problems + 1))
  elif [ "$mode" != None ] && [ "$(date -u -d "$cuntil" +%s)" -lt "$(date -u -d "$until" +%s)" ]; then
    echo "  $bucket/$key ($version): retain-until $until in the backup is later than $cuntil in the source" >&2; problems=$((problems + 1))
  fi
  [ "$mode" != None ] && locked=$((locked + 1))
  chold=$(lock_field legal-hold "$bucket" "$key" "$version" 'LegalHold.Status' None) || chold=unreadable
  [ "$hold" = "$chold" ] || { echo "  note: $bucket/$key ($version): legal hold $hold in the backup, $chold now"; holds=$((holds + 1)); }
done < "$locks"

echo "Lock metadata: $checked versions checked ($locked locked); $problems mismatched; $holds legal holds changed since the backup."
[ "$checked" -gt 0 ] || { echo "check-locks.sh: no versions to check" >&2; exit 1; }
[ "$problems" = 0 ]
