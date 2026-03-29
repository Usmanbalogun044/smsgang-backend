#!/usr/bin/env bash

# Pull latest application image
docker compose -f docker-compose.prod.yml pull tegaai-app

# Kill the running containers
docker compose -f docker-compose.prod.yml down --remove-orphans

# Restart containers
docker compose -f docker-compose.prod.yml up -d

# Run Laravel initialization
docker compose -f docker-compose.prod.yml exec -T tegaai-app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec -T tegaai-app php artisan config:cache
docker compose -f docker-compose.prod.yml exec -T tegaai-app php artisan route:cache

# Run migrations
docker compose -f docker-compose.prod.yml exec -T tegaai-app php artisan migrate --force

# Cleanup old images
docker image prune -f
