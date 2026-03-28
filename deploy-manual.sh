#!/bin/bash

# Manual deployment script for smsgang backend
# Usage: ./deploy-manual.sh [server_host] [server_user] [server_port]

set -euo pipefail

# Configuration
SERVER_HOST="${1:-157.173.127.226}"
SERVER_USER="${2:-root}"
SERVER_PORT="${3:-22}"
APP_PATH="/home/smsgangbackend"
DOCKER_USERNAME="your-docker-username"  # Set this to your Docker Hub username

echo "🚀 SMSGang Manual Deployment"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📍 Target: $SERVER_USER@$SERVER_HOST:/home/smsgangbackend"
echo ""

# Step 1: Build Docker image locally
echo "📦 Step 1: Building Docker image..."
docker build -t "${DOCKER_USERNAME}/smsgang:latest" -t "${DOCKER_USERNAME}/smsgang:$(git rev-parse --short HEAD)" ./backend

if [ $? -ne 0 ]; then
    echo "❌ Docker build failed"
    exit 1
fi
echo "✅ Docker image built successfully"
echo ""

# Step 2: Push to Docker Hub
echo "📤 Step 2: Pushing to Docker Hub..."
echo "ℹ️  Make sure you're logged into Docker Hub: docker login"
docker push "${DOCKER_USERNAME}/smsgang:latest"
docker push "${DOCKER_USERNAME}/smsgang:$(git rev-parse --short HEAD)"

if [ $? -ne 0 ]; then
    echo "❌ Docker push failed"
    exit 1
fi
echo "✅ Docker image pushed to Docker Hub"
echo ""

# Step 3: Deploy to VPS via SSH
echo "🔗 Step 3: Connecting to VPS and deploying..."
ssh -p "$SERVER_PORT" "${SERVER_USER}@${SERVER_HOST}" << 'EOSSH'
set -euo pipefail

APP_PATH="/home/smsgangbackend"

echo "📁 Creating directory structure..."
mkdir -p "$APP_PATH"
cd "$APP_PATH"

echo "📥 Setting up backend code..."
if [ -d .git ]; then
  echo "   Git repo exists, pulling latest backend code..."
  git pull origin main
else
  echo "   Cloning backend folder from repository..."
  REPO_DIR="/tmp/smsgang-repo-$$"
  mkdir -p "$REPO_DIR"
  cd "$REPO_DIR"
  
  echo "   Downloading repository..."
  git clone --depth 1 https://github.com/Usmanbalogun044/smsgang.git . || {
    echo "❌ Failed to clone repository"
    exit 1
  }
  
  echo "   Copying backend files to $APP_PATH..."
  if [ -d "backend" ]; then
    cp -r backend/* "$APP_PATH/" || {
      echo "❌ Failed to copy backend files"
      exit 1
    }
  else
    echo "❌ Backend folder not found in repository"
    exit 1
  fi
  
  # Cleanup temp directory
  cd "$APP_PATH"
  rm -rf "$REPO_DIR"
  
  # Initialize git in app path for future updates
  git init
  git remote add origin https://github.com/Usmanbalogun044/smsgang.git
fi

cd "$APP_PATH"

echo "� Checking docker-compose file..."
if [ ! -f "docker-compose.production.yml" ]; then
  echo "❌ docker-compose.production.yml not found!"
  exit 1
fi

echo "🔐 Setting up environment..."
if [ -f .env ]; then
  echo "✅ .env already exists, skipping setup"
elif [ -f .env.production ]; then
  cp .env.production .env
  echo "✅ Created .env from .env.production"
elif [ -f .env.production.example ]; then
  cp .env.production.example .env
  echo "⚠️  Created .env from .env.production.example (UPDATE WITH REAL VALUES!)"
  echo "⚠️  Edit /home/smsgangbackend/.env with your actual production credentials"
else
  echo "❌ No environment file found (.env, .env.production, or .env.production.example)"
  echo "❌ Cannot proceed without environment configuration"
  exit 1
fi

echo "⬇️  Pulling latest image from Docker Hub..."
docker compose -f docker-compose.production.yml pull smsgangapp

echo "🚀 Starting/updating containers..."
docker compose -f docker-compose.production.yml up -d

echo "⏳ Waiting for services to stabilize..."
sleep 10

echo "🔄 Running database migrations..."
docker compose -f docker-compose.production.yml exec -T smsgangapp php artisan migrate --force

echo "⚡ Optimizing application..."
docker compose -f docker-compose.production.yml exec -T smsgangapp php artisan optimize

echo "🧹 Cleaning up old Docker images..."
docker image prune -f

echo "✅ Deployment complete!"
echo ""
echo "🌍 Access the API at: https://api.smsgang.org"
echo "📊 Logs: http://157.173.127.226:8086 (Dozzle)"
echo "🗄️  Database: http://157.173.127.226:5001 (PhpMyAdmin)"
EOSSH

echo ""
echo "✅ SMSGang backend deployment finished!"
