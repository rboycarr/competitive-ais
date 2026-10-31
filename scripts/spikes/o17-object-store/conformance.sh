#!/usr/bin/env bash
# O17 spike: S3 versioning / object-lock conformance for MinIO replacements (docs/11 §5, ADR-0007).
# Each candidate runs alone on an internal Docker network (no internet egress) with throwaway
# credentials and a throwaway volume; everything is removed on exit.
# Usage (inside WSL): bash conformance.sh [rustfs|seaweedfs ...] | tee results.txt
set -uo pipefail

RUSTFS_IMAGE=rustfs/rustfs:1.0.4
SEAWEED_IMAGE=chrislusf/seaweedfs:4.51
CLI_IMAGE=amazon/aws-cli:2.39.3
NET=o17-net
TARGETS=("$@"); [ ${#TARGETS[@]} -eq 0 ] && TARGETS=(rustfs seaweedfs)

WORK=$(mktemp -d)
AK=$(openssl rand -hex 10)
SK=$(openssl rand -hex 20)
UNTIL=$(date -u -d '+1 day' +%Y-%m-%dT%H:%M:%SZ)
SOONER=$(date -u -d '+2 hours' +%Y-%m-%dT%H:%M:%SZ)
LATER=$(date -u -d '+2 days' +%Y-%m-%dT%H:%M:%SZ)

teardown() {
  docker rm -f o17-cli o17-target >/dev/null 2>&1
  docker volume rm -f o17-data >/dev/null 2>&1
}
cleanup() { teardown; docker network rm "$NET" >/dev/null 2>&1; rm -rf "$WORK"; }
trap cleanup EXIT

printf 'v1\n' > "$WORK/v1.txt"
printf 'v2\n' > "$WORK/v2.txt"
cat > "$WORK/config" <<'EOF'
[default]
region = us-east-1
request_checksum_calculation = when_required
response_checksum_validation = when_required
s3 =
    addressing_style = path
EOF
cat > "$WORK/s3.json" <<EOF
{"identities":[{"name":"admin","credentials":[{"accessKey":"$AK","secretKey":"$SK"}],
  "actions":["Admin","Read","Write","List","Tagging"]}]}
EOF
chmod 644 "$WORK"/*

docker network create --internal "$NET" >/dev/null

# ---------- helpers ----------
TARGET=""; EP=""; PASSES=0; FAILS=0
rec() { printf '%-9s %-4s %-4s %s\n' "$TARGET" "$1" "$2" "$3"; [ "$2" = PASS ] && PASSES=$((PASSES+1)) || FAILS=$((FAILS+1)); }
s3() { docker exec o17-cli aws --endpoint-url "$EP" s3api "$@"; }
cli() { docker exec o17-cli "$@"; }
# expect success
ok() { local id=$1 d=$2 out; shift 2; if out=$("$@" 2>&1); then rec "$id" PASS "$d"; else rec "$id" FAIL "$d :: $(tail -1 <<<"$out")"; fi; }
# expect failure (and report the error code)
no() { local id=$1 d=$2 out; shift 2; if out=$("$@" 2>&1); then rec "$id" FAIL "$d :: unexpectedly succeeded"; else rec "$id" PASS "$d :: $(grep -oE '\(([A-Za-z]+)\)|error: \([0-9]+\)|returned error: [0-9]+' <<<"$out" | head -1)"; fi; }
# expect output equal to a value
eq() { local id=$1 d=$2 want=$3 got; shift 3; got=$("$@" 2>&1); if [ "$got" = "$want" ]; then rec "$id" PASS "$d"; else rec "$id" FAIL "$d :: want '$want' got '$(tail -1 <<<"$got")'"; fi; }
put() { local b=$1 k=$2 f=$3; shift 3; s3 put-object --bucket "$b" --key "$k" --body "/cfg/$f" "$@" --query VersionId --output text; }
getv() { s3 get-object --bucket "$1" --key "$2" --version-id "$3" /tmp/o >/dev/null && cli cat /tmp/o; }
exists() { s3 head-object --bucket "$1" --key "$2" --version-id "$3" >/dev/null; }
wait_ready() {
  for _ in $(seq 1 90); do s3 list-buckets >/dev/null 2>&1 && return 0; sleep 1; done
  echo "$TARGET did not become ready"; docker logs --tail 30 o17-target; return 1
}

start_target() {
  docker volume create o17-data >/dev/null
  case "$TARGET" in
    rustfs)
      docker run -d --name o17-target --network "$NET" -v o17-data:/data \
        -e RUSTFS_ACCESS_KEY="$AK" -e RUSTFS_SECRET_KEY="$SK" "$RUSTFS_IMAGE" >/dev/null
      EP=http://o17-target:9000 ;;
    seaweedfs)
      docker run -d --name o17-target --network "$NET" -v o17-data:/data -v "$WORK/s3.json:/etc/seaweedfs/s3.json:ro" \
        "$SEAWEED_IMAGE" mini -dir=/data -s3.config=/etc/seaweedfs/s3.json -master.telemetry=false >/dev/null
      EP=http://o17-target:8333 ;;
    *) echo "unknown target $TARGET"; return 1 ;;
  esac
  docker run -d --name o17-cli --network "$NET" -v "$WORK:/cfg:ro" \
    -e AWS_CONFIG_FILE=/cfg/config -e AWS_ACCESS_KEY_ID="$AK" -e AWS_SECRET_ACCESS_KEY="$SK" \
    -e AWS_EC2_METADATA_DISABLED=true --entrypoint sleep "$CLI_IMAGE" infinity >/dev/null
  wait_ready
}

suite() {
  # Bucket setup and versioning
  ok T01 "create bucket with object lock enabled" s3 create-bucket --bucket lock --object-lock-enabled-for-bucket
  eq T02 "object lock configuration reports Enabled" Enabled s3 get-object-lock-configuration --bucket lock --query ObjectLockConfiguration.ObjectLockEnabled --output text
  eq T03 "versioning auto-enabled on lock bucket" Enabled s3 get-bucket-versioning --bucket lock --query Status --output text
  s3 create-bucket --bucket ver >/dev/null 2>&1
  ok T04 "enable versioning on a plain bucket" s3 put-bucket-versioning --bucket ver --versioning-configuration Status=Enabled
  local V1; V1=$(put ver k v1.txt 2>/dev/null); put ver k v2.txt >/dev/null 2>&1
  eq T05 "two puts keep two versions" 2 s3 list-object-versions --bucket ver --prefix k --query 'length(Versions)' --output text
  eq T06 "old version readable by version id" v1 getv ver k "$V1"
  ok T07 "delete without version id (delete marker)" s3 delete-object --bucket ver --key k
  eq T08 "old version still readable after delete marker" v1 getv ver k "$V1"

  # Governance mode
  local G; G=$(put lock gov v1.txt --object-lock-mode GOVERNANCE --object-lock-retain-until-date "$UNTIL" 2>/dev/null)
  no T09 "delete governance-locked version denied" s3 delete-object --bucket lock --key gov --version-id "$G"
  ok T10 "governance-locked version still exists" exists lock gov "$G"
  ok T11 "root can bypass governance (app keys must lack s3:BypassGovernanceRetention)" s3 delete-object --bucket lock --key gov --version-id "$G" --bypass-governance-retention

  # Compliance mode
  local C; C=$(put lock comp v1.txt --object-lock-mode COMPLIANCE --object-lock-retain-until-date "$UNTIL" 2>/dev/null)
  eq T12 "head shows COMPLIANCE mode" COMPLIANCE s3 head-object --bucket lock --key comp --version-id "$C" --query ObjectLockMode --output text
  no T13 "delete compliance-locked version denied" s3 delete-object --bucket lock --key comp --version-id "$C"
  no T14 "delete compliance-locked version with bypass denied" s3 delete-object --bucket lock --key comp --version-id "$C" --bypass-governance-retention
  no T15 "shorten compliance retention denied" s3 put-object-retention --bucket lock --key comp --version-id "$C" --retention "Mode=COMPLIANCE,RetainUntilDate=$SOONER"
  no T16 "downgrade compliance to governance denied" s3 put-object-retention --bucket lock --key comp --version-id "$C" --retention "Mode=GOVERNANCE,RetainUntilDate=$UNTIL" --bypass-governance-retention
  ok T17 "extend compliance retention allowed" s3 put-object-retention --bucket lock --key comp --version-id "$C" --retention "Mode=COMPLIANCE,RetainUntilDate=$LATER"
  put lock comp v2.txt >/dev/null 2>&1
  eq T18 "locked version unchanged after overwrite" v1 getv lock comp "$C"
  ok T19 "compliance-locked version still exists" exists lock comp "$C"

  # Legal hold
  local L; L=$(put lock hold v1.txt 2>/dev/null)
  ok T20 "set legal hold ON" s3 put-object-legal-hold --bucket lock --key hold --version-id "$L" --legal-hold Status=ON
  no T21 "delete version under legal hold denied" s3 delete-object --bucket lock --key hold --version-id "$L"
  ok T22 "set legal hold OFF" s3 put-object-legal-hold --bucket lock --key hold --version-id "$L" --legal-hold Status=OFF
  ok T23 "delete version after hold released" s3 delete-object --bucket lock --key hold --version-id "$L"

  # Bucket default retention
  s3 create-bucket --bucket lockdef --object-lock-enabled-for-bucket >/dev/null 2>&1
  ok T24 "set bucket default retention (COMPLIANCE 1 day)" s3 put-object-lock-configuration --bucket lockdef --object-lock-configuration '{"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"COMPLIANCE","Days":1}}}'
  local D; D=$(put lockdef d v1.txt 2>/dev/null)
  eq T25 "default retention applied to new object" COMPLIANCE s3 head-object --bucket lockdef --key d --version-id "$D" --query ObjectLockMode --output text
  no T26 "delete default-retained version denied" s3 delete-object --bucket lockdef --key d --version-id "$D"

  # Integrity and access
  local SUM; SUM=$(openssl dgst -sha256 -binary "$WORK/v1.txt" | base64)
  s3 put-object --bucket ver --key sum --body /cfg/v1.txt --checksum-algorithm SHA256 >/dev/null 2>&1
  eq T27 "server stores and returns SHA-256 checksum" "$SUM" s3 head-object --bucket ver --key sum --checksum-mode ENABLED --query ChecksumSHA256 --output text
  local U; U=$(docker exec o17-cli aws --endpoint-url "$EP" s3 presign s3://lock/comp --expires-in 120)
  eq T28 "pre-signed GET returns the object" v2 cli curl -fsS "$U"
  local last=${U: -1}; local T=${U%?}$([ "$last" = 0 ] && echo 1 || echo 0)
  no T29 "tampered pre-signed URL rejected" cli curl -fsS "$T"
  local X; X=$(docker exec o17-cli aws --endpoint-url "$EP" s3 presign s3://lock/comp --expires-in 1); sleep 3
  no T30 "expired pre-signed URL rejected" cli curl -fsS "$X"
  no T31 "anonymous GET rejected" cli curl -fsS "$EP/lock/comp"

  # Persistence across restart
  docker restart o17-target >/dev/null; wait_ready >/dev/null
  eq T32 "lock mode persists after restart" COMPLIANCE s3 head-object --bucket lock --key comp --version-id "$C" --query ObjectLockMode --output text
  no T33 "delete still denied after restart" s3 delete-object --bucket lock --key comp --version-id "$C"
}

for TARGET in "${TARGETS[@]}"; do
  PASSES=0; FAILS=0
  echo "== $TARGET ($( [ "$TARGET" = rustfs ] && echo "$RUSTFS_IMAGE" || echo "$SEAWEED_IMAGE" ))"
  if start_target; then suite; else rec T00 FAIL "server did not start"; fi
  echo "== $TARGET: $PASSES passed, $FAILS failed"
  teardown
done
