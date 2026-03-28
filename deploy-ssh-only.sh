#!/bin/bash

# Simple manual deployment - SSH only
# No Docker Hub push required
# Usage: ./deploy-ssh-only.sh [server_host] [server_user] [server_port]

set -euo pipefail

SERVER_HOST="${1:-157.173.127.226}"
SERVER_USER="${2:-root}"
SERVER_PORT="${3:-22}"
APP_PATH="/home/smsgangbackend"

echo "🚀 SMSGang Manual Deployment (SSH Only)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📍 Target: $SERVER_USER@$SERVER_HOST:/home/smsgangbackend"
echo ""
echo "⚠️  This script deploys the latest committed code on the VPS."
echo "Make sure you've pushed your changes to the main branch!"
echo ""
read -p "Continue? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 1
fi
echo ""

# Deploy via SSH
echo "🔗 Connecting to VPS..."
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

echo "📋 Checking docker-compose file..."
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
fi

echo "🐳 Building Docker image on VPS..."
docker compose -f docker-compose.production.yml build smsgangapp

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
