#!/usr/bin/env bash
# Create or update the env files from their examples (used by setup.sh and CI). Secrets are never printed.
#   .env           Laravel runtime (web, Horizon, scheduler)
#   .env.migrator  migrator account: `migrator` service and MySQL init only (TASK-0003 #1)
#   .env.services  MySQL root and readonly passwords: MySQL only
#   .env.s3        object-store admin and audit-anchor keys: SeaweedFS and its init only (TASK-0003 #7)
# Keeps existing values, moves keys that older layouts kept elsewhere, appends keys that are new in the
# examples, and fills every empty secret with a random value generated locally.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

FILES=(.env .env.migrator .env.services .env.s3)

# Append keys that exist in the example but not yet in the env file (new settings after a pull).
add_missing_keys() {
  local file=$1 line
  while IFS= read -r line; do
    [[ $line =~ ^([A-Z0-9_]+)= ]] || continue
    if ! grep -q "^${BASH_REMATCH[1]}=" "$file"; then
      printf '%s\n' "$line" >> "$file"
      echo "  ${file}: added ${BASH_REMATCH[1]}"
    fi
  done < "$file.example"
}

# Move KEY=value lines from one env file to another (earlier layouts), keeping the value.
move_keys() {
  local from=$1 to=$2 key; shift 2
  for key in "$@"; do
    if grep -q "^${key}=" "$from"; then
      grep -v "^${key}=" "$to" > "$to.tmp" || true
      grep "^${key}=" "$from" >> "$to.tmp"
      mv "$to.tmp" "$to"
      sed -i "/^${key}=/d" "$from"
      chmod 600 "$to"
      echo "  moved ${key}: ${from} -> ${to}"
    fi
  done
}

# Fill KEY= lines that are empty with a random hex value.
fill_secrets() {
  local file=$1; shift
  for key in "$@"; do
    if grep -q "^${key}=$" "$file"; then
      sed -i "s/^${key}=$/${key}=$(openssl rand -hex 24)/" "$file"
      echo "  ${file}: generated ${key}"
    fi
  done
}

for f in "${FILES[@]}"; do
  if [ ! -f "$f" ]; then cp "$f.example" "$f"; echo "Created $f from $f.example"; fi
  chmod 600 "$f"
done

move_keys .env .env.migrator DB_MIGRATOR_USERNAME DB_MIGRATOR_PASSWORD
move_keys .env.services .env.s3 S3_ADMIN_ACCESS_KEY S3_ADMIN_SECRET_KEY S3_ANCHOR_ACCESS_KEY S3_ANCHOR_SECRET_KEY

for f in "${FILES[@]}"; do add_missing_keys "$f"; done

sed -i "s/^DEV_UID=$/DEV_UID=$(id -u)/; s/^DEV_GID=$/DEV_GID=$(id -g)/" .env
fill_secrets .env DB_PASSWORD DB_RETENTION_PASSWORD REDIS_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
fill_secrets .env.migrator DB_MIGRATOR_PASSWORD
fill_secrets .env.services MYSQL_ROOT_PASSWORD DB_READONLY_PASSWORD
fill_secrets .env.s3 S3_ADMIN_ACCESS_KEY S3_ADMIN_SECRET_KEY S3_ANCHOR_ACCESS_KEY S3_ANCHOR_SECRET_KEY
