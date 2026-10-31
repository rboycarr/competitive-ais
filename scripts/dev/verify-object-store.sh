#!/usr/bin/env bash
# Verifies the per-purpose object-store keys against the running stack (ADR-0007 amendment, P1-02 acceptance).
# Run inside WSL after `docker compose up`:  bash scripts/dev/verify-object-store.sh
# Test objects live under _verify/ and are removed at the end with the admin key. Keys are never printed.
set -uo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

CLI_IMAGE=amazon/aws-cli:2.39.3@sha256:d557b3524475d70dd3104cf44d96568ee3188897b73a6ece2b37aa50e1af362f
NET=competitive-ais_data
EP=http://seaweedfs:8333

WORK=$(mktemp -d); KEYS=$(mktemp -d); CLI=cais-verify-cli
cleanup() { docker rm -f "$CLI" >/dev/null 2>&1; rm -rf "$WORK" "$KEYS"; }
trap cleanup EXIT

# One env file per identity (mode 600, not mounted), passed with `docker exec --env-file`, so keys never appear in argv.
envval() { grep -m1 "^$2=" "$1" | cut -d= -f2-; }
umask 077
printf 'AWS_ACCESS_KEY_ID=%s\nAWS_SECRET_ACCESS_KEY=%s\n' "$(envval .env AWS_ACCESS_KEY_ID)" "$(envval .env AWS_SECRET_ACCESS_KEY)" > "$KEYS/app"
printf 'AWS_ACCESS_KEY_ID=%s\nAWS_SECRET_ACCESS_KEY=%s\n' "$(envval .env.s3 S3_ADMIN_ACCESS_KEY)" "$(envval .env.s3 S3_ADMIN_SECRET_KEY)" > "$KEYS/admin"
printf 'AWS_ACCESS_KEY_ID=%s\nAWS_SECRET_ACCESS_KEY=%s\n' "$(envval .env.s3 S3_ANCHOR_ACCESS_KEY)" "$(envval .env.s3 S3_ANCHOR_SECRET_KEY)" > "$KEYS/anchor"
umask 022
printf 'verify\n' > "$WORK/obj.txt"
cat > "$WORK/config" <<'EOF'
[default]
region = us-east-1
request_checksum_calculation = when_required
response_checksum_validation = when_required
s3 =
    addressing_style = path
EOF
chmod 644 "$WORK"/*

docker run -d --name "$CLI" --network "$NET" -v "$WORK:/cfg:ro" -e AWS_CONFIG_FILE=/cfg/config \
  -e AWS_EC2_METADATA_DISABLED=true --entrypoint sleep "$CLI_IMAGE" infinity >/dev/null || exit 1

PASSES=0; FAILS=0
rec() { printf '%-4s %-4s %s\n' "$1" "$2" "$3"; [ "$2" = PASS ] && PASSES=$((PASSES+1)) || FAILS=$((FAILS+1)); }
# as <app|anchor|admin|anon> s3api args...
as() {
  local who=$1; shift
  if [ "$who" = anon ]; then
    docker exec "$CLI" aws --endpoint-url "$EP" --no-sign-request s3api "$@"
  else
    docker exec --env-file "$KEYS/$who" "$CLI" aws --endpoint-url "$EP" s3api "$@"
  fi
}
ok() { local id=$1 d=$2 out; shift 2; if out=$("$@" 2>&1); then rec "$id" PASS "$d"; else rec "$id" FAIL "$d :: $(tail -1 <<<"$out")"; fi; }
no() { local id=$1 d=$2 out; shift 2; if out=$("$@" 2>&1); then rec "$id" FAIL "$d :: unexpectedly succeeded"; else rec "$id" PASS "$d :: $(grep -oE '\(([A-Za-z]+)\)' <<<"$out" | head -1)"; fi; }
put() { local who=$1 b=$2 k=$3; shift 3; as "$who" put-object --bucket "$b" --key "$k" --body /cfg/obj.txt "$@" --query VersionId --output text; }

UNTIL=$(date -u -d '+1 day' +%Y-%m-%dT%H:%M:%SZ)
K=_verify/$(date -u +%Y%m%dT%H%M%SZ)

# Application key
quarantine_roundtrip() { put app evidence-quarantine "$K/q" >/dev/null && as app get-object --bucket evidence-quarantine --key "$K/q" /tmp/o >/dev/null; }
ok  K01 "app: put + get in evidence-quarantine"          quarantine_roundtrip
G=$(put app evidence "$K/g" --object-lock-mode GOVERNANCE --object-lock-retain-until-date "$UNTIL" 2>/dev/null)
if [ -n "$G" ] && [ "$G" != None ]; then
  rec K02 PASS "app: put governance-locked object in evidence"
  no  K03 "app: delete locked version denied"             as app delete-object --bucket evidence --key "$K/g" --version-id "$G"
  no  K04 "app: delete locked version with bypass denied" as app delete-object --bucket evidence --key "$K/g" --version-id "$G" --bypass-governance-retention
  no  K19 "app: shorten governance retention denied"      as app put-object-retention --bucket evidence --key "$K/g" --version-id "$G" --retention "Mode=GOVERNANCE,RetainUntilDate=$(date -u -d '+1 hour' +%Y-%m-%dT%H:%M:%SZ)"
  ok  K05 "app: set legal hold ON"                        as app put-object-legal-hold --bucket evidence --key "$K/g" --version-id "$G" --legal-hold Status=ON
  ok  K06 "app: set legal hold OFF"                       as app put-object-legal-hold --bucket evidence --key "$K/g" --version-id "$G" --legal-hold Status=OFF
else
  rec K02 FAIL "app: put governance-locked object in evidence (K03-K06, K19 not run)"
fi
ok  K07 "app: put in exports"                             as app put-object --bucket exports --key "$K/e" --body /cfg/obj.txt
no  K08 "app: list audit-anchor denied"                   as app list-objects-v2 --bucket audit-anchor
no  K09 "app: put in audit-anchor denied"                 as app put-object --bucket audit-anchor --key "$K/a" --body /cfg/obj.txt
no  K10 "app: create bucket denied"                       as app create-bucket --bucket cais-verify-new
no  K11 "app: delete bucket denied"                       as app delete-bucket --bucket exports
no  K12 "app: change bucket object-lock config denied"    as app put-object-lock-configuration --bucket evidence --object-lock-configuration '{"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"GOVERNANCE","Days":1}}}'

# Audit-anchor key
A=$(put anchor audit-anchor "$K/m" 2>/dev/null)
[ -n "$A" ] && rec K13 PASS "anchor: put in audit-anchor" || rec K13 FAIL "anchor: put in audit-anchor"
no  K14 "anchor: list evidence denied"                    as anchor list-objects-v2 --bucket evidence
no  K15 "anchor: put in evidence denied"                  as anchor put-object --bucket evidence --key "$K/x" --body /cfg/obj.txt

# No key
no  K16 "anonymous: list evidence denied"                 as anon list-objects-v2 --bucket evidence
no  K17 "anonymous: get object denied"                    as anon get-object --bucket evidence --key "$K/g" /tmp/o

# Admin key (kept out of the app) can bypass governance; used here to clean up.
ok  K18 "admin: delete locked version with bypass"        as admin delete-object --bucket evidence --key "$K/g" --version-id "$G" --bypass-governance-retention

# Cleanup of remaining test objects (all versions and delete markers under the run prefix).
for b in evidence-quarantine evidence exports audit-anchor; do
  as admin list-object-versions --bucket "$b" --prefix "$K/" --query '[Versions,DeleteMarkers][][].[Key,VersionId]' --output text 2>/dev/null \
  | while read -r key ver; do
      [ -n "${key:-}" ] && [ "$key" != None ] && as admin delete-object --bucket "$b" --key "$key" --version-id "$ver" --bypass-governance-retention >/dev/null 2>&1
    done
done

echo "== object-store key policy: $PASSES passed, $FAILS failed"
[ "$FAILS" -eq 0 ]
