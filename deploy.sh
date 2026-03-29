#!/bin/bash

set -e

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║      Smsgangbackend - Docker Registry Deployment to VPS     ║"
echo "╚══════════════════════════════════════════════════════════════╝"

# Check required environment variables
if [ -z "$VPS_HOST" ] || [ -z "$VPS_USER" ] || [ -z "$DOCKER_USERNAME" ]; then
    echo "❌ Error: Missing required environment variables"
    echo ""
    echo "Required variables:"
    echo "  VPS_HOST          - VPS hostname or IP address"
    echo "  VPS_USER          - SSH user for deployment"
    echo "  DOCKER_USERNAME   - Docker Hub username"
    echo ""
    echo "Optional variables:"
    echo "  VPS_PATH          - Path on VPS (default: /home/deploy/smsgang)"
    echo "  DOCKER_REGISTRY   - Docker registry (default: docker.io)"
    echo "  DOCKER_IMAGE      - Docker image name (default: smsgang-backend)"
    echo "  DOCKER_TAG        - Docker image tag (default: latest)"
    echo ""
    echo "Example:"
    echo "  export VPS_HOST=example.com"
    echo "  export VPS_USER=deploy"
    echo "  bash deploy.sh"
    exit 1
fi

VPS_PATH="${VPS_PATH:-/home/deploy/smsgang}"
DOCKER_REGISTRY="${DOCKER_REGISTRY:-docker.io}"
DOCKER_IMAGE="${DOCKER_IMAGE:-smsgang-backend}"
DOCKER_TAG="${DOCKER_TAG:-latest}"
CERTBOT_DOMAIN="${CERTBOT_DOMAIN:-api.smsgang.org}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-noreusmanbalogun044@gmail.com}"

VPS_REPO="${VPS_USER}@${VPS_HOST}"

echo "📦 Deployment target: $VPS_REPO:$VPS_PATH"
echo "🐳 Docker image: $DOCKER_REGISTRY/$DOCKER_IMAGE:$DOCKER_TAG"
echo ""

# Step 1: Create deployment directory on VPS
echo "📁 Step 1: Creating deployment directory..."
ssh "$VPS_REPO" "mkdir -p $VPS_PATH && cd $VPS_PATH && pwd"
echo "✅ Directory ready"
echo ""

# Step 2: Verify configs uploaded by GitHub Actions
echo "📋 Step 2: Verifying configuration on VPS..."
ssh "$VPS_REPO" bash << VERIFY_SCRIPT
  [ -f $VPS_PATH/.env ] || { echo '❌ Missing .env on VPS'; exit 1; }
  [ -f $VPS_PATH/openapi.yml ] || { echo '❌ Missing openapi.yml on VPS'; exit 1; }
  [ -f $VPS_PATH/docker-compose.prod.yml ] || { echo '❌ Missing docker-compose.prod.yml on VPS'; exit 1; }
  [ -f $VPS_PATH/docker/nginx.conf ] || { echo '❌ Missing docker/nginx.conf on VPS'; exit 1; }
  grep -q '^APP_KEY=' $VPS_PATH/.env || { echo '❌ Missing APP_KEY in .env'; exit 1; }
  grep -q '^DB_HOST=' $VPS_PATH/.env || { echo '❌ Missing DB_HOST in .env'; exit 1; }
  grep -q '^DB_USERNAME=' $VPS_PATH/.env || { echo '❌ Missing DB_USERNAME in .env'; exit 1; }
  grep -q '^DB_PASSWORD=' $VPS_PATH/.env || { echo '❌ Missing DB_PASSWORD in .env'; exit 1; }
  echo '✅ All config files verified'
VERIFY_SCRIPT
echo ""

# Step 4: Deploy via docker-compose
echo "🚀 Step 4: Deploying to VPS..."
DOCKER_USERNAME_VAR="$DOCKER_USERNAME"
DOCKER_TAG_VAR="$DOCKER_TAG"
VPS_PATH_VAR="$VPS_PATH"
CERTBOT_DOMAIN_VAR="$CERTBOT_DOMAIN"
CERTBOT_EMAIL_VAR="$CERTBOT_EMAIL"

ssh "$VPS_REPO" bash <<EOF
set -e
cd "$VPS_PATH_VAR"

export DOCKER_USERNAME="$DOCKER_USERNAME_VAR"
export DOCKER_TAG="$DOCKER_TAG_VAR"
export CERTBOT_DOMAIN="$CERTBOT_DOMAIN_VAR"
export CERTBOT_EMAIL="$CERTBOT_EMAIL_VAR"

# Validate required files exist
[ -f docker/nginx.conf ] || { echo '❌ Missing docker/nginx.conf on VPS'; exit 1; }
[ -f .env ] || { echo '❌ Missing .env on VPS'; exit 1; }
[ -f openapi.yml ] || { echo '❌ Missing openapi.yml on VPS'; exit 1; }

echo '📥 Pulling latest Docker image...'
docker compose --env-file .env -f docker-compose.prod.yml pull

echo '⏹️  Stopping old containers...'
docker compose --env-file .env -f docker-compose.prod.yml down --remove-orphans 2>/dev/null || true

echo '🚀 Starting app container first...'
docker compose --env-file .env -f docker-compose.prod.yml up -d smsgang-app

echo '⏳ Waiting for app container to be healthy...'
for i in \$(seq 1 12); do
  STATUS=\$(docker inspect --format='{{.State.Health.Status}}' smsgang-app-prod 2>/dev/null || echo 'starting')
  echo "   Health status: \$STATUS (attempt \$i/12)"
  if [ "\$STATUS" = "healthy" ]; then
    break
  fi
  sleep 5
done

echo '🚀 Starting remaining services (dozzle, swagger, adminer, certbot)...'
docker compose --env-file .env -f docker-compose.prod.yml up -d

echo '⏹️  Waiting for services to stabilize...'
sleep 15

echo '🔐 Generating SSL certificates with certbot...'
if [ ! -f /data/certbot/letsencrypt/live/$CERTBOT_DOMAIN/fullchain.pem ]; then
  echo '   First-time certificate generation...'
  docker compose --env-file .env -f docker-compose.prod.yml run --rm --entrypoint /bin/sh smsgang-certbot -c "certbot certonly --webroot -w /var/www/certbot --email '$CERTBOT_EMAIL' -d $CERTBOT_DOMAIN --agree-tos --non-interactive" || echo '⚠️  Cert generation in progress or skipped'
  sleep 5
  docker compose --env-file .env -f docker-compose.prod.yml restart smsgang-app
  sleep 3
else
  echo '   Certificates already exist, skipping generation'
fi

echo '�🔧 Fixing Laravel runtime permissions...'
docker compose --env-file .env -f docker-compose.prod.yml exec -T smsgang-app sh -lc "mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache && chmod -R 777 storage bootstrap/cache" 2>/dev/null || true

echo '🔄 Running database migrations...'
docker compose --env-file .env -f docker-compose.prod.yml exec -T smsgang-app php artisan migrate --force

echo '⚙️  Caching Laravel config/routes/events...'
docker compose --env-file .env -f docker-compose.prod.yml exec -T smsgang-app php artisan optimize:clear 2>/dev/null || true
docker compose --env-file .env -f docker-compose.prod.yml exec -T smsgang-app php artisan config:cache 2>/dev/null || true
docker compose --env-file .env -f docker-compose.prod.yml exec -T smsgang-app php artisan route:cache 2>/dev/null || true
docker compose --env-file .env -f docker-compose.prod.yml exec -T smsgang-app php artisan event:cache 2>/dev/null || true

echo ''
echo '📋 Running status check...'
docker compose --env-file .env -f docker-compose.prod.yml ps

docker compose --env-file .env -f docker-compose.prod.yml ps --services --status running | grep -q '^smsgang-app$' \
  || { echo '❌ smsgang-app container is not running'; exit 1; }

echo ''
echo '🏥 Running health check...'
curl -fsS http://localhost/health && echo '✅ Health check passed' \
  || { echo '❌ Health check failed'; exit 1; }

echo ''
echo '✅ Deployment completed!'
EOF

echo ""
echo "✅ Deployment completed successfully!"
echo ""
echo "📋 Useful commands:"
echo "  Logs:      ssh $VPS_REPO 'cd $VPS_PATH && docker compose --env-file .env -f docker-compose.prod.yml logs -f smsgang-app'"
echo "  Dozzle:    http://$VPS_HOST:9001"
echo "  Swagger:   http://$VPS_HOST:9002"
echo "  Adminer:   http://$VPS_HOST:9003"
echo ""
echo "⚡ Health check:"
echo "   curl -i http://$VPS_HOST/health"
echo ""
echo "🎉 All done!"
