# SMSGang Backend Makefile

.PHONY: help build up down logs shell migrate test clean

help:
	@echo "SMSGang Backend Docker Commands"
	@echo "================================="
	@echo ""
	@echo "make build              Build Docker images"
	@echo "make up                 Start all containers"
	@echo "make down               Stop all containers"
	@echo "make logs               View container logs"
	@echo "make shell              Access app container shell"
	@echo "make migrate            Run database migrations"
	@echo "make seed               Seed database with test data"
	@echo "make migrate-fresh      Fresh migration + seed"
	@echo "make tinker             Open Laravel Tinker REPL"
	@echo "make queue-failed       Show failed queue jobs"
	@echo "make queue-retry-all    Retry all failed jobs"
	@echo "make cache-clear        Clear application cache"
	@echo "make test               Run test suite"
	@echo "make lint               Run code linter"
	@echo "make format             Format code with Pint"
	@echo "make composer-install   Install Composer dependencies"
	@echo "make composer-update    Update Composer dependencies"
	@echo "make clean              Remove containers and volumes"
	@echo ""

build:
	docker-compose build

up:
	docker-compose up -d
	@echo "✅ Containers started"
	@echo "🌐 Backend: http://localhost:8000"
	@echo "📊 Database: http://localhost:8080"

down:
	docker-compose down
	@echo "✅ Containers stopped"

logs:
	docker-compose logs -f app

logs-scheduler:
	docker-compose logs -f app | grep scheduler

logs-worker:
	docker-compose logs -f app | grep worker

logs-mysql:
	docker-compose logs -f mysql

logs-redis:
	docker-compose logs -f redis

shell:
	docker-compose exec app sh

migrate:
	docker-compose exec app php artisan migrate

seed:
	docker-compose exec app php artisan db:seed

migrate-fresh:
	docker-compose exec app php artisan migrate:fresh --seed
	@echo "✅ Database fresh migrated and seeded"

tinker:
	docker-compose exec app php artisan tinker

queue-failed:
	docker-compose exec app php artisan queue:failed

queue-retry-all:
	docker-compose exec app php artisan queue:retry --all

cache-clear:
	docker-compose exec app php artisan cache:clear
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear
	@echo "✅ Cache cleared"

test:
	docker-compose exec app php artisan test

test-coverage:
	docker-compose exec app php artisan test --coverage

lint:
	docker-compose exec app composer run lint

format:
	docker-compose exec app composer run format

composer-install:
	docker-compose exec app composer install

composer-update:
	docker-compose exec app composer update

clean:
	docker-compose down -v
	@echo "✅ All containers and volumes removed"

fresh:
	$(MAKE) clean
	$(MAKE) build
	$(MAKE) up
	@echo "✅ Fresh Docker environment ready"

# Production commands
prod-up:
	docker-compose -f docker-compose.prod.yml up -d

prod-down:
	docker-compose -f docker-compose.prod.yml down

prod-logs:
	docker-compose -f docker-compose.prod.yml logs -f app

prod-migrate:
	docker-compose -f docker-compose.prod.yml exec app php artisan migrate --force

prod-backup:
	docker-compose -f docker-compose.prod.yml exec mysql mysqldump -u smsgang -p$$(grep DB_PASSWORD .env | cut -d= -f2) $$(grep DB_DATABASE .env | cut -d= -f2) > db_backup_$$(date +%Y%m%d_%H%M%S).sql
	@echo "✅ Database backed up"

prod-ps:
	docker-compose -f docker-compose.prod.yml ps

# Development utilities
server:
	docker-compose exec app php artisan serve

queue-work:
	docker-compose exec app php artisan queue:work redis --sleep=3 --tries=1

horizon:
	docker-compose exec app php artisan horizon

pail:
	docker-compose exec app php artisan pail

ps:
	docker-compose ps

stats:
	docker stats

# Production deployment
deploy_production:
	sudo chmod -R +x scripts
	./scripts/production/index.sh
