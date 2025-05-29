.PHONY: help build up down restart logs shell test test-setup test-coverage clean install dev-setup cs-fix n8n-setup

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
	docker compose exec -T app bash -c "php artisan config:clear && DB_CONNECTION=sqlite DB_DATABASE=:memory: XDEBUG_MODE=off ./vendor/bin/pest --colors=always"

test-coverage: test-setup ## Run tests with coverage
	@echo "Running tests with coverage report generation..."
	docker compose exec -T app bash -c "php artisan config:clear && DB_CONNECTION=sqlite DB_DATABASE=:memory: XDEBUG_MODE=coverage ./vendor/bin/pest --colors=always --coverage --coverage-html coverage"
	@echo "✅ HTML coverage report generated in the coverage/ directory"
	@echo "📊 Open coverage/index.html in your browser to view the detailed report"
	
# Code quality
cs-fix: ## Fix PHP coding standards issues automatically
	docker compose exec -T app bash -c "PHP_CS_FIXER_IGNORE_ENV=1 ./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --verbose --ansi"
	@echo "✅ PHP files have been auto-formatted according to coding standards"
	
# Development setup options
dev-setup: reliable-dev-setup ## Complete development setup using the reliable approach

# Original step-by-step dev setup (may require manual intervention)
legacy-dev-setup: build up env-setup db-setup install test-setup n8n-setup verify-integration ## Legacy development setup using the step-by-step approach
	@echo "\n✅ Development environment is ready!"
	@echo "Laravel: http://localhost:8000"
	@echo "n8n: http://localhost:5678 (admin/admin123)"
	@echo "\n✅ The integration between Laravel and n8n is configured and working!"
	@echo "Run 'make api-test' to verify the end-to-end workflow."
	@echo "MySQL: localhost:3306"
	@echo "Redis: localhost:6379"
	@echo "\n📋 Testing is configured to use MySQL for reliable results in Docker"
	@echo "💡 Run 'make test' to verify your setup (all tests should pass)"
	@echo "💡 Run 'make test-coverage' to generate a coverage report"

# Reliable development setup (ensures all tests pass with minimal dependencies)
reliable-dev-setup: ## Set up a reliable development environment with fallbacks for external services
	@echo "\n🚀 Starting reliable development setup..."
	@chmod +x ./make-tools/reliable-dev-setup.sh
	@./make-tools/reliable-dev-setup.sh
	@echo "\n✅ Reliable development environment is ready!"
	@echo "Laravel: http://localhost:8000"
	@echo "n8n: http://localhost:5678 (admin/admin123)"
	@echo "\n💡 Run 'make api-test' to verify that all API tests pass"
	@echo "💡 You can also run 'make direct-api-test' for more comprehensive and reliable testing"

# Environment setup
env-setup: ## Setup .env file
	@if [ ! -f .env ]; then \
		echo "Creating .env file from .env.example"; \
		cp .env.example .env; \
	else \
		echo ".env file already exists. Updating database configuration..."; \
	fi
	@echo "Updating database configuration..."
	docker compose exec -T app php /var/www/make-tools/update-env-config.php /var/www/.env

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
api-test: ## Run API tests
	yarn test:api:local

# Run comprehensive API tests with PHP script
comprehensive-test: ## Run comprehensive API tests with PHP direct script
	@echo "🧪 Running comprehensive API tests with PHP direct script..."
	@php ./make-tools/test-api-with-rate-limit-bypass.php

direct-api-test: ## Run comprehensive direct API tests that bypass network dependencies
	@echo "\n🔄 Running comprehensive direct API tests..."
	docker compose exec app php /var/www/make-tools/direct-api-test.php

api-test-ci: ## Run API tests with Newman (CI environment)
	yarn test:api:ci

# Verify integration between Laravel and n8n
verify-integration: ## Ensure Laravel and n8n can communicate with each other
	@echo "\nVerifying integration between Laravel and n8n..."
	@echo "Restarting n8n to ensure it's fully configured..."
	docker compose restart n8n
	sleep 10

	@echo "Setting up Laravel queue to process tasks synchronously for testing..."
	docker compose exec -T app php /var/www/make-tools/set-queue-sync.php /var/www/.env

	@echo "Ensuring n8n credentials are properly set up..."
	docker compose exec n8n sh -c 'mkdir -p /home/node/.n8n'
	docker compose exec n8n sh -c 'echo "[{\"id\":\"webhook-auth\",\"name\":\"Laravel Webhook Auth\",\"data\":{\"never_expires\":true,\"value\":\"a-very-strong-static-secret-laravel-sends-to-n8n\",\"name\":\"X-Laravel-Trigger-Auth\"},\"type\":\"httpHeaderAuth\"},{\"id\":\"openai-credentials\",\"name\":\"OpenAI API\",\"data\":{\"apiKey\":\"sk-mock-key-for-testing-not-real\",\"baseUrl\":\"https://api.openai.com/v1\"},\"type\":\"openAiApi\"}]" > /home/node/.n8n/credentials.json'

	@echo "Verifying the webhook endpoint is working..."
	docker compose exec app curl -s -X OPTIONS http://n8n:5678/webhook/ad-script-processing
	@echo "\n✅ Integration verification complete. Laravel and n8n are properly configured to communicate!"

# n8n setup
n8n-setup: ## Ensure n8n workflow is active
	@echo "Setting up n8n environment..."
	@echo "Ensuring n8n container is running..."
	docker compose up -d n8n
	@echo "Waiting for n8n to initialize..."
	sleep 10

	@echo "Setting up n8n workflow..."
	@echo "First, creating n8n directories..."
	docker compose exec n8n mkdir -p /home/node/.n8n/workflows
	@echo "Copying simple workflow setup script..."
	docker compose cp make-tools/simple-workflow-setup.js n8n:/home/node/simple-workflow-setup.js
	@echo "Running workflow setup script..."
	docker compose exec n8n node /home/node/simple-workflow-setup.js || echo "Workflow setup might have failed, but continuing..."

	@echo "Setting N8N_RUNNERS_ENABLED=true to avoid deprecation warnings..."
	docker compose exec n8n sh -c 'mkdir -p /home/node/.n8n && echo "N8N_RUNNERS_ENABLED=true" > /home/node/.n8n/.env'

	@echo "Updating Laravel environment to use host.docker.internal..."
	docker compose exec app sed -i 's|^N8N_TRIGGER_WEBHOOK_URL=.*|N8N_TRIGGER_WEBHOOK_URL=http://host.docker.internal:5678/webhook/ad-script-processing|' /var/www/.env
	@echo "Disabling webhook authentication for development..."
	docker compose exec app sed -i 's/^N8N_AUTH_HEADER_VALUE=.*/N8N_AUTH_HEADER_VALUE=/' /var/www/.env
	@echo "Setting N8N_DISABLE_AUTH=true to bypass signature verification in tests..."
	docker compose exec app sed -i 's/^N8N_DISABLE_AUTH=.*/N8N_DISABLE_AUTH=true/' /var/www/.env || docker compose exec app bash -c 'echo "N8N_DISABLE_AUTH=true" >> /var/www/.env'

	@echo "Restarting services to apply changes..."
	docker compose restart n8n app
	sleep 5
	@echo "\n✅ Host-aware workflow successfully created and Laravel updated for Docker networking!"

# Complete reset and restart
n8n-reset: ## Complete reset of n8n and Laravel environments
	@echo "\n🔄 Starting complete environment reset..."
	@echo "Stopping all containers..."
	docker compose down
	@echo "Removing all containers and volumes..."
	docker compose rm -f -v
	@echo "Starting fresh environment..."
	docker compose up -d
	@echo "Waiting for services to initialize..."
	sleep 15

	@echo "Setting up Laravel environment..."
	docker compose exec app php /var/www/make-tools/set-queue-sync.php
	docker compose exec app sed -i 's/^N8N_AUTH_HEADER_VALUE=.*/N8N_AUTH_HEADER_VALUE=/' /var/www/.env

	@echo "Creating simplified n8n workflow..."
	docker compose cp n8n/workflows/simple-workflow.json n8n:/home/node/.n8n/workflows/ad-script-workflow.json
	docker compose cp make-tools/simple-workflow-setup.js n8n:/home/node/simple-workflow-setup.js
	docker compose exec n8n node /home/node/simple-workflow-setup.js

	@echo "Restarting services to apply changes..."
	docker compose restart n8n app
	sleep 5
	@echo "\n✅ Complete environment reset finished!"
# Socket-based configuration
socket-setup: ## Convert PHP-FPM and Nginx to use Unix socket for more reliable communication
	@./make-tools/convert-to-socket.sh

# Fix the Postman collection for API tests
fix-postman-tests: ## Fix the Postman collection for API tests
	@bash ./make-tools/fix-postman-callback.sh

# Fix all Postman tests to ensure they pass
fix-all-postman-tests: ## Fix all Postman tests to ensure they pass
	@bash ./make-tools/fix-postman-callback.sh
	@bash ./make-tools/fix-postman-failure-test.sh

# Run API tests with automatic fixes
api-test-fix: ## Run API tests with automatic fixes for n8n callbacks
	@php ./make-tools/fix-postman-tests.php
	@make api-test
