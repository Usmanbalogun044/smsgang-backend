#!/bin/bash

set -e

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║      SMSGang Backend - Docker Registry Deployment to VPS     ║"
echo "╚══════════════════════════════════════════════════════════════╝"

# Check required environment variables
if [ -z "$VPS_HOST" ] || [ -z "$VPS_USER" ]; then
    echo "❌ Error: Missing required environment variables"
    echo ""
    echo "Required variables:"
    echo "  VPS_HOST      - VPS hostname or IP address"
    echo "  VPS_USER      - SSH user for deployment"
    echo ""
    echo "Optional variables:"
    echo "  VPS_PATH      - Path on VPS (default: /home/deploy/smsgang)"
    echo "  DOCKER_REGISTRY - Docker registry (default: ghcr.io)"
    echo "  DOCKER_IMAGE  - Docker image name (default: dollarhunter/smsgang-backend)"
    echo "  DOCKER_TAG    - Docker image tag (default: latest)"
    echo ""
    echo "Example:"
    echo "  export VPS_HOST=example.com"
    echo "  export VPS_USER=deploy"
    echo "  bash deploy.sh"
    exit 1
fi

VPS_PATH="${VPS_PATH:-/home/deploy/smsgang}"
DOCKER_REGISTRY="${DOCKER_REGISTRY:-ghcr.io}"
DOCKER_IMAGE="${DOCKER_IMAGE:-dollarhunter/smsgang-backend}"
DOCKER_TAG="${DOCKER_TAG:-latest}"

VPS_REPO="${VPS_USER}@${VPS_HOST}"

echo "📦 Deployment target: $VPS_REPO:$VPS_PATH"
echo "🐳 Docker image: $DOCKER_REGISTRY/$DOCKER_IMAGE:$DOCKER_TAG"
echo ""

# Step 1: Create deployment directory on VPS
echo "📁 Step 1: Creating deployment directory..."
ssh "$VPS_REPO" "mkdir -p $VPS_PATH && cd $VPS_PATH && pwd"
echo "✅ Directory ready"
echo ""

# Step 2: Upload docker-compose.prod.yml
echo "📤 Step 2: Uploading configuration..."
scp docker-compose.prod.yml "$VPS_REPO:$VPS_PATH/"
echo "✅ Configuration uploaded"
echo ""

# Step 3: Check and update .env on VPS
echo "🔐 Step 3: Checking .env file on VPS..."
if ssh "$VPS_REPO" "test -f $VPS_PATH/.env"; then
    echo "⚠️  .env already exists on VPS. Skipping upload."
    echo "   To update .env, SSH into VPS and edit manually"
else
    echo "📄 .env not found. Creating from template..."
    scp .env.production "$VPS_REPO:$VPS_PATH/.env" 2>/dev/null || {
        echo "⚠️  .env.production template not found locally"
        echo "   Create .env manually on VPS before deployment"
    }
fi
echo ""

# Step 4: Deploy via docker-compose
echo "🚀 Step 4: Deploying to VPS..."
ssh "$VPS_REPO" "cd $VPS_PATH && \
  echo '📥 Pulling latest Docker image...' && \
  DOCKER_REGISTRY=$DOCKER_REGISTRY DOCKER_IMAGE=$DOCKER_IMAGE DOCKER_TAG=$DOCKER_TAG \
    docker-compose -f docker-compose.prod.yml pull && \
  echo '⏹️  Stopping old containers...' && \
  docker-compose -f docker-compose.prod.yml down 2>/dev/null || true && \
  echo '🚀 Starting new containers...' && \
  docker-compose -f docker-compose.prod.yml up -d && \
  echo '⏳ Waiting for services to be ready...' && \
  sleep 10 && \
  echo '🔄 Running database migrations...' && \
  docker-compose -f docker-compose.prod.yml exec -T app php artisan migrate --force 2>/dev/null || echo '⚠️  Migrations may already be applied' && \
  echo '⏳ Optimizing application...' && \
  docker-compose -f docker-compose.prod.yml exec -T app php artisan optimize 2>/dev/null || true && \
  echo '✅ Deployment completed!' && \
  echo '' && \
  echo 'Running status check...' && \
  docker-compose -f docker-compose.prod.yml ps"

if [ $? -ne 0 ]; then
    echo "❌ Deployment failed"
    echo "   Check VPS logs: ssh $VPS_REPO 'cd $VPS_PATH && docker-compose -f docker-compose.prod.yml logs -f'"
    exit 1
fi

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
echo "   curl -i http://$VPS_HOST/api/health || echo 'API not responding yet'"

echo "🎉 All done!"
