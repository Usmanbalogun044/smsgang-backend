# SMSGang Backend

Premium SMS activation platform built with Laravel 12, Redis queues, and Docker. Supports 5SIM provisioning, Lendoverify payment processing, and live exchange rate syncing.

## Quick Links

- 🚀 **[DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)** — Start here! Step-by-step deployment guide
- 🐳 **[DOCKER.md](DOCKER.md)** — Local development & production deployment with Docker
- 🖥️ **[VPS_SETUP.md](VPS_SETUP.md)** — Complete VPS preparation (firewall, SSL, backups)
- 🔑 **[GITHUB_ACTIONS_SETUP.md](GITHUB_ACTIONS_SETUP.md)** — CI/CD secrets & GitHub Actions configuration
- 📋 **[JOBS.md](JOBS.md)** — Background job scheduling and management
- 📚 **[Makefile](Makefile)** — 30+ useful Docker and development commands

## Project Overview

### Technology Stack

| Component | Technology | Version |
|-----------|-----------|---------|
| Framework | Laravel | 12.x |
| Language | PHP | 8.2-FPM |
| Database | MySQL | 8.0 |
| Cache/Queue | Redis | 7.x |
| Container | Docker | 24.0+ |
| Orchestration | Docker Compose | 2.24+ |
| Process Manager | Supervisor | 4.x |
| Web Server | Nginx | Alpine |
| CI/CD | GitHub Actions | - |
| Container Registry | GHCR | ghcr.io |

### Key Features

- **Payment Processing**: Lendoverify gateway with transaction audit logging
- **SMS Activation**: 5SIM integration for phone number provisioning
- **Background Jobs**: Redis queues with 8 concurrent workers
- **Task Scheduling**: Auto-pricing sync, SMS polling, activation expiration
- **Rate Conversion**: RapidAPI currency API with hourly syncing
- **Admin Dashboard**: Transaction monitoring, order management, analytics
- **API Documentation**: Swagger UI for easy endpoint discovery
- **Container Logs**: Dozzle real-time log viewer
- **Database Admin**: Adminer for quick database inspection

---

## Getting Started

### Local Development (5 minutes)

```bash
# Clone and setup
git clone <repo>
cd backend

# Start Docker environment
docker-compose up -d

# Run migrations (auto-run on compose up)
docker-compose exec app php artisan migrate

# View logs
docker-compose logs -f app

# Access services
# API: http://localhost:8000
# Database: http://localhost:8080 (Adminer)
```

### Production Deployment (30 minutes)

See [DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md) for step-by-step guide.

---

## Contributing

Thank you for considering contributing! Please:

1. Create feature branch: `git checkout -b feature/your-feature`
2. Make changes and test locally
3. Commit with clear message: `git commit -m "feat: description"`
4. Push and create pull request
5. Once merged to main, GitHub Actions auto-deploys

## License

This project is proprietary. All rights reserved.

---

**Last Updated**: 2025-01-16
