# VPS Setup Guide for SMSGang Backend

Complete guide to prepare your VPS for automated Docker deployment via GitHub Actions.

## 1️⃣ Prerequisites

- VPS running Ubuntu 20.04+ (or Debian 11+)
- SSH access with sudo privileges
- Registered domain name (for SSL)
- GitHub account with repository access
- GitHub Personal Access Token for container registry

---

## 2️⃣ VPS System Setup

### Install Docker & Docker Compose

```bash
# Update system
sudo apt-get update && sudo apt-get upgrade -y

# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Add current user to docker group
sudo usermod -aG docker $USER
newgrp docker

# Install Docker Compose
sudo curl -L "https://github.com/docker/compose/releases/download/v2.24.0/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

# Verify installation
docker --version
docker-compose --version
```

### Configure Firewall

```bash
# Enable UFW firewall
sudo ufw enable
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Allow SSH, HTTP, HTTPS
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Block monitoring ports (internal only)
sudo ufw default deny 9001
sudo ufw default deny 9002
sudo ufw default deny 8080

# Verify rules
sudo ufw status
```

### Create Deployment User

```bash
# Create deploy user
sudo useradd -m -s /bin/bash deploy
sudo usermod -aG docker deploy

# Create SSH directory
sudo mkdir -p /home/deploy/.ssh
sudo chmod 700 /home/deploy/.ssh

# Add your public SSH key (from your local machine)
# First, copy your local ~/.ssh/id_rsa.pub content, then:
sudo nano /home/deploy/.ssh/authorized_keys
# Paste your public key, then Ctrl+X, Y, Enter

# Set permissions
sudo chmod 600 /home/deploy/.ssh/authorized_keys
sudo chown -R deploy:deploy /home/deploy/.ssh
```

### Create Deployment Directory

```bash
# Create project directory
sudo mkdir -p /home/deploy/smsgang
sudo chown -R deploy:deploy /home/deploy/smsgang

# Create persistent storage directories
sudo mkdir -p /home/deploy/smsgang/storage/uploads
sudo mkdir -p /home/deploy/smsgang/storage/logs
sudo mkdir -p /home/deploy/smsgang/mysql-data
sudo mkdir -p /home/deploy/smsgang/redis-data
sudo chown -R deploy:deploy /home/deploy/smsgang/storage
sudo chown -R deploy:deploy /home/deploy/smsgang/mysql-data
sudo chown -R deploy:deploy /home/deploy/smsgang/redis-data

# Verify directory structure
ls -la /home/deploy/smsgang
```

---

## 3️⃣ Docker Registry Authentication

### Login to GitHub Container Registry

```bash
# On your VPS
cd /home/deploy/smsgang

# Create .dockerconfigjson (or use docker login)
echo $GH_PAT | docker login ghcr.io -u USERNAME --password-stdin

# Verify login
docker pull ghcr.io/dollarhunter/smsgang-backend:latest

# Grant deploy user Docker access (if not already done)
sudo usermod -aG docker deploy
```

---

## 4️⃣ SSL/TLS Setup with Let's Encrypt

### Install Certbot

```bash
sudo apt-get install -y certbot python3-certbot-nginx

# Create certificate (replace example.com with your domain)
sudo certbot certonly --nginx -d example.com -d www.example.com

# Verify certificate
sudo certbot certificates
```

### Configure Nginx SSL in docker-compose.prod.yml

After getting certificates, update `server/default-production.conf`:

```nginx
# Use your actual certificate paths
ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
```

### Auto-renewal Cron Job

```bash
# Add to crontab (runs daily)
sudo crontab -e

# Add this line:
0 3 * * * certbot renew --quiet && docker exec smsgang-nginx nginx -s reload

# Verify crontab
sudo crontab -l
```

---

## 5️⃣ Environment Configuration

### Create .env File

```bash
sudo nano /home/deploy/smsgang/.env
```

Add the following (replace with your actual values):

```bash
# App
APP_NAME=SMSGang
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:YOUR_APP_KEY_HERE  # Generate with: php artisan key:generate
APP_URL=https://example.com
DOMAIN=example.com

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=smsgang
DB_USERNAME=smsgang
DB_PASSWORD=YOUR_SECURE_DB_PASSWORD
DB_ROOT_PASSWORD=YOUR_SECURE_ROOT_PASSWORD

# Cache & Queue
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=YOUR_SECURE_REDIS_PASSWORD  # Or leave empty if no password

# Mail (configure your SMTP provider)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_FROM_ADDRESS=noreply@example.com

# Payment Gateway (Lendoverify)
LENDOVERIFY_API_KEY=your_lendoverify_api_key
LENDOVERIFY_PUBLIC_KEY=your_lendoverify_public_key
LENDOVERIFY_BASE_URL=https://api.lendoverify.com

# 5SIM (SMS activation)
FIVESIM_API_KEY=your_5sim_api_key
FIVESIM_BASE_URL=https://5sim.com/api

# Hooks/Webhooks
LENDOVERIFY_WEBHOOK_SECRET=your_webhook_secret_key

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=debug

# Location defaults (Nigeria)
DEFAULT_COUNTRY=NG
DEFAULT_CURRENCY=NGN
```

Set proper permissions:

```bash
sudo chmod 600 /home/deploy/smsgang/.env
sudo chown deploy:deploy /home/deploy/smsgang/.env
```

---

## 6️⃣ GitHub Actions Secrets Configuration

In your GitHub repository settings, add these secrets (Settings → Secrets and variables → Actions):

```
VPS_HOST          = your.vps.com (or IP)
VPS_USER          = deploy
VPS_SSH_KEY       = (paste your private SSH key from ~/.ssh/id_rsa)
GH_PAT            = ghp_xxxxxxxxxxxx (GitHub Personal Access Token)
DOCKER_REGISTRY   = ghcr.io
DOCKER_IMAGE      = dollarhunter/smsgang-backend
```

---

## 7️⃣ First Deployment

### Option A: Via GitHub Actions (Recommended)

```bash
# Push your changes to main branch
git add .
git commit -m "Update Docker & deployment configs"
git push origin main

# GitHub Actions will automatically:
# 1. Build Docker image
# 2. Push to ghcr.io
# 3. Deploy to VPS
# 4. Run migrations
# 5. Optimize application

# Monitor in GitHub UI: Actions tab
```

### Option B: Manual Deployment

```bash
# From your local machine
export VPS_HOST=your.vps.com
export VPS_USER=deploy
export DOCKER_REGISTRY=ghcr.io
export DOCKER_IMAGE=dollarhunter/smsgang-backend
export DOCKER_TAG=latest

bash backend/deploy.sh
```

---

## 8️⃣ Post-Deployment Verification

### Check Services Status

```bash
# SSH into VPS
ssh deploy@your.vps.com

# Check running containers
cd /home/deploy/smsgang
docker-compose -f docker-compose.prod.yml ps

# Check logs
docker-compose -f docker-compose.prod.yml logs -f app
```

### Verify API Health

```bash
# From local machine
curl -i https://your.vps.com/api/health

# Should return 200 OK with health status
```

### Access Monitoring Tools

- **Dozzle** (Container Logs): http://your.vps.com:9001
- **Swagger UI** (API Docs): http://your.vps.com:9002
- **Adminer** (Database UI): http://your.vps.com:8080

---

## 9️⃣ Database Migration & Seeding

### Initial Database Setup

```bash
# SSH into VPS
ssh deploy@your.vps.com
cd /home/deploy/smsgang

# Run migrations
docker-compose -f docker-compose.prod.yml exec -T app php artisan migrate --force

# Seed initial data
docker-compose -f docker-compose.prod.yml exec -T app php artisan db:seed

# Verify database
docker-compose -f docker-compose.prod.yml exec mysql mysql -u smsgang -p$DB_PASSWORD smsgang -e "SHOW TABLES;"
```

---

## 🔟 Backup Strategy

### Daily Database Backup

```bash
# Create backup script
sudo nano /home/deploy/smsgang/backup-db.sh
```

```bash
#!/bin/bash
BACKUP_DIR="/home/deploy/smsgang/backups"
mkdir -p $BACKUP_DIR
cd /home/deploy/smsgang

docker-compose -f docker-compose.prod.yml exec -T mysql mysqldump \
  -u smsgang -p$(grep DB_PASSWORD .env | cut -d= -f2) \
  $(grep DB_DATABASE .env | cut -d= -f2) \
  | gzip > $BACKUP_DIR/db_backup_$(date +%Y%m%d_%H%M%S).sql.gz

# Keep only last 30 days
find $BACKUP_DIR -name "*.sql.gz" -mtime +30 -delete

echo "✅ Backup completed"
```

Make executable and add to crontab:

```bash
sudo chmod +x /home/deploy/smsgang/backup-db.sh

# Run daily at 2 AM
0 2 * * * /home/deploy/smsgang/backup-db.sh
```

---

## 1️⃣1️⃣ Monitoring & Logging

### View Real-time Logs

```bash
# App logs
docker-compose -f docker-compose.prod.yml logs -f app

# Queue worker logs
docker-compose -f docker-compose.prod.yml logs -f app | grep "queue:work"

# Nginx access/error
docker-compose -f docker-compose.prod.yml logs -f nginx

# All services
docker-compose -f docker-compose.prod.yml logs -f
```

### Monitor System Resources

```bash
# Check container resource usage
docker stats

# Check disk space
df -h

# Check memory usage
free -h
```

---

## 1️⃣2️⃣ Troubleshooting

### Containers Won't Start

```bash
# Check docker daemon
docker ps

# View container logs
docker logs <container_id>

# Restart services
docker-compose -f docker-compose.prod.yml restart

# Full rebuild
docker-compose -f docker-compose.prod.yml down
docker-compose -f docker-compose.prod.yml up -d
```

### Database Connection Issues

```bash
# Test MySQL connection
docker-compose -f docker-compose.prod.yml exec mysql mysql -u smsgang -p -e "SELECT 1;"

# Check Redis connection
docker-compose -f docker-compose.prod.yml exec redis redis-cli ping

# Verify .env file
cat /home/deploy/smsgang/.env | grep DB_
```

### SSL Certificate Issues

```bash
# Check certificate expiry
sudo certbot certificates

# Manual renewal
sudo certbot renew

# Update Nginx config and reload
docker-compose -f docker-compose.prod.yml exec nginx nginx -s reload
```

### Worker Queue Stuck

```bash
# Check queue status
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:failed

# Retry failed jobs
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:retry all

# Flush queue
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:flush
```

---

## 1️⃣3️⃣ Quick Reference Commands

```bash
# Deploy script (local machine)
bash backend/deploy.sh

# View deployment logs
docker logs smsgang-app-1

# Restart all services
docker-compose -f docker-compose.prod.yml restart

# Scale queue workers
# Edit docker/supervisor.conf: numprocs=8
docker-compose -f docker-compose.prod.yml restart

# Clear application cache
docker-compose -f docker-compose.prod.yml exec -T app php artisan cache:clear

# View Makefile commands
make help

# Production commands in Makefile
make prod-up        # Start services
make prod-down      # Stop services  
make prod-logs      # View logs
make prod-migrate   # Run migrations
make prod-ps        # Show container status
```

---

## 📞 Support

For issues or questions:
1. Check logs: `docker-compose -f docker-compose.prod.yml logs -f`
2. Review Dozzle: http://your.vps.com:9001
3. Check GitHub Actions: Full deployment logs available in repo Actions tab
4. SSH into VPS and test manually

---

**Last Updated**: 2025-01-16
**Docker Version**: 2.24.0+
**Docker Compose Version**: v2.24.0+
**Target OS**: Ubuntu 20.04+
