# Production Deployment Checklist

Quick reference for deploying SMSGang backend to production.

## 📋 Pre-Deployment (One-time Setup)

### VPS Preparation
- [ ] VPS provisioned (Ubuntu 20.04+)
- [ ] SSH access configured
- [ ] Read [VPS_SETUP.md](VPS_SETUP.md) completely
- [ ] Docker & Docker Compose installed on VPS
- [ ] Deploy user created (`deploy`)
- [ ] SSH public key added to `~/.ssh/authorized_keys`
- [ ] VPS firewall configured (22, 80, 443 open)
- [ ] Directory `/home/deploy/smsgang` created
- [ ] Persistent storage directories created
- [ ] `.env` file created on VPS with production secrets
- [ ] SSL certificate obtained from Let's Encrypt
- [ ] Certbot auto-renewal cron job configured

### GitHub Configuration
- [ ] Read [GITHUB_ACTIONS_SETUP.md](GITHUB_ACTIONS_SETUP.md) completely
- [ ] GitHub PAT created (read/write packages)
- [ ] SSH key pair generated for GitHub Actions
- [ ] GitHub secrets configured:
  - [ ] `VPS_HOST` = your VPS IP/domain
  - [ ] `VPS_USER` = `deploy`
  - [ ] `VPS_SSH_KEY` = private SSH key
  - [ ] `GH_PAT` = GitHub Personal Access Token
  - [ ] `DOCKER_REGISTRY` = `ghcr.io` (optional)
  - [ ] `DOCKER_IMAGE` = your image name (optional)
- [ ] GitHub Actions workflow file exists: `.github/workflows/backend-deploy.yml`

### Code Preparation
- [ ] All code committed to git
- [ ] `.env.production` template updated in repository
- [ ] `docker-compose.prod.yml` reviewed
- [ ] Nginx config reviewed: `server/default-production.conf`
- [ ] `deploy.sh` script reviewed

---

## 🚀 First Deployment

### Step 1: Verify VPS Readiness
```bash
# Login to VPS
ssh deploy@your.vps.com

# Check Docker
docker --version
docker-compose --version

# Check directories
ls -la /home/deploy/smsgang
cat /home/deploy/smsgang/.env | grep APP_

# Check SSL certificate
ls -la /etc/letsencrypt/live/your-domain/

# Exit VPS
exit
```

**Status**: ✅ VPS ready if all checks pass

### Step 2: Verify GitHub Secrets
```bash
# In GitHub repo Settings → Secrets and variables → Actions
# Verify (values hidden but should show "set"):
✅ VPS_HOST
✅ VPS_USER
✅ VPS_SSH_KEY
✅ GH_PAT
```

**Status**: ✅ Secrets configured if all show "set"

### Step 3: Trigger Deployment

**Option A: Automatic (Recommended)**
```bash
# From your local machine
cd backend

# Make a change or just commit
git add .
git commit -m "Deploy to production" || true
git push origin main

# GitHub Actions automatically:
# - Builds Docker image
# - Pushes to ghcr.io
# - Tests PHP code
# - Deploys to VPS
# - Runs migrations
# - Restarts services
```

**Option B: Manual Deployment Script**
```bash
# From your local machine
export VPS_HOST=your.vps.com
export VPS_USER=deploy
bash backend/deploy.sh
```

### Step 4: Monitor Deployment

**Via GitHub Actions:**
1. Go to your repo → **Actions** tab
2. Find the running workflow
3. Click to see real-time logs
4. Wait for ✅ success

**Via VPS logs:**
```bash
ssh deploy@your.vps.com
cd /home/deploy/smsgang

# Watch deployment progress
docker-compose -f docker-compose.prod.yml logs -f app

# Check container status
docker-compose -f docker-compose.prod.yml ps

# Exit
exit
```

### Step 5: Verify Deployment Success

```bash
# Check API health
curl -i https://your-domain.com/api/health

# Check Dozzle logs (from browser)
# http://your-domain.com:9001

# Check Swagger API docs (from browser)
# http://your-domain.com:9002

# Check database via Adminer (from browser)
# http://your-domain.com:8080
# User: smsgang
# Password: (from .env DB_PASSWORD)
```

**Status**: ✅ Deployment successful if all checks pass

---

## 📊 Post-Deployment Verification

### Database
```bash
ssh deploy@your.vps.com
cd /home/deploy/smsgang

# Check migrations completed
docker-compose -f docker-compose.prod.yml exec -T app php artisan migrate:status

# Verify tables exist
docker-compose -f docker-compose.prod.yml exec mysql mysql -u smsgang -p -e "SHOW TABLES;" smsgang

exit
```

### Queue Workers
```bash
ssh deploy@your.vps.com
cd /home/deploy/smsgang

# Monitor workers
docker-compose -f docker-compose.prod.yml logs -f app | grep "worker"

# Check failed jobs
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:failed

exit
```

### Scheduler
```bash
ssh deploy@your.vps.com
cd /home/deploy/smsgang

# Monitor scheduler execution
docker-compose -f docker-compose.prod.yml logs -f app | grep "scheduler"

exit
```

---

## 🔄 Subsequent Deployments

Once initial setup is complete, deployments are simple:

### Standard Deployment
```bash
# Make code changes
git add .
git commit -m "Fix: description of changes"
git push origin main

# GitHub Actions automatically deploys!
```

### No-Code Deployment (Config Change)
```bash
# Update .env on VPS manually
ssh deploy@your.vps.com
nano /home/deploy/smsgang/.env

# Restart containers to pick up changes
cd /home/deploy/smsgang
docker-compose -f docker-compose.prod.yml restart
exit
```

### Rollback to Previous Version
```bash
ssh deploy@your.vps.com
cd /home/deploy/smsgang

# View image history
docker image history ghcr.io/your-image:latest | head

# Restart with previous image tag
docker-compose -f docker-compose.prod.yml down
docker image tag ghcr.io/your-image:previous ghcr.io/your-image:latest
docker-compose -f docker-compose.prod.yml up -d

# Run migrations if needed
docker-compose -f docker-compose.prod.yml exec -T app php artisan migrate

exit
```

---

## 🛡️ Important Commands Reference

```bash
# SSH to VPS
ssh deploy@your.vps.com

# View logs
cd /home/deploy/smsgang
docker-compose -f docker-compose.prod.yml logs -f app

# Restart services
docker-compose -f docker-compose.prod.yml restart

# Stop everything
docker-compose -f docker-compose.prod.yml down

# Start everything
docker-compose -f docker-compose.prod.yml up -d

# Run migrations
docker-compose -f docker-compose.prod.yml exec -T app php artisan migrate --force

# Clear cache
docker-compose -f docker-compose.prod.yml exec -T app php artisan cache:clear

# View container status
docker-compose -f docker-compose.prod.yml ps

# Prune Docker (cleanup)
docker system prune -a

# Check disk space
df -h

# Exit VPS
exit
```

---

## 🆘 Common Issues

### "Containers won't start"
1. SSH to VPS
2. Run: `docker-compose -f docker-compose.prod.yml logs app`
3. Check error message
4. Usually: `.env` file issue or database password mismatch

### "Database migration fails"
```bash
# Check .env database credentials
cat .env | grep DB_

# Verify MySQL service is running
docker-compose -f docker-compose.prod.yml ps mysql

# Test connection
docker-compose -f docker-compose.prod.yml exec mysql mysql -u smsgang -p -e "SELECT 1;"
```

### "API returns 500 error"
```bash
# Check app logs
docker-compose -f docker-compose.prod.yml logs app | tail -50

# Check Dozzle UI: http://your-domain.com:9001
```

### "Queue workers not processing jobs"
```bash
# Check worker logs
docker-compose -f docker-compose.prod.yml logs app | grep worker

# Check failed jobs
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:failed

# Retry failed jobs
docker-compose -f docker-compose.prod.yml exec -T app php artisan queue:retry all
```

### "SSL certificate error"
```bash
# Check certificate expiry
sudo certbot certificates

# Manual renewal
sudo certbot renew

# Restart Nginx to load new cert
docker-compose -f docker-compose.prod.yml restart nginx
```

---

## 📞 Support Resources

- **[VPS_SETUP.md](VPS_SETUP.md)** — Comprehensive VPS preparation guide
- **[GITHUB_ACTIONS_SETUP.md](GITHUB_ACTIONS_SETUP.md)** — GitHub Actions secrets configuration
- **[DOCKER.md](DOCKER.md)** — Docker local & production setup
- **[JOBS.md](JOBS.md)** — Background job scheduling
- **GitHub Actions Logs** — Detailed deployment logs in Actions tab
- **Dozzle UI** — Real-time container logs at http://your-domain.com:9001

---

## ✅ Success Indicators

Deployment is successful when:
- ✅ GitHub Actions workflow shows green checkmark
- ✅ `curl https://your-domain.com/api/health` returns 200
- ✅ Dozzle logs show no error messages
- ✅ Database tables exist and migrations completed
- ✅ Queue workers are running and processing jobs
- ✅ Scheduler is executing jobs on schedule
- ✅ SSL certificate is valid (https:// works)

---

**Happy Deploying! 🚀**

For detailed troubleshooting, see [VPS_SETUP.md](VPS_SETUP.md#troubleshooting)
