#!/usr/bin/env bash

set -euo pipefail

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║      SMSGang Backend - Docker Registry Deployment to VPS    ║"
echo "╚══════════════════════════════════════════════════════════════╝"

if [ -z "${VPS_HOST:-}" ] || [ -z "${VPS_USER:-}" ]; then
        echo "❌ Error: Missing required environment variables"
        echo "Required: VPS_HOST, VPS_USER"
        exit 1
fi

VPS_PATH="${VPS_PATH:-/home/deploy/smsgang}"
DOCKER_REGISTRY="${DOCKER_REGISTRY:-ghcr.io}"
DOCKER_IMAGE="${DOCKER_IMAGE:-usmanbalogun044/smsgang-backend}"
DOCKER_TAG="${DOCKER_TAG:-latest}"
CERTBOT_DOMAIN="${CERTBOT_DOMAIN:-}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"

VPS_REPO="${VPS_USER}@${VPS_HOST}"

if [ -f "docker-compose.prod.yml" ]; then
        COMPOSE_FILE="docker-compose.prod.yml"
elif [ -f "docker-compose.production.yml" ]; then
        COMPOSE_FILE="docker-compose.production.yml"
else
        echo "❌ Missing compose file (docker-compose.prod.yml or docker-compose.production.yml)"
        exit 1
fi

echo "📦 Deployment target: $VPS_REPO:$VPS_PATH"
echo "🐳 Docker image: $DOCKER_REGISTRY/$DOCKER_IMAGE:$DOCKER_TAG"
echo ""

echo "📁 Step 1: Creating deployment directory..."
ssh "$VPS_REPO" "mkdir -p '$VPS_PATH/server' && cd '$VPS_PATH' && pwd"
echo "✅ Directory ready"
echo ""

echo "📤 Step 2: Uploading configuration..."
scp "$COMPOSE_FILE" "$VPS_REPO:$VPS_PATH/docker-compose.prod.yml"
scp openapi.yml "$VPS_REPO:$VPS_PATH/openapi.yml"
scp server/default-production.conf "$VPS_REPO:$VPS_PATH/server/default-production.conf"
scp .env.production "$VPS_REPO:$VPS_PATH/.env.deploy-template" 2>/dev/null || true
echo "✅ Configuration uploaded"
echo ""

echo "🔐 Step 3: Checking .env file on VPS..."
if ssh "$VPS_REPO" "test -f '$VPS_PATH/.env'"; then
        echo "⚠️  .env already exists on VPS. Skipping upload."
else
        echo "📄 .env not found. Creating from template..."
        scp .env.production "$VPS_REPO:$VPS_PATH/.env" 2>/dev/null || {
                echo "⚠️  .env.production template not found locally"
                echo "   Create .env manually on VPS before deployment"
        }
fi
echo ""

echo "🚀 Step 4: Deploying to VPS..."
ssh "$VPS_REPO" \
    "VPS_PATH='$VPS_PATH' DOCKER_REGISTRY='$DOCKER_REGISTRY' DOCKER_IMAGE='$DOCKER_IMAGE' DOCKER_TAG='$DOCKER_TAG' CERTBOT_DOMAIN='$CERTBOT_DOMAIN' CERTBOT_EMAIL='$CERTBOT_EMAIL' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail

cd "$VPS_PATH"

[ -f .env ] || { echo '❌ Missing .env on VPS'; exit 1; }
[ -f openapi.yml ] || { echo '❌ Missing openapi.yml on VPS'; exit 1; }
[ -f docker-compose.prod.yml ] || { echo '❌ Missing docker-compose.prod.yml on VPS'; exit 1; }
[ -f server/default-production.conf ] || { echo '❌ Missing server/default-production.conf on VPS'; exit 1; }

DB_HOST_CURRENT=$(grep -E '^DB_HOST=' .env | tail -n1 | cut -d= -f2- || true)
if [ "$DB_HOST_CURRENT" = "smsgangdatabase" ]; then
    echo '⚠️  Legacy DB_HOST detected in VPS .env (smsgangdatabase). Updating from .env.deploy-template...'
    [ -f .env.deploy-template ] || { echo '❌ Missing .env.deploy-template; cannot auto-fix DB settings'; exit 1; }

    upsert_env_var() {
        key="$1"
        value="$2"
        escaped_value=$(printf '%s' "$value" | sed 's/[&|]/\\&/g')
        if grep -q "^${key}=" .env; then
            sed -i "s|^${key}=.*|${key}=${escaped_value}|" .env
        else
            echo "${key}=${value}" >> .env
        fi
    }

    for key in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
        val=$(grep -E "^${key}=" .env.deploy-template | tail -n1 | cut -d= -f2- || true)
        [ -n "$val" ] && upsert_env_var "$key" "$val"
    done

    echo '✅ DB settings updated from .env.deploy-template'
fi

DB_HOST_EFFECTIVE=$(grep -E '^DB_HOST=' .env | tail -n1 | cut -d= -f2- || true)
[ -n "$DB_HOST_EFFECTIVE" ] || { echo '❌ DB_HOST missing in .env'; exit 1; }
echo "ℹ️  Using DB_HOST=$DB_HOST_EFFECTIVE"

echo '📋 Rendered image values:'
docker-compose -f docker-compose.prod.yml config | grep -E 'image:' || true

echo '📥 Pulling latest Docker image...'
docker-compose -f docker-compose.prod.yml pull

echo '⏹️  Stopping old containers...'
docker-compose -f docker-compose.prod.yml down --remove-orphans 2>/dev/null || true

echo '🚀 Starting app container first...'
docker-compose -f docker-compose.prod.yml up -d --scale app=1 app

echo '⏳ Waiting for app container to be healthy...'
for i in $(seq 1 12); do
    STATUS=$(docker inspect --format='{{.State.Health.Status}}' smsgang-app 2>/dev/null || echo 'starting')
    echo "   Health status: $STATUS (attempt $i/12)"
    if [ "$STATUS" = "healthy" ] || [ "$STATUS" = "running" ]; then
        break
    fi
    sleep 5
done

docker-compose -f docker-compose.prod.yml ps --services --status running | grep -q '^app$' \
    || { echo '❌ app service is not running'; exit 1; }

echo '🚀 Starting remaining services...'
docker-compose -f docker-compose.prod.yml up -d

echo '⏳ Waiting for services to stabilize...'
sleep 15

if [ -n "${CERTBOT_DOMAIN:-}" ] && [ -n "${CERTBOT_EMAIL:-}" ]; then
    if [ ! -f "/data/certbot/letsencrypt/live/${CERTBOT_DOMAIN}/fullchain.pem" ]; then
        echo '🔐 First-time certificate generation...'
        docker-compose -f docker-compose.prod.yml run --rm --entrypoint /bin/sh certbot \
            -c "certbot certonly --webroot -w /var/www/certbot --email '${CERTBOT_EMAIL}' -d '${CERTBOT_DOMAIN}' --agree-tos --non-interactive" \
            || echo '⚠️  Cert generation skipped/failed (will retry on renew loop)'
        docker-compose -f docker-compose.prod.yml restart nginx || true
    fi
fi

echo '🔄 Running database migrations...'
docker-compose -f docker-compose.prod.yml exec -T app php artisan migrate --force

echo '⚙️  Optimizing Laravel...'
docker-compose -f docker-compose.prod.yml exec -T app php artisan optimize:clear 2>/dev/null || true
docker-compose -f docker-compose.prod.yml exec -T app php artisan config:cache 2>/dev/null || true
docker-compose -f docker-compose.prod.yml exec -T app php artisan route:cache 2>/dev/null || true
docker-compose -f docker-compose.prod.yml exec -T app php artisan event:cache 2>/dev/null || true

echo '🧹 Cleaning dangling images...'
docker image prune -f

echo '📋 Running status check...'
docker-compose -f docker-compose.prod.yml ps

echo '🏥 Running local health check...'
curl -fsS http://localhost/api/health >/dev/null 2>&1 || curl -fsS http://localhost/health >/dev/null 2>&1

echo '✅ Deployment completed!'
REMOTE_SCRIPT

echo ""
echo "✅ Deployment completed successfully!"
echo ""
echo "📋 Next steps:"
echo "  1. View logs: ssh $VPS_REPO 'cd $VPS_PATH && docker-compose -f docker-compose.prod.yml logs -f app'"
echo "  2. Check Dozzle logs: http://$VPS_HOST:9001"
echo "  3. API Swagger docs: http://$VPS_HOST:9002"
echo "  4. Database admin: http://$VPS_HOST:8080"
echo ""
echo "⚡ Health check:"
echo "   curl -i http://$VPS_HOST/api/health"
echo ""
echo "🎉 All done!"
