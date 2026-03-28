# GitHub Actions Setup Guide

Configure GitHub Actions CI/CD for automated Docker builds and VPS deployment.

## Overview

The GitHub Actions workflow (`.github/workflows/backend-deploy.yml`) automatically:

1. **Build** — Creates Docker image and pushes to GitHub Container Registry (ghcr.io)
2. **Test** — Runs PHP unit tests with MySQL and Redis services
3. **Deploy** — Copies files to VPS, pulls image, and starts containers

The workflow triggers on every push to the `main` branch.

---

## Step 1: Create GitHub Personal Access Token (PAT)

### Generate PAT for Container Registry

1. Go to [GitHub Settings → Developer Settings → Personal Access Tokens](https://github.com/settings/tokens)
2. Click **Generate new token** (Classic)
3. Set name: `GH_PAT_CONTAINER_REGISTRY`
4. Select scopes:
   - ✅ `read:packages`
   - ✅ `write:packages`
   - ✅ `delete:packages`
5. Click **Generate token**
6. **Copy the token** (you won't see it again)

---

## Step 2: Generate SSH Key Pair

Generate a new SSH key for VPS deployment (or use existing):

```bash
# Generate new key
ssh-keygen -t rsa -b 4096 -f ~/.ssh/github_deploy -C "github-actions"

# View public key (you'll add this to VPS)
cat ~/.ssh/github_deploy.pub

# View private key (you'll add this to GitHub secrets)
cat ~/.ssh/github_deploy
```

**Important**: Keep the private key secure. Only the public key goes on the VPS.

---

## Step 3: Configure VPS SSH Access

On your VPS server:

```bash
# As deploy user
su - deploy

# Create .ssh directory if not exists
mkdir -p ~/.ssh
chmod 700 ~/.ssh

# Add GitHub's public key
echo "ssh-rsa AAAAB3NzaC1yc2E..." >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys

# Test SSH access from local machine
ssh -i ~/.ssh/github_deploy deploy@your.vps.com
# Should connect without password
```

---

## Step 4: Add GitHub Secrets

In your GitHub repository:

1. Go to **Settings** → **Secrets and variables** → **Actions**
2. Click **New repository secret** for each:

### VPS_HOST

- **Name**: `VPS_HOST`
- **Value**: Your VPS IP or domain (e.g., `app.example.com`)
- **Purpose**: Tells GitHub Actions where to deploy

### VPS_USER

- **Name**: `VPS_USER`
- **Value**: `deploy` (or your SSH username)
- **Purpose**: SSH user for deployment

### VPS_SSH_KEY

- **Name**: `VPS_SSH_KEY`
- **Value**: **Paste entire private key** from `~/.ssh/github_deploy`
- **Purpose**: Authenticates GitHub Actions to your VPS

```
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEA...
(entire key content)
-----END OPENSSH PRIVATE KEY-----
```

### GH_PAT

- **Name**: `GH_PAT`
- **Value**: Your GitHub Personal Access Token from Step 1
- **Purpose**: Authenticates to ghcr.io container registry

### DOCKER_REGISTRY (Optional)

- **Name**: `DOCKER_REGISTRY`
- **Value**: `ghcr.io`
- **Purpose**: Container registry location

### DOCKER_IMAGE (Optional)

- **Name**: `DOCKER_IMAGE`
- **Value**: `dollarhunter/smsgang-backend`
- **Purpose**: Image name in registry

---

## Step 5: Verify Secrets Configuration

View configured secrets in GitHub (note: values are hidden):

```
✅ VPS_HOST       = set
✅ VPS_USER       = set
✅ VPS_SSH_KEY    = set
✅ GH_PAT         = set
✅ DOCKER_REGISTRY = set (optional)
✅ DOCKER_IMAGE   = set (optional)
```

---

## Step 6: Test Deployment Pipeline

### Trigger Workflow Manually

1. Go to your GitHub repository
2. Click **Actions** tab
3. Select **Backend Deploy** workflow
4. Click **Run workflow** → **Use workflow from Branch: main**
5. Click green **Run workflow** button

The workflow will:
- ℹ️ Skip build if no code changes (uses cache)
- ✅ Test PHP code
- 🚀 Deploy to VPS (if test passes)

### Monitor Pipeline

1. Click the running workflow
2. Click the job name to see real-time logs
3. Watch for success ✅ or failure ❌

---

## Step 7: Automatic Deployment

Once secrets are configured, deployment happens automatically:

```
Your local machine → git push origin main
                         ↓
GitHub → Detects push to main branch
              ↓
GitHub Actions → Builds Docker image
                        ↓
             → Pushes to ghcr.io
                        ↓
             → Runs PHP tests
                        ↓
             → SSHs into VPS
                        ↓
             → Pulls new image
                        ↓
             → Restarts containers
                        ↓
VPS → Running latest code ✅
```

---

## Step 8: Workflow File Structure

The workflow (`.github/workflows/backend-deploy.yml`) has 3 jobs:

### Job 1: **build**

- Builds Docker image with buildx
- Pushes to `ghcr.io` with latest tag
- Uses cache for faster builds
- Requires: DOCKER_REGISTRY, DOCKER_IMAGE, GH_PAT

### Job 2: **test**

- Runs PHP unit tests
- Starts temporary MySQL and Redis services
- Validates code before deployment
- Runs regardless of build results

### Job 3: **deploy**

- Runs only if main branch
- Runs only if build and test succeed
- Copies files via SCP
- Runs commands via SSH
- Pulls latest image and restarts containers

---

## Troubleshooting

### "Permission denied (publickey)"

**Cause**: SSH key not configured or VPS user doesn't have key

**Solution**:
1. Verify public key on VPS: `cat ~/.ssh/authorized_keys`
2. Test manually: `ssh -i ~/.ssh/github_deploy deploy@your.vps.com`
3. Update GitHub secret if key has changed

### "Docker pull failed: unauthorized"

**Cause**: GH_PAT token expired or incorrect

**Solution**:
1. Generate new PAT in GitHub
2. Update `GH_PAT` secret
3. Re-run workflow

### "Build cache not found"

**Cause**: First build or registry cache cleared

**Solution**: Normal behavior, image builds from scratch. Future builds use cache.

### "Containers won't start on VPS"

**Solution**:
```bash
ssh deploy@your.vps.com
cd /home/deploy/smsgang

# Check logs
docker-compose -f docker-compose.prod.yml logs app

# Restart services
docker-compose -f docker-compose.prod.yml down
docker-compose -f docker-compose.prod.yml up -d

# Verify .env
cat .env | grep DB_
```

### "Can't find workflow file"

**Solution**: Ensure `.github/workflows/backend-deploy.yml` exists in repository root

```bash
ls -la .github/workflows/
# Should show: backend-deploy.yml
```

---

## Advanced: Custom Triggers

Edit `.github/workflows/backend-deploy.yml` to trigger on different events:

```yaml
# Current: Push to main only
on:
  push:
    branches: [main]

# Alternative: Also deploy on tags
on:
  push:
    branches: [main]
    tags: ['v*']

# Alternative: Manual trigger
on:
  workflow_dispatch

# Alternative: Scheduled deployment (daily at 2 AM)
on:
  schedule:
    - cron: '0 2 * * *'
```

---

## Advanced: Multiple Environments

To deploy to different environments (staging, production):

```yaml
# In secrets, create:
# STAGING_VPS_HOST, STAGING_VPS_USER, STAGING_VPS_SSH_KEY
# PROD_VPS_HOST, PROD_VPS_USER, PROD_VPS_SSH_KEY

# In workflow:
- name: Deploy to ${{ env.TARGET_ENV }}
  env:
    VPS_HOST: ${{ secrets[format('{0}_VPS_HOST', env.TARGET_ENV)] }}
    VPS_USER: ${{ secrets[format('{0}_VPS_USER', env.TARGET_ENV)] }}
    VPS_SSH_KEY: ${{ secrets[format('{0}_VPS_SSH_KEY', env.TARGET_ENV)] }}
```

---

## Security Best Practices

1. **SSH Keys**: Use separate key for GitHub Actions, not personal key
2. **PAT Scopes**: Use minimum required scopes (read/write packages only)
3. **Secret Rotation**: Rotate PAT and SSH keys periodically
4. **Branch Protection**: Require tests pass before merging to main
5. **Audit Logs**: Monitor GitHub Actions logs for unusual activity

---

## Monitoring & Alerts

### View Workflow Runs

In GitHub repository:
- **Actions** tab → See all workflow runs
- Click run → See job details and logs
- Star your repo if interesting features!

### Email Notifications

- GitHub sends notifications for failed workflows by default
- Configure in **Settings → Notifications**

### Discord/Slack Notifications

Add to `.github/workflows/backend-deploy.yml`:

```yaml
- name: Notify Slack
  if: failure()
  uses: slackapi/slack-github-action@v1
  with:
    webhook-url: ${{ secrets.SLACK_WEBHOOK }}
    payload: |
      {
        "text": "❌ Deployment failed",
        "blocks": [
          {
            "type": "section",
            "text": {
              "type": "mrkdwn",
              "text": "Workflow: ${{ github.workflow }}\nBranch: ${{ github.ref }}"
            }
          }
        ]
      }
```

---

## Quick Reference

| Task | Command |
|------|---------|
| Trigger pipeline | `git push origin main` |
| Manual trigger | GitHub UI → Actions → Run workflow |
| View logs | GitHub UI → Actions → Click run |
| Check secrets | Settings → Secrets (values hidden) |
| Update secret | Settings → Secrets → Update → Save |
| Disable workflow | GitHub UI → Actions → Disable workflow |

---

## Next Steps

1. ✅ Create GitHub PAT
2. ✅ Generate SSH key pair
3. ✅ Add public key to VPS
4. ✅ Configure GitHub secrets
5. ✅ Push code to main branch
6. ✅ Monitor first deployment in Actions tab
7. ✅ Verify deployment on VPS

Once configured, deployments happen automatically on every push to `main`! 🚀

---

**Last Updated**: 2025-01-16
**Workflow Version**: 2.x
**Compatible with**: GitHub Actions default runners
