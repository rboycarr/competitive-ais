#!/bin/sh
# Render the S3 identity config from environment keys, then start SeaweedFS (ADR-0007, O17).
# Keys are never printed. Telemetry is always off.
set -eu
umask 077

for v in S3_ADMIN_ACCESS_KEY S3_ADMIN_SECRET_KEY S3_APP_ACCESS_KEY S3_APP_SECRET_KEY S3_ANCHOR_ACCESS_KEY S3_ANCHOR_SECRET_KEY; do
  eval "val=\${$v:-}"
  case "$val" in
    '' | *[!A-Za-z0-9]*) echo "start.sh: $v must be set and alphanumeric (run scripts/dev/setup.sh)" >&2; exit 1 ;;
  esac
done

sed -e "s/__S3_ADMIN_ACCESS_KEY__/$S3_ADMIN_ACCESS_KEY/" \
    -e "s/__S3_ADMIN_SECRET_KEY__/$S3_ADMIN_SECRET_KEY/" \
    -e "s/__S3_APP_ACCESS_KEY__/$S3_APP_ACCESS_KEY/" \
    -e "s/__S3_APP_SECRET_KEY__/$S3_APP_SECRET_KEY/" \
    -e "s/__S3_ANCHOR_ACCESS_KEY__/$S3_ANCHOR_ACCESS_KEY/" \
    -e "s/__S3_ANCHOR_SECRET_KEY__/$S3_ANCHOR_SECRET_KEY/" \
    /etc/seaweedfs/s3-identities.template.json > /tmp/s3.json

exec weed mini -dir=/data -s3.config=/tmp/s3.json -master.telemetry=false
