.PHONY: help build up down restart logs shell test test-setup test-coverage clean install dev-setup

# Default target
help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# Docker operations
build: ## Build Docker containers
	docker compose build --no-cache

up: ## Start all services
	docker compose up -d

down: ## Stop all services
	docker compose down

restart: ## Restart all services
	docker compose restart

logs: ## Show logs for all services
	docker compose logs -f

logs-app: ## Show logs for Laravel app
	docker compose logs -f app

logs-nginx: ## Show logs for Nginx
	docker compose logs -f nginx

logs-mysql: ## Show logs for MySQL
	docker compose logs -f mysql

logs-redis: ## Show logs for Redis
	docker compose logs -f redis

logs-n8n: ## Show logs for n8n
	docker compose logs -f n8n

logs-queue: ## Show logs for Queue worker
	docker compose logs -f queue

# Application operations
shell: ## Access Laravel app shell
	docker compose exec app bash

shell-mysql: ## Access MySQL shell
	docker compose exec mysql mysql -u laravel -p laravel_n8n_ad_refactor

shell-redis: ## Access Redis CLI
	docker compose exec redis redis-cli

# Laravel operations
install: ## Install Laravel dependencies and setup
	docker compose exec app composer install
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate
	docker compose exec app php artisan db:seed

migrate: ## Run Laravel migrations
	docker compose exec app php artisan migrate

migrate-fresh: ## Fresh migration with seeding
	docker compose exec app php artisan migrate:fresh --seed

seed: ## Run database seeders
	docker compose exec app php artisan db:seed

cache-clear: ## Clear Laravel caches
	docker compose exec app php artisan cache:clear
	docker compose exec app php artisan config:clear
	docker compose exec app php artisan route:clear
	docker compose exec app php artisan view:clear

optimize: ## Optimize Laravel for production
	docker compose exec app php artisan config:cache
	docker compose exec app php artisan route:cache
	docker compose exec app php artisan view:cache

# Testing
test-setup: ## Setup test environment
	# Clear config cache to ensure fresh configuration
	docker compose exec -T app php artisan config:clear
	# No need to run migrations for SQLite :memory: database as it will be created fresh for each test

# Standalone test execution without Docker
test-local: ## Run tests locally without Docker
	php artisan test

# Docker-based testing
test: test-setup ## Run tests in Docker
	# Run tests using SQLite for faster execution
	docker compose exec -T app bash -c "php artisan config:clear && DB_CONNECTION=sqlite DB_DATABASE=:memory: XDEBUG_MODE=off ./vendor/bin/pest"

test-coverage: test-setup ## Run tests with coverage
	docker compose exec -T app bash -c "php artisan config:clear && DB_CONNECTION=sqlite DB_DATABASE=:memory: XDEBUG_MODE=coverage ./vendor/bin/pest --coverage-html coverage"
	@echo "✅ Coverage report generated in the coverage/ directory"
	@echo "📊 Open coverage/index.html in your browser to view the report"
	
# Development
dev-setup: build up env-setup db-setup install test-setup ## Complete development setup
	@echo "\n✅ Development environment is ready!"
	@echo "Laravel: http://localhost:8000"
	@echo "n8n: http://localhost:5678 (admin/admin123)"
	@echo "MySQL: localhost:3306"
	@echo "Redis: localhost:6379"
	@echo "\n📋 Testing is configured to use MySQL for reliable results in Docker"
	@echo "💡 Run 'make test' to verify your setup (all tests should pass)"
	@echo "💡 Run 'make test-coverage' to generate a coverage report"

# Environment setup
env-setup: ## Setup .env file
	@if [ ! -f .env ]; then \
		echo "Creating .env file from .env.example"; \
		cp .env.example .env; \
	else \
		echo ".env file already exists. Updating database configuration..."; \
	fi
	sed -i '' 's/DB_HOST=127.0.0.1/DB_HOST=mysql/g' .env
	sed -i '' 's/DB_DATABASE=laravel/DB_DATABASE=laravel_n8n_ad_refactor/g' .env
	sed -i '' 's/DB_USERNAME=root/DB_USERNAME=laravel/g' .env
	sed -i '' 's/DB_PASSWORD=/DB_PASSWORD=secret/g' .env
	sed -i '' 's/REDIS_HOST=127.0.0.1/REDIS_HOST=redis/g' .env

# Database setup with wait for MySQL
db-setup: ## Wait for MySQL to be ready
	@echo "Waiting for MySQL to be ready..."
	@echo "Sleeping for 15 seconds to give MySQL time to initialize..."
	sleep 15
	@echo "Checking MySQL connection..."
	docker compose exec mysql mysqladmin -h mysql -u laravel -psecret ping --silent || (echo "MySQL is not ready yet. Check docker logs and try again."; exit 1)
	@echo "✅ MySQL is ready!"

# Cleanup
clean: ## Clean up Docker resources
	docker compose down -v
	docker system prune -f
	docker volume prune -f

# Status
status: ## Show container status
	docker compose ps

# Quick restart for development
quick-restart: ## Quick restart of app and queue
	docker compose restart app queue

# API tests
api-test: ## Run API tests with Newman (local environment)
	yarn test:api

api-test-ci: ## Run API tests with Newman (CI environment)
	yarn test:api:ci