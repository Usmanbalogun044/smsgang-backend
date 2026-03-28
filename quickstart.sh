#!/bin/bash

# SMSGang Backend - Docker Quick Start

echo "╔════════════════════════════════════════════════════════════╗"
echo "║     SMSGang Backend - Docker Quick Start Setup              ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is not installed. Please install Docker first."
    echo "   Download from: https://www.docker.com/products/docker-desktop"
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose is not installed."
    echo "   Run: pip install docker-compose"
    exit 1
fi

echo "✅ Docker detected: $(docker --version)"
echo "✅ Docker Compose detected: $(docker-compose --version)"
echo ""

# Create .env file if it doesn't exist
if [ ! -f ".env" ]; then
    echo "📝 Creating .env file from .env.docker..."
    cp .env.docker .env
    
    # Generate APP_KEY
    echo "🔑 Generating APP_KEY..."
    APP_KEY=$(docker run --rm -v $(pwd):/app php:8.2-cli php -r "echo 'base64:' . base64_encode(random_bytes(32));")
    # Update .env with APP_KEY (sed compatible with both macOS and Linux)
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s/^APP_KEY=/APP_KEY=$APP_KEY/" .env
    else
        sed -i "s/^APP_KEY=/APP_KEY=$APP_KEY/" .env
    fi
    echo "✅ .env file created with APP_KEY"
else
    echo "ℹ️  .env file already exists"
fi

echo ""
echo "🐳 Starting Docker containers..."
docker-compose up -d

# Wait for MySQL to be healthy
echo ""
echo "⏳ Waiting for MySQL to be ready..."
max_attempts=30
attempt=0
while ! docker-compose exec -T mysql mysqladmin ping -h localhost &> /dev/null; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "❌ MySQL failed to start"
        docker-compose logs mysql
        exit 1
    fi
    echo -n "."
    sleep 1
done
echo ""
echo "✅ MySQL is ready"

# Wait for Redis to be ready
echo "⏳ Waiting for Redis to be ready..."
max_attempts=30
attempt=0
while ! docker-compose exec -T redis redis-cli ping &> /dev/null; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "❌ Redis failed to start"
        docker-compose logs redis
        exit 1
    fi
    echo -n "."
    sleep 1
done
echo ""
echo "✅ Redis is ready"

echo ""
echo "✨ Setup Complete!"
echo ""
echo "╔════════════════════════════════════════════════════════════╗"
echo "║                    Access Points                            ║"
echo "╠════════════════════════════════════════════════════════════╣"
echo "║                                                              ║"
echo "║  🌐 Backend API    → http://localhost:8000                 ║"
echo "║  📊 Database UI    → http://localhost:8080 (Adminer)       ║"
echo "║     User: smsgang / Password: secret123                    ║"
echo "║                                                              ║"
echo "║  📝 MySQL         → localhost:3306                         ║"
echo "║  💾 Redis         → localhost:6379                         ║"
echo "║                                                              ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
echo "📚 Useful Commands:"
echo "   make logs              # View logs"
echo "   make shell             # Access container shell"
echo "   make migrate           # Run migrations"
echo "   make tinker            # Open Laravel REPL"
echo "   make cache-clear       # Clear cache"
echo "   docker-compose down    # Stop containers"
echo ""
echo "📖 Documentation: See DOCKER.md for more information"
echo ""
