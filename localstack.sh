#!/usr/bin/env bash
#
# Local S3 for development, without an AWS account.
#
# Starts LocalStack (S3 only) in Docker and sets up the documents bucket the way
# production needs it (docs/documents.md#storage), from the documents.* settings
# in .env:
#
#   documents.s3Bucket     the bucket to create, with Object Lock (and so versioning)
#   documents.s3Region     the region it is created in
#   documents.s3Prefix     where pending/ sits, for the lifecycle rule
#   documents.s3Endpoint   must point at this machine, e.g. http://localhost:4566
#
# Then runs `php spark documents:check` against it. server.sh runs this before
# serving whenever documents.disk = s3 and documents.s3Endpoint is local.
#
# The container is named `localstack` and listens on 4566, so another project's
# LocalStack on the same machine is reused rather than duplicated. It is left
# running when the server stops; `docker stop localstack` stops it.
#
# LocalStack's free edition keeps nothing across a container restart: the bucket
# is created again, but documents uploaded before the restart no longer open.
#
# Usage:
#   ./localstack.sh
#   LOCALSTACK_IMAGE=localstack/localstack:3.8 PHP_BIN=php8.3 ./localstack.sh

set -u

cd "$(dirname "$0")" || exit 1

CONTAINER="localstack"
IMAGE="${LOCALSTACK_IMAGE:-localstack/localstack:3.8}"
PHP_BIN="${PHP_BIN:-php}"
ENV_FILE=".env"

fail() { echo "❌ $*" >&2; exit 1; }

# One documents.* value from .env, without quotes or a trailing comment.
setting() {
  [ -f "$ENV_FILE" ] || return 0
  sed -n -E "s/^[[:space:]]*documents\.$1[[:space:]]*=[[:space:]]*(.*)$/\1/p" "$ENV_FILE" | tail -n 1 \
    | sed -E "s/[[:space:]]+#.*$//; s/[[:space:]]+$//; s/^['\"](.*)['\"]$/\1/"
}

# --- 1. Settings --------------------------------------------------------------
BUCKET=$(setting s3Bucket)
REGION=$(setting s3Region); REGION="${REGION:-af-south-1}"
PREFIX=$(setting s3Prefix); PREFIX="${PREFIX#/}"; PREFIX="${PREFIX%/}"
ENDPOINT=$(setting s3Endpoint)

[ -n "$BUCKET" ] || fail "documents.s3Bucket is not set in $ENV_FILE."
case "$ENDPOINT" in
  http://localhost:*|http://127.0.0.1:*) ;;
  *) fail "documents.s3Endpoint is '${ENDPOINT}'. Point it at LocalStack (http://localhost:4566) — this script never touches a real bucket." ;;
esac
PORT=$(echo "$ENDPOINT" | sed -E 's#^http://[^:]+:([0-9]+).*#\1#')
HEALTH="http://localhost:$PORT/_localstack/health"

# --- 2. Start LocalStack ------------------------------------------------------
docker info >/dev/null 2>&1 || fail "Docker is not running. Start Docker Desktop, or set documents.disk = local."

if [ -n "$(docker ps -q -f "name=^${CONTAINER}$")" ]; then
  echo "LocalStack is already running."
elif [ -n "$(docker ps -aq -f "name=^${CONTAINER}$")" ]; then
  echo "Starting the LocalStack container..."
  docker start "$CONTAINER" >/dev/null || fail "Could not start the $CONTAINER container."
else
  echo "Creating the LocalStack container ($IMAGE) on port $PORT..."
  docker run -d --name "$CONTAINER" -p "$PORT:4566" \
    -e SERVICES=s3 -e DEFAULT_REGION="$REGION" -e AWS_DEFAULT_REGION="$REGION" \
    "$IMAGE" >/dev/null || fail "Could not create the $CONTAINER container."
fi

ready() { curl -s "$HEALTH" | grep -q '"s3": "\(available\|running\)"'; }
for _ in $(seq 1 30); do ready && break; sleep 1; done
ready || fail "LocalStack S3 did not answer on $HEALTH. Is another container using port $PORT? (docker ps)"

# --- 3. The bucket, as production has it --------------------------------------
# awslocal ships inside the LocalStack image, so no AWS CLI is needed here.
aws() { docker exec "$CONTAINER" awslocal --region "$REGION" "$@"; }

if aws s3api head-bucket --bucket "$BUCKET" >/dev/null 2>&1; then
  echo "Bucket $BUCKET is there."
else
  location=()
  [ "$REGION" = "us-east-1" ] || location=(--create-bucket-configuration "LocationConstraint=$REGION")
  aws s3api create-bucket --bucket "$BUCKET" --object-lock-enabled-for-bucket "${location[@]}" >/dev/null \
    || fail "Could not create bucket $BUCKET."
  echo "✅ Created bucket $BUCKET in $REGION with Object Lock."
fi

if ! aws s3api get-object-lock-configuration --bucket "$BUCKET" 2>/dev/null | grep -q '"ObjectLockEnabled": "Enabled"'; then
  fail "Bucket $BUCKET has no Object Lock, which can only be turned on when a bucket is created. Remove it (docker exec $CONTAINER awslocal s3 rb s3://$BUCKET --force) and run this again."
fi

# Private, like production. No default retention: the application locks each
# document itself, and a default would also lock pending/.
aws s3api put-public-access-block --bucket "$BUCKET" \
  --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true >/dev/null

# Uploads nobody attached are cleared from pending/ after two days.
PENDING="${PREFIX:+$PREFIX/}pending/"
aws s3api put-bucket-lifecycle-configuration --bucket "$BUCKET" --lifecycle-configuration "{
  \"Rules\": [{
    \"ID\": \"expire-pending-uploads\", \"Status\": \"Enabled\", \"Filter\": {\"Prefix\": \"$PENDING\"},
    \"Expiration\": {\"Days\": 2}, \"NoncurrentVersionExpiration\": {\"NoncurrentDays\": 1}
  }]
}" >/dev/null || fail "Could not set the lifecycle rule on $BUCKET."
echo "✅ Bucket is private and clears $PENDING after 2 days."

# --- 4. Check it the way the application will use it ---------------------------
if command -v "$PHP_BIN" >/dev/null 2>&1; then
  "$PHP_BIN" spark documents:check || fail "documents:check failed. Check the documents.* settings in $ENV_FILE."
else
  echo "⚠️  '$PHP_BIN' not found, so documents:check was skipped. Set PHP_BIN to run it."
fi
