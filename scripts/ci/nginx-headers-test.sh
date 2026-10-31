#!/usr/bin/env bash
# nginx fallback headers (P1-08, TASK-0014 #2): responses nginx produces itself (static files, its own errors, PHP
# unreachable) carry the security headers and a request ID. Runs the real config with the pinned nginx image on
# a private network without PHP, so PHP requests end in nginx's own 502. Runnable locally:
#   bash scripts/ci/nginx-headers-test.sh
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)   # set -e stops here if git cannot read the repo
cd "$ROOT"

IMAGE=$(grep -m1 -oE 'nginx:[0-9][^ "]*' compose.yaml)
NAME=cais-nginx-headers-$$
NET=cais-nginx-headers-$$
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT

docker network create "$NET" >/dev/null
docker run -d --name "$NAME" --network "$NET" \
  -v "$PWD/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro" \
  -v "$PWD/public:/var/www/html/public:ro" "$IMAGE" >/dev/null
for _ in $(seq 1 20); do docker exec "$NAME" wget -q -O /dev/null http://127.0.0.1/nginx-health 2>/dev/null && break; sleep 0.5; done

failed=0
check() { # path expected-status
  local headers
  # Raw HTTP: BusyBox wget stops printing headers on error statuses. The sleep keeps the connection open while
  # nginx waits for the (missing) PHP upstream; BusyBox nc closes it at end of input otherwise (status 499).
  headers=$(docker exec "$NAME" sh -c "(printf 'GET $1 HTTP/1.0\r\nHost: localhost\r\n\r\n'; sleep 3) | nc 127.0.0.1 80" | sed '/^\r$/q' || true)
  for expected in "HTTP/1.1 $2" "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'" \
                  "X-Content-Type-Options: nosniff" "X-Frame-Options: DENY" "Referrer-Policy: strict-origin-when-cross-origin"; do
    grep -qF -- "$expected" <<<"$headers" || { echo "  $1: missing [$expected]" >&2; failed=1; }
  done
  grep -qE 'X-Request-Id: [0-9a-f]{32}' <<<"$headers" || { echo "  $1: missing X-Request-Id" >&2; failed=1; }
  [ "$(grep -c 'Content-Security-Policy' <<<"$headers")" -le 1 ] || { echo "  $1: more than one CSP" >&2; failed=1; }
}

check /robots.txt 200        # static file
check /.env 403              # nginx deny
check /other.php 404         # nginx 404
check /some/page 502         # PHP unreachable: nginx error page

[ "$failed" -eq 0 ] || { echo "nginx fallback headers test failed" >&2; exit 1; }
echo "nginx fallback headers test passed."
