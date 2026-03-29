#!/bin/sh

set -e

echo "🚀 Starting Smsgangbackend..."

# Wait for database host if provided (non-blocking after timeout)
DB_WAIT_HOST="${DB_HOST:-}"
DB_WAIT_PORT="${DB_PORT:-5432}"
if [ -n "$DB_WAIT_HOST" ]; then
  echo "⏳ Waiting for database at ${DB_WAIT_HOST}:${DB_WAIT_PORT}..."
  i=0
  until nc -z "$DB_WAIT_HOST" "$DB_WAIT_PORT" >/dev/null 2>&1 || [ "$i" -ge 30 ]; do
    i=$((i+1))
    sleep 1
  done
  if nc -z "$DB_WAIT_HOST" "$DB_WAIT_PORT" >/dev/null 2>&1; then
    echo "✅ Database is reachable"
  else
    echo "⚠️  Database not reachable yet; continuing startup"
  fi
fi

# Wait for redis host if provided (non-blocking after timeout)
REDIS_WAIT_HOST="${REDIS_HOST:-}"
REDIS_WAIT_PORT="${REDIS_PORT:-6379}"
if [ -n "$REDIS_WAIT_HOST" ]; then
  echo "⏳ Waiting for Redis at ${REDIS_WAIT_HOST}:${REDIS_WAIT_PORT}..."
  i=0
  until nc -z "$REDIS_WAIT_HOST" "$REDIS_WAIT_PORT" >/dev/null 2>&1 || [ "$i" -ge 30 ]; do
    i=$((i+1))
    sleep 1
  done
  if nc -z "$REDIS_WAIT_HOST" "$REDIS_WAIT_PORT" >/dev/null 2>&1; then
    echo "✅ Redis is reachable"
  else
    echo "⚠️  Redis not reachable yet; continuing startup"
  fi
fi

# Install dependencies if needed (dev only - should not trigger in prod)
if [ ! -d "vendor" ]; then
  echo "📦 Installing Composer dependencies..."
  composer install --no-interaction --optimize-autoloader
fi

# Generate APP_KEY only if not set AND a writable .env file exists
# In production, APP_KEY must be passed as an environment variable
if [ -z "$APP_KEY" ]; then
  if [ -f /var/www/html/.env ] && [ -w /var/www/html/.env ]; then
    echo "🔑 Generating APP_KEY..."
    php artisan key:generate --force
  else
    echo "⚠️  APP_KEY is not set and no writable .env found."
    echo "    Set APP_KEY in your environment or .env.production file."
    echo "    Generate one with: php -r \"echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;\""
    exit 1
  fi
else
  echo "✅ APP_KEY already provided via environment"
fi

# Create cache & storage directories if needed
echo "📁 Setting up storage directories..."
mkdir -p storage/logs \
         storage/framework/views \
         storage/framework/cache \
         storage/framework/sessions \
         bootstrap/cache
chmod -R 777 storage bootstrap/cache

echo "✨ Smsgang is ready!"

# Execute the main command (php-fpm8.4 -F)
exec "$@"