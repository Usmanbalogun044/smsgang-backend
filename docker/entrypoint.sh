#!/bin/sh

set -e

echo "🚀 Starting SMSGang Backend..."

# Wait for MySQL to be ready
echo "⏳ Waiting for MySQL..."
while ! nc -z mysql 3306; do
  sleep 1
done
echo "✅ MySQL is ready"

# Wait for Redis to be ready
echo "⏳ Waiting for Redis..."
while ! nc -z redis 6379; do
  sleep 1
done
echo "✅ Redis is ready"

# Install dependencies if needed
if [ ! -d "vendor" ]; then
  echo "📦 Installing Composer dependencies..."
  composer install --no-interaction --optimize-autoloader
fi

# Generate APP_KEY if not set
if [ -z "$APP_KEY" ]; then
  echo "🔑 Generating APP_KEY..."
  php artisan key:generate
fi

# Run migrations
echo "🔄 Running database migrations..."
php artisan migrate --force

# Cache configuration and routes
echo "⚡ Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Create cache & storage directories if needed
mkdir -p storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache

echo "✨ SMSGang Backend is ready!"
echo "🌐 Access at http://localhost:8000"
echo "📊 Database: http://localhost:8080 (Adminer)"
echo ""

# Execute the main command
exec "$@"
