#!/bin/bash
#
# Starts LocalStack (S3 only) for the grants application and provisions the buckets
# the app uploads to. Used by application/helpers/aws_s3_helper.php, which routes
# S3 traffic to LocalStack when ENVIRONMENT is 'development'.
#
# Usage: ./setup_localstack.sh [edge_port] [app_container] [project_path]

# --- 1. Arguments & Defaults ---
# $1: Edge Port (Default: 4566)
# $2: PHP app container(s) that must reach LocalStack, space separated (Default: all running of apps, apps81, apps82)
# $3: Project Path (Default: directory of this script)

EDGE_PORT="${1:-4566}"
APP_CONTAINER="${2:-}"
PROJECT_PATH="${3:-$(dirname "$0")}"

ABS_PROJECT_PATH=$(cd "$PROJECT_PATH" && pwd)

LOCALSTACK_CONTAINER="localstack"
LOCALSTACK_IMAGE="localstack/localstack"
LOCALSTACK_PUBLIC_ENDPOINT="http://localhost:$EDGE_PORT"
LOCALSTACK_INTERNAL_ENDPOINT="http://$LOCALSTACK_CONTAINER:4566"
ENV_FILE="$ABS_PROJECT_PATH/.env"

GRANTS_CONFIG="$ABS_PROJECT_PATH/application/config/grants.php"
AWS_ATTACHMENT_CONFIG="$ABS_PROJECT_PATH/application/config/Aws_attachment.php"

# Reads a string config item, e.g. $config['s3_bucket_name'] = 'value';
read_config_item() {
    local file="$1" key="$2"
    [ -f "$file" ] || return 0
    sed -n "s/^[[:space:]]*\$config\['$key'\][[:space:]]*=[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p" "$file" | tail -n 1
}

# Aws_attachment is autoloaded after grants, so its values win; grants.php is the fallback
config_value() {
    local value
    value=$(read_config_item "$AWS_ATTACHMENT_CONFIG" "$1")
    [ -z "$value" ] && value=$(read_config_item "$GRANTS_CONFIG" "$1")
    echo "$value"
}

DEFAULT_REGION=$(config_value "s3_region")
DEFAULT_REGION="${DEFAULT_REGION:-eu-west-1}"

# Every bucket the app may write to: attachments (both configs) and the reimbursement CSV bucket
BUCKETS=$(printf "%s\n" \
    "$(read_config_item "$AWS_ATTACHMENT_CONFIG" "s3_bucket_name")" \
    "$(read_config_item "$GRANTS_CONFIG" "s3_bucket_name")" \
    "$(read_config_item "$AWS_ATTACHMENT_CONFIG" "csv_reimbursement_bucket")" \
    | awk 'NF && !seen[$0]++')

if [ -z "$BUCKETS" ]; then
    echo "❌ Error: No S3 bucket names found in application/config (s3_bucket_name, csv_reimbursement_bucket)."
    exit 1
fi

# --- 2. Start LocalStack ---
if ! docker info >/dev/null 2>&1; then
    echo "❌ Error: Docker is not running."
    exit 1
fi

echo "--- Starting LocalStack ($DEFAULT_REGION) ---"
if [ -n "$(docker ps -q -f "name=^${LOCALSTACK_CONTAINER}$")" ]; then
    echo "LocalStack container already running."
elif [ -n "$(docker ps -aq -f "name=^${LOCALSTACK_CONTAINER}$")" ]; then
    docker start "$LOCALSTACK_CONTAINER" >/dev/null
else
    docker run -d \
        --name "$LOCALSTACK_CONTAINER" \
        -p "$EDGE_PORT:4566" \
        -e SERVICES=s3 \
        -e DEFAULT_REGION="$DEFAULT_REGION" \
        -e AWS_DEFAULT_REGION="$DEFAULT_REGION" \
        "$LOCALSTACK_IMAGE" >/dev/null
fi

for _ in {1..30}; do
    if curl -s "$LOCALSTACK_PUBLIC_ENDPOINT/_localstack/health" | grep -q '"s3": "\(available\|running\)"'; then
        break
    fi
    sleep 1
done

if ! curl -s "$LOCALSTACK_PUBLIC_ENDPOINT/_localstack/health" | grep -q '"s3": "\(available\|running\)"'; then
    echo "❌ Error: LocalStack S3 did not become ready on $LOCALSTACK_PUBLIC_ENDPOINT"
    exit 1
fi

# --- 3. Connect LocalStack to the PHP app container network ---
echo "--- Connecting LocalStack to the app network ---"
# Connect to every running app container (apps on :8001, apps81 on :8002, apps82 on :8082),
# since the grants app can be served from any of them
APP_CONTAINERS="${APP_CONTAINER:-apps apps81 apps82}"
CONNECTED_ANY=0

for container in $APP_CONTAINERS; do
    [ -n "$(docker ps -q -f "name=^${container}$")" ] || continue
    CONNECTED_ANY=1

    for network in $(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}' "$container"); do
        if docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}' "$LOCALSTACK_CONTAINER" | grep -qw "$network"; then
            echo "Already on network $network ($container)"
        else
            docker network connect --alias "$LOCALSTACK_CONTAINER" "$network" "$LOCALSTACK_CONTAINER"
            echo "✅ Connected to network $network (reachable from $container as $LOCALSTACK_INTERNAL_ENDPOINT)"
        fi
    done
done

if [ "$CONNECTED_ANY" -eq 0 ]; then
    echo "⚠️  No running app container found (tried: $APP_CONTAINERS)."
    echo "    Re-run with the container name once it is up, e.g. ./setup_localstack.sh $EDGE_PORT apps81"
fi

# --- 4. Create S3 Buckets ---
# awslocal ships inside the LocalStack image, so no AWS CLI is needed on the host
echo "--- Provisioning S3 Buckets ---"
for BUCKET_NAME in $BUCKETS; do
    if docker exec "$LOCALSTACK_CONTAINER" awslocal s3api head-bucket --bucket "$BUCKET_NAME" >/dev/null 2>&1; then
        echo "Bucket already exists: $BUCKET_NAME"
        continue
    fi

    if [ "$DEFAULT_REGION" = "us-east-1" ]; then
        docker exec "$LOCALSTACK_CONTAINER" awslocal s3api create-bucket \
            --bucket "$BUCKET_NAME" \
            --region "$DEFAULT_REGION" >/dev/null
    else
        docker exec "$LOCALSTACK_CONTAINER" awslocal s3api create-bucket \
            --bucket "$BUCKET_NAME" \
            --region "$DEFAULT_REGION" \
            --create-bucket-configuration "LocationConstraint=$DEFAULT_REGION" >/dev/null
    fi

    echo "✅ S3 Bucket Created: $BUCKET_NAME"
done

# --- 5. Update .env file ---
set_env_value() {
    local key="$1" value="$2"
    if grep -q "^$key=" "$ENV_FILE"; then
        sed -i '' "s|^$key=.*|$key='$value'|" "$ENV_FILE" 2>/dev/null || \
        sed -i "s|^$key=.*|$key='$value'|" "$ENV_FILE"
        echo "Updated $key in $ENV_FILE"
    else
        echo "$key='$value'" >> "$ENV_FILE"
        echo "Added $key to $ENV_FILE"
    fi
}

echo "--- Updating $ENV_FILE ---"
touch "$ENV_FILE"
[ -n "$(tail -c 1 "$ENV_FILE")" ] && echo "" >> "$ENV_FILE"
set_env_value "LOCALSTACK_ENDPOINT" "$LOCALSTACK_INTERNAL_ENDPOINT"
set_env_value "LOCALSTACK_PUBLIC_ENDPOINT" "$LOCALSTACK_PUBLIC_ENDPOINT"
set_env_value "LOCALSTACK_ACCESS_KEY_ID" "test"
set_env_value "LOCALSTACK_SECRET_ACCESS_KEY" "test"

echo "--- Setup Complete ---"
echo "Uploads go to LocalStack when CI_ENV is 'development'."
