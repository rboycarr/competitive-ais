#!/usr/bin/env bash
# Create or update the env files from their examples (used by setup.sh and CI). Secrets are never printed.
#   .env                    Laravel runtime (web, Horizon, scheduler); readable inside the PHP containers
#   .secrets/migrator.env   migrator account: `migrator` service and MySQL init only (TASK-0003 #1)
#   .secrets/services.env   MySQL root and readonly passwords: MySQL only
#   .secrets/s3.env         object-store admin and audit-anchor keys: SeaweedFS and its init only
# .secrets/ is hidden inside every container that bind-mounts the repository (TASK-0004 N2); Compose reads
# the files on the host for `env_file`.
# Keeps existing values, migrates earlier layouts, appends keys new in the examples, and fills empty secrets
# with random values generated locally. Refuses to guess when an old and a new location disagree.
set -euo pipefail
ROOT=${INIT_ENV_ROOT:-$(git -C "$(dirname "$0")" rev-parse --show-toplevel)}   # set -e stops here if git cannot read the repo
cd "$ROOT"

declare -A EXAMPLE=(
  [.env]=.env.example
  [.secrets/migrator.env]=.env.migrator.example
  [.secrets/services.env]=.env.services.example
  [.secrets/s3.env]=.env.s3.example
)
FILES=(.env .secrets/migrator.env .secrets/services.env .secrets/s3.env)

mkdir -p .secrets && chmod 700 .secrets

# Earlier layouts kept these files at the repository root (TASK-0003 branch).
for pair in .env.migrator:.secrets/migrator.env .env.services:.secrets/services.env .env.s3:.secrets/s3.env; do
  old=${pair%%:*} new=${pair#*:}
  if [ -f "$old" ]; then
    if [ -f "$new" ]; then echo "Both $old and $new exist; merge them by hand, then delete $old." >&2; exit 1; fi
    mv "$old" "$new"; echo "  moved $old -> $new"
  fi
done

# Append keys that exist in the example but not yet in the env file (new settings after a pull).
add_missing_keys() {
  local file=$1 example=$2 line
  while IFS= read -r line; do
    [[ $line =~ ^([A-Z0-9_]+)= ]] || continue
    if ! grep -q "^${BASH_REMATCH[1]}=" "$file"; then
      printf '%s\n' "$line" >> "$file"
      echo "  ${file}: added ${BASH_REMATCH[1]}"
    fi
  done < "$example"
}

# Move KEY=value lines from one env file to another (earlier layouts). An empty old value is dropped; two
# different non-empty values stop the script, because either could be the one the services use.
move_keys() {
  local from=$1 to=$2 key old new; shift 2
  for key in "$@"; do
    grep -q "^${key}=" "$from" || continue
    old=$(grep -m1 "^${key}=" "$from" | cut -d= -f2-)
    new=$(grep -m1 "^${key}=" "$to" 2>/dev/null | cut -d= -f2- || true)
    if [ -n "$old" ] && [ -n "$new" ] && [ "$old" != "$new" ]; then
      echo "${key} has different values in ${from} and ${to}; keep the one in use and delete the other." >&2
      exit 1
    fi
    if [ -n "$old" ] && [ -z "$new" ]; then
      { grep -v "^${key}=" "$to" || true; printf '%s=%s\n' "$key" "$old"; } > "$to.tmp" && mv "$to.tmp" "$to"
      echo "  moved ${key}: ${from} -> ${to}"
    fi
    sed -i "/^${key}=/d" "$from"
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
  if [ ! -f "$f" ]; then cp "${EXAMPLE[$f]}" "$f"; echo "Created $f from ${EXAMPLE[$f]}"; fi
done

move_keys .env .secrets/migrator.env DB_MIGRATOR_USERNAME DB_MIGRATOR_PASSWORD
move_keys .secrets/services.env .secrets/s3.env S3_ADMIN_ACCESS_KEY S3_ADMIN_SECRET_KEY S3_ANCHOR_ACCESS_KEY S3_ANCHOR_SECRET_KEY

for f in "${FILES[@]}"; do add_missing_keys "$f" "${EXAMPLE[$f]}"; chmod 600 "$f"; done

sed -i "s/^DEV_UID=$/DEV_UID=$(id -u)/; s/^DEV_GID=$/DEV_GID=$(id -g)/" .env
fill_secrets .env DB_PASSWORD DB_RETENTION_PASSWORD REDIS_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
fill_secrets .secrets/migrator.env DB_MIGRATOR_PASSWORD
fill_secrets .secrets/services.env MYSQL_ROOT_PASSWORD DB_READONLY_PASSWORD
fill_secrets .secrets/s3.env S3_ADMIN_ACCESS_KEY S3_ADMIN_SECRET_KEY S3_ANCHOR_ACCESS_KEY S3_ANCHOR_SECRET_KEY
