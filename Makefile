.PHONY: help build up down restart logs shell test clean install

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
test: ## Run tests
	docker compose exec app ./vendor/bin/pest

test-coverage: ## Run tests with coverage
	docker compose exec app ./vendor/bin/pest --coverage

# Development
dev-setup: build up install ## Complete development setup
	@echo "Development environment is ready!"
	@echo "Laravel: http://localhost:8000"
	@echo "n8n: http://localhost:5678 (admin/admin123)"
	@echo "MySQL: localhost:3306"
	@echo "Redis: localhost:6379"

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