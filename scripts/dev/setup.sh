#!/usr/bin/env bash
# First-time (and repeatable) local setup for the Docker stack (P1-02, docs/10 §1).
# Run inside WSL from anywhere in the repo:  bash scripts/dev/setup.sh
# Idempotent: existing .env values, volumes, and data are kept. Secrets are generated locally and never printed.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

command -v docker >/dev/null || { echo "Docker is not available in this shell (docs/10 §1.1)." >&2; exit 1; }

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

for f in .env .env.services; do
  if [ ! -f "$f" ]; then cp "$f.example" "$f"; echo "Created $f from $f.example"; fi
  chmod 600 "$f"
done
fill_secrets .env DB_PASSWORD REDIS_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
fill_secrets .env.services MYSQL_ROOT_PASSWORD S3_ADMIN_ACCESS_KEY S3_ADMIN_SECRET_KEY S3_ANCHOR_ACCESS_KEY S3_ANCHOR_SECRET_KEY

echo "Building images"
DEV_UID=$(id -u) DEV_GID=$(id -g) docker compose build

echo "Installing PHP and Node dependencies (lock files)"
docker compose run --rm --no-deps php composer install --no-interaction
docker compose run --rm --no-deps node npm ci

if grep -q '^APP_KEY=$' .env; then
  docker compose run --rm --no-deps php php artisan key:generate --no-interaction
fi

echo "Starting the stack (the first ClamAV start downloads signatures and can take several minutes)"
docker compose up -d --wait --wait-timeout 1200

docker compose exec php php artisan migrate --no-interaction

cat <<'EOF'

Ready:
  App        http://localhost:8080
  Vite       http://localhost:5173
  Mailpit    http://localhost:8025
  Horizon    http://localhost:8080/horizon (local only)
Check the object-store key policy:  bash scripts/dev/verify-object-store.sh
Daily start:  docker compose up -d --wait     Stop:  docker compose stop
EOF
