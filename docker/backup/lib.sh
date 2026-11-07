# Shared by backup.sh and check-locks.sh (P1-51). Sourced, not executed. Uses the read-only backup key.
: "${S3_BACKUP_ACCESS_KEY:?}" "${S3_BACKUP_SECRET_KEY:?}"
export AWS_ACCESS_KEY_ID="$S3_BACKUP_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$S3_BACKUP_SECRET_KEY"
export AWS_DEFAULT_REGION=us-east-1 AWS_EC2_METADATA_DISABLED=true AWS_CONFIG_FILE=/work/aws-config
printf '[default]\nrequest_checksum_calculation = when_required\nresponse_checksum_validation = when_required\ns3 =\n    addressing_style = path\n' > "$AWS_CONFIG_FILE"
s3() { aws --endpoint-url "${S3_ENDPOINT:-http://seaweedfs:8333}" s3api "$@"; }

# Retention or legal hold of one version, or the given "None" value when the version has none.
#   lock_field retention|legal-hold BUCKET KEY VERSION JMESPATH NONE
lock_field() {
  local what=$1 bucket=$2 key=$3 version=$4 query=$5 none=$6 value err
  err=$(mktemp -p /work)
  if value=$(s3 "get-object-$what" --bucket "$bucket" --key "$key" --version-id "$version" --query "$query" --output text 2>"$err"); then
    rm -f "$err"; printf '%s' "$value"
  elif grep -qE 'NoSuchObjectLockConfiguration|ObjectLockConfigurationNotFound|NoSuchObjectLegalHold' "$err"; then
    rm -f "$err"; printf '%s' "$none"
  else
    cat "$err" >&2; rm -f "$err"; return 1
  fi
}
