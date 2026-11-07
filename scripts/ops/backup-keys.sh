#!/usr/bin/env bash
# Create the backup encryption key pair if there is none (P1-51, PO decision O47). Keys are never printed.
#   .secrets/backup.env   BACKUP_AGE_RECIPIENT  public key: backups are encrypted to it (backup service)
#   .secrets/restore.env  BACKUP_AGE_IDENTITY   private key: decrypts them (drill restore service only)
# With an identity and no recipient, the recipient is derived from the identity. With a recipient and no identity
# (production: the identity is kept offline), nothing changes. Needs the backup image (docker compose build backup).
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$ROOT"

val() { grep -m1 "^$1=" "$2" | cut -d= -f2- || true; }
set_val() {   # file key value: replace the KEY= line, keeping the file's mode
  { grep -v "^$2=" "$1" || true; printf '%s=%s\n' "$2" "$3"; } > "$1.tmp" && cat "$1.tmp" > "$1" && rm -f "$1.tmp"
}
age_tool() { docker run --rm -i --network none --entrypoint "$1" competitive-ais/backup:dev "${@:2}"; }

[ -f .secrets/backup.env ] && [ -f .secrets/restore.env ] || { echo "Run scripts/dev/init-env.sh first." >&2; exit 1; }
recipient=$(val BACKUP_AGE_RECIPIENT .secrets/backup.env)
identity=$(val BACKUP_AGE_IDENTITY .secrets/restore.env)

if [ -z "$identity" ] && [ -z "$recipient" ]; then
  identity=$(age_tool age-keygen 2>/dev/null | grep '^AGE-SECRET-KEY-1')
  set_val .secrets/restore.env BACKUP_AGE_IDENTITY "$identity"
  echo "  .secrets/restore.env: generated BACKUP_AGE_IDENTITY"
fi
if [ -n "$identity" ]; then
  derived=$(printf '%s\n' "$identity" | age_tool age-keygen -y)
  if [ -z "$recipient" ]; then
    set_val .secrets/backup.env BACKUP_AGE_RECIPIENT "$derived"
    echo "  .secrets/backup.env: set BACKUP_AGE_RECIPIENT"
  elif [ "$recipient" != "$derived" ]; then
    echo "BACKUP_AGE_RECIPIENT does not match BACKUP_AGE_IDENTITY; backups would not be restorable with this key." >&2
    exit 1
  fi
fi
echo "Backup keys ready."
