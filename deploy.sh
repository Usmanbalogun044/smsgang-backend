#!/usr/bin/env bash

set -e

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║     SMSGang Backend - Production Deployment to VPS              ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""

if [ -z "$VPS_HOST" ] || [ -z "$VPS_USER" ]; then
    echo "❌ Missing required environment variables"
    echo "   Required: VPS_HOST, VPS_USER"
    echo "   Optional: VPS_PATH (default /home/deploy/smsgang)"
    echo "            DOCKER_REGISTRY, DOCKER_IMAGE, DOCKER_TAG"
    exit 1
fi

VPS_PATH="${VPS_PATH:-/home/deploy/smsgang}"
DOCKER_REGISTRY="${DOCKER_REGISTRY:-ghcr.io}"
DOCKER_IMAGE="${DOCKER_IMAGE:-usmanbalogun044/smsgang-backend}"
DOCKER_TAG="${DOCKER_TAG:-latest}"
VPS_REPO="${VPS_USER}@${VPS_HOST}"

if [ -f "docker-compose.prod.yml" ]; then
    COMPOSE_FILE="docker-compose.prod.yml"
elif [ -f "docker-compose.production.yml" ]; then
    COMPOSE_FILE="docker-compose.production.yml"
else
    echo "❌ Missing compose file (docker-compose.prod.yml or production.yml)"
    exit 1
fi

echo "📦 Target: $VPS_REPO:$VPS_PATH"
echo "🐳 Image: $DOCKER_REGISTRY/$DOCKER_IMAGE:$DOCKER_TAG"
echo ""

echo "📁 Step 1: Preparing VPS..."
ssh "$VPS_REPO" "mkdir -p $VPS_PATH && cd $VPS_PATH && pwd" || exit 1
echo "✅ Directory ready"
echo ""

echo "📤 Step 2: Uploading compose..."
scp "$COMPOSE_FILE" "$VPS_REPO:$VPS_PATH/docker-compose.prod.yml" || exit 1
echo "✅ Uploaded"
echo ""

echo "🔐 Step 3: Checking .env..."
if ! ssh "$VPS_REPO" "test -f $VPS_PATH/.env"; then
    echo "   Copying .env template..."
    scp .env.production "$VPS_REPO:$VPS_PATH/.env" 2>/dev/null || echo "⚠️  Template not found; create .env manually"
fi
echo ""

echo "🚀 Step 4: Deploying containers..."
ssh "$VPS_REPO" "
    set -e
    cd $VPS_PATH
    echo '  → Checking DNS resolution...'
    nslookup ghcr.io > /dev/null || { echo '     ⚠️  DNS unstable, retrying...'; sleep 2; nslookup ghcr.io > /dev/null || true; }
    echo '  → Pulling images...'
    DOCKER_REGISTRY=$DOCKER_REGISTRY DOCKER_IMAGE=$DOCKER_IMAGE DOCKER_TAG=$DOCKER_TAG \
        docker-compose -f docker-compose.prod.yml pull

    echo '  → Stopping containers...'
    docker-compose -f docker-compose.prod.yml down --remove-orphans || true

    echo '  → Starting containers...'
    DOCKER_REGISTRY=$DOCKER_REGISTRY DOCKER_IMAGE=$DOCKER_IMAGE DOCKER_TAG=$DOCKER_TAG \
        docker-compose -f docker-compose.prod.yml up -d --scale app=1

    echo '  → Waiting for services...'
    sleep 10

    echo '  → Running migrations...'
    docker-compose -f docker-compose.prod.yml exec -T app php artisan migrate --force 2>/dev/null || echo '     (may be already applied)'

    echo '  → Optimizing cache...'
    docker-compose -f docker-compose.prod.yml exec -T app php artisan optimize 2>/dev/null || true

    echo '  → Cleaning up images...'
    docker image prune -f

    echo '✅ Deployment complete'
    docker-compose -f docker-compose.prod.yml ps
" || { echo "❌ Deploy failed"; exit 1; }

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Deployment successful!"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "📊 Dashboards:"
echo "   Logs:      http://$VPS_HOST:9001 (Dozzle)"
echo "   API Docs:  http://$VPS_HOST:9002 (Swagger)"
echo "   DB Admin:  http://$VPS_HOST:8080 (Adminer)"
echo ""
echo "🔍 Health check:"
echo "   curl http://$VPS_HOST/api/health"
echo ""
echo "📝 Logs:"
echo "   ssh $VPS_REPO 'cd $VPS_PATH && docker-compose -f docker-compose.prod.yml logs -f app'"
echo ""
