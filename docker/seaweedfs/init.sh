#!/bin/bash
# One-shot: create the buckets (idempotent) and check object lock (ADR-0007, docs/10 §1.3).
# Retention periods are set per object by the app (evidence) and the audit anchor job (P1-50).
set -euo pipefail

export AWS_ACCESS_KEY_ID="$S3_ADMIN_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$S3_ADMIN_SECRET_KEY"
export AWS_DEFAULT_REGION=us-east-1 AWS_EC2_METADATA_DISABLED=true AWS_CONFIG_FILE=/tmp/aws-config
cat > "$AWS_CONFIG_FILE" <<'EOF'
[default]
request_checksum_calculation = when_required
response_checksum_validation = when_required
s3 =
    addressing_style = path
EOF

EP=http://seaweedfs:8333
s3() { aws --endpoint-url "$EP" s3api "$@"; }

ensure() {
  local bucket=$1; shift
  if s3 head-bucket --bucket "$bucket" >/dev/null 2>&1; then
    echo "bucket $bucket: exists"
  else
    s3 create-bucket --bucket "$bucket" "$@" >/dev/null
    echo "bucket $bucket: created"
  fi
}

ensure evidence-quarantine
ensure evidence --object-lock-enabled-for-bucket
ensure exports
ensure audit-anchor --object-lock-enabled-for-bucket

for b in evidence audit-anchor; do
  state=$(s3 get-object-lock-configuration --bucket "$b" --query ObjectLockConfiguration.ObjectLockEnabled --output text)
  [ "$state" = Enabled ] || { echo "bucket $b: object lock is '$state', expected Enabled" >&2; exit 1; }
  echo "bucket $b: object lock Enabled"
done
