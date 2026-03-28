# SMSGang Backend - Docker Setup Guide

## Overview

Complete Docker setup for SMSGang backend with:
- **Local Development**: Full Docker environment with hot-reload
- **Production Deployment**: Optimized containers for VPS deployment
- **Background Jobs**: Queue workers and scheduler in Docker
- **Database**: MySQL with automatic migrations
- **Caching**: Redis for queues, cache, and sessions
- **Web Server**: Nginx with PHP-FPM
- **Process Management**: Supervisor for managing multiple services

## Prerequisites

- Docker 20.10+
- Docker Compose 2.0+
- Git
- For VPS deployment: SSH access to your server

## Local Development Setup

### 1. Start the Stack

```bash
cd backend

# Copy environment file
cp .env.docker .env

# Build and start containers
docker-compose up -d

# Verify all containers are running
docker-compose ps
```

### 2. Access Services

- **Backend API**: http://localhost:8000
- **Database Manager**: http://localhost:8080 (Adminer)
  - User: `smsgang`
  - Password: `secret123`
  - Database: `smsgang`
- **Redis**: `localhost:6379` (no password)
- **MySQL**: `localhost:3306`

### 3. Running Commands

```bash
# View logs
docker-compose logs -f app

# Run artisan commands
docker-compose exec app php artisan tinker
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan migrate:fresh --seed

# Access container shell
docker-compose exec app sh

# View queue worker logs
docker-compose logs -f app | grep "worker"

# View scheduler logs
docker-compose logs -f app | grep "scheduler"
```

### 4. Development Workflow

The entire `/backend` directory is mounted in the container, so:
- Changes to code are **immediately reflected**
- No rebuild needed for PHP code changes
- Composer dependencies: rebuild with `docker-compose up -d --build`

### 5. Stopping and Cleaning Up

```bash
# Stop all containers
docker-compose down

# Remove all data (MySQL, Redis)
docker-compose down -v

# Rebuild from scratch
docker-compose up -d --build
```

## Background Jobs

### Job Schedule

Jobs run automatically in Docker via the scheduler:

| Job | Schedule | Purpose |
|-----|----------|---------|
| `SyncAllPricingJob` | Every hour at :05 | Sync pricing from 5sim, update exchange rates |
| `CheckAllActiveSmsJob` | Every 5 minutes | Check SMS for all pending activations |
| `ExpireActivationsJob` | Every 30 minutes | Expire old/inactive activations |
| Queue cleanup | Daily at 02:00 | Clean up failed jobs |

### Monitoring Jobs

```bash
# Watch scheduler execution
docker-compose logs -f app | grep scheduler

# Watch queue workers
docker-compose logs -f app | grep worker

# Check failed jobs
docker-compose exec app php artisan queue:failed

# Retry failed job
docker-compose exec app php artisan queue:retry {id}

# Flush all failed jobs
docker-compose exec app php artisan queue:flush
```

### Queue Configuration

- **Driver**: Redis (fast, distributed)
- **Workers**: 4 concurrent processes
- **Supervisor**: Automatically restarts failed workers
- **Timeout**: 0 (no timeout, useful for long-running jobs)

## Production Deployment

> **📚 Complete step-by-step VPS setup guide**: See [VPS_SETUP.md](VPS_SETUP.md) for comprehensive instructions on preparing your VPS, configuring SSL, setting up backups, and troubleshooting.

### Quick Start (After VPS is Ready)

**Option A: Automatic Deployment via GitHub Actions (Recommended)**

```bash
# 1. Configure GitHub secrets (one-time setup)
# In repository Settings → Secrets and variables → Actions:
#   - VPS_HOST: your.vps.com
#   - VPS_USER: deploy
#   - VPS_SSH_KEY: (your SSH private key)
#   - GH_PAT: (GitHub Personal Access Token)

# 2. Push changes to main branch
git add .
git commit -m "production deployment"
git push origin main

# 3. GitHub Actions automatically:
#    - Builds Docker image
#    - Pushes to ghcr.io
#    - Deploys to VPS
#    - Runs migrations
#    - Optimizes application
```

**Option B: Manual Deployment Script**

```bash
# From your local machine (in backend directory)
export VPS_HOST=your.vps.com
export VPS_USER=deploy
export DOCKER_REGISTRY=ghcr.io
export DOCKER_IMAGE=dollarhunter/smsgang-backend

bash deploy.sh
```

This script will:
1. Create deployment directory on VPS
2. Upload configuration files
3. Pull Docker image from registry
4. Stop old containers
5. Start new containers
6. Run database migrations
7. Optimize application cache

### Verify Deployment

```bash
# SSH into VPS
ssh deploy@your.vps.com
cd /home/deploy/smsgang

# Check services
docker-compose -f docker-compose.prod.yml ps

# View logs
docker-compose -f docker-compose.prod.yml logs -f app

# Test API health
curl https://your.vps.com/api/health
```

### Access Monitoring Tools

After deployment, your VPS monitoring stack is available at:

- **Dozzle** (Container Logs): `http://your.vps.com:9001`
- **Swagger UI** (API Docs): `http://your.vps.com:9002`
- **Adminer** (Database Admin): `http://your.vps.com:8080`

### Environment Variables

Key `.env` variables for production (see VPS_SETUP.md for complete template):

```bash
APP_ENV=production
APP_DEBUG=false
DB_PASSWORD=your_secure_password
REDIS_PASSWORD=your_secure_password
LENDOVERIFY_API_KEY=your_api_key
FIVESIM_API_KEY=your_api_key
MAIL_HOST=smtp.mailtrap.io  # or your email provider
```

### Queue Workers

The Docker image includes **8 concurrent queue workers** (configurable in `docker/supervisor.conf`).

```bash
# Monitor queue status
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:failed

# Retry failed jobs
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:retry all

# Clear failed jobs
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:flush
```

### Database Backups

Automated daily backups are configured (see VPS_SETUP.md):

```bash
# Manual backup
bash /home/deploy/smsgang/backup-db.sh

# Restore from backup
mysql -u smsgang -p smsgang < /home/deploy/smsgang/backups/latest.sql
```

### SSL/TLS Certificates

Use Let's Encrypt for free SSL (see VPS_SETUP.md):

```bash
# Initial setup
sudo certbot certonly --standalone -d your-domain.com

# Auto-renewal (cron job runs daily)
0 3 * * * certbot renew --quiet && docker exec smsgang-nginx nginx -s reload
```

### Production Checklist

- [ ] VPS provisioned and Docker installed (see VPS_SETUP.md)
- [ ] GitHub secrets configured (VPS_HOST, VPS_USER, VPS_SSH_KEY, GH_PAT)
- [ ] `.env.production` created with real API keys
- [ ] SSL certificate obtained from Let's Encrypt
- [ ] First deployment tested
- [ ] Database migrations successful
- [ ] Dozzle logs accessible
- [ ] Queue workers processing jobs
- [ ] Backups configured
- [ ] Monitoring alerts set up

## Troubleshooting

### **Containers won't start**

```bash
# Check logs
docker-compose logs app

# Ensure ports aren't in use
sudo lsof -i :8000 :3306 :6379

# Full rebuild
docker-compose down -v
docker-compose up --build -d
```

### **Database connection failed**

```bash
# Wait a bit longer for MySQL to initialize
sleep 30
docker-compose restart app

# Check MySQL logs
docker-compose logs mysql
```

### **Queue workers not running**

```bash
# Check supervisor status
docker-compose exec app supervisorctl status

# Restart supervisor
docker-compose exec app supervisorctl restart all

# Check logs
docker-compose logs app | grep worker
```

### **Jobs not being processed**

```bash
# Check Redis connection
docker-compose exec redis redis-cli PING

# Check queue
docker-compose exec app php artisan queue:failed

# Monitor queue size
docker-compose exec redis redis-cli LLEN queues:default
```

### **Out of memory or disk**

```bash
# Clean up unused Docker resources
docker system prune -a --volumes

# Check disk usage
docker system df

# Increase allocated memory/disk in Docker Desktop settings
```

## File Structure

```
backend/
├── Dockerfile                    # Production image definition
├── docker-compose.yml            # Local development (hot-reload)
├── docker-compose.prod.yml       # Production deployment
├── docker/
│   ├── nginx.conf               # Nginx web server config
│   ├── php.ini                  # PHP optimization settings
│   ├── supervisor.conf          # Supervisor process management
│   ├── entrypoint.sh            # Container startup script
│   └── mysql-init/              # MySQL initialization scripts
├── .dockerignore                # Files to exclude from image
├── .env.docker                  # Local development env vars
├── .env.production              # Production env template
├── deploy.sh                    # VPS deployment script
├── routes/console.php           # Background job scheduling (Laravel 12)
└── ...other app files
```

## Performance Tips

### Local Development
- Disable xdebug if not needed in php.ini
- Use volumes with caching where possible
- Consider using named volumes for better performance on Mac/Windows

### Production
- Set `APP_DEBUG=false`
- Enable opache with proper settings
- Use separate Redis instance for sessions/cache/queue
- Implement rate limiting at reverse proxy level
- Monitor queue depth regularly

## Support

For issues or questions about this Docker setup, refer to:
- [Docker Documentation](https://docs.docker.com/)
- [Laravel Docker Guide](https://laravel.com/docs/11.x#docker)
- [Supervisor Documentation](http://supervisord.org/)

---

**Last Updated**: March 14, 2026
**Version**: 1.0
