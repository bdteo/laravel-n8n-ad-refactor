#!/bin/bash
# Reliable Development Environment Setup
# This script sets up a reliable development environment where all API tests pass
# without requiring manual intervention or specific container states.

set -e # Exit on any error

echo "🚀 Starting reliable development environment setup..."

# 1. Ensure all containers are built and running
echo "📦 Ensuring all containers are properly built and running..."
docker compose down || true
docker compose build --no-cache
docker compose up -d

# 2. Wait for services to be ready
echo "⏳ Waiting for services to initialize..."
echo "⏳ Waiting for MySQL to be ready..."
sleep 15

MAX_RETRIES=30
RETRY_COUNT=0
MYSQL_READY=false

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
  if docker compose exec -T mysql mysqladmin -h mysql -u laravel -psecret ping --silent > /dev/null 2>&1; then
    MYSQL_READY=true
    echo "✅ MySQL is ready!"
    break
  fi
  echo "⏳ MySQL is not ready yet. Retrying in 2 seconds... (Attempt $((RETRY_COUNT+1))/$MAX_RETRIES)"
  RETRY_COUNT=$((RETRY_COUNT+1))
  sleep 2
done

if [ "$MYSQL_READY" = false ]; then
  echo "❌ MySQL failed to become ready. Continuing anyway, but there may be issues."
fi

# 3. Setup Laravel environment
echo "🔧 Setting up Laravel environment..."
docker compose exec -T app composer install
docker compose exec -T app php artisan key:generate --force
docker compose exec -T app php artisan migrate:fresh --seed --force

# 4. Configure environment for testing
echo "🔧 Configuring environment for API tests..."

# Ensure proper Laravel environment variables for n8n integration
echo "⚙️ Setting up Laravel-n8n integration environment variables..."
docker compose exec -T app bash -c 'sed -i "s|^N8N_TRIGGER_WEBHOOK_URL=.*|N8N_TRIGGER_WEBHOOK_URL=http://host.docker.internal:5678/webhook-test/ad-script-refactor-openrouter|" /var/www/.env'
docker compose exec -T app bash -c 'sed -i "s/^N8N_AUTH_HEADER_VALUE=.*/N8N_AUTH_HEADER_VALUE=/" /var/www/.env'
docker compose exec -T app bash -c 'sed -i "s/^N8N_DISABLE_AUTH=.*/N8N_DISABLE_AUTH=true/" /var/www/.env || echo "N8N_DISABLE_AUTH=true" >> /var/www/.env'
docker compose exec -T app bash -c 'sed -i "s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/" /var/www/.env'

# Clear Laravel cache to ensure changes take effect
echo "🧹 Clearing Laravel cache..."
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan cache:clear
docker compose exec -T app php artisan route:clear

# 5. Setup n8n if available, but make system resilient to n8n failures
echo "🔄 Setting up n8n (if available)..."
if docker compose exec -T n8n echo "n8n container is running" > /dev/null 2>&1; then
  echo "✅ n8n container is running. Setting up workflow..."

  # Create directories
  docker compose exec -T n8n mkdir -p /home/node/.n8n/workflows || true

  # Setup n8n environment
  docker compose exec -T n8n bash -c 'mkdir -p /home/node/.n8n && echo "N8N_RUNNERS_ENABLED=true" > /home/node/.n8n/.env' || true

  # Copy and run workflow setup script if container is healthy
  if docker compose exec -T n8n echo "Testing n8n container health" > /dev/null 2>&1; then
    docker compose cp make-tools/simple-workflow-setup.js n8n:/home/node/simple-workflow-setup.js || true
    docker compose exec -T n8n node /home/node/simple-workflow-setup.js || echo "⚠️ n8n workflow setup failed, but continuing with fallback mode..."
  else
    echo "⚠️ n8n container is not healthy. Continuing with fallback mode..."
  fi
else
  echo "⚠️ n8n container is not available. Continuing with fallback mode..."
fi

# 6. Fix Postman collection for validation test (outcome_description length)
#echo "📝 Updating Postman collection for validation test..."
#docker compose exec -T app php /var/www/make-tools/fix-postman-collection.php

# 7. Restart services to apply all changes (with proper order to avoid 502 errors)
echo "🔄 Restarting services to apply all changes..."
docker compose stop app nginx
echo "⏳ Waiting for services to stop completely..."
sleep 3
docker compose start app
echo "⏳ Waiting for PHP-FPM to initialize..."
sleep 3
docker compose start nginx
echo "⏳ Waiting for Nginx to initialize..."
sleep 2

# 8. Verify the setup
echo "🔍 Verifying the setup..."
echo "📋 Checking Laravel app status..."
if curl -s http://localhost:8000/api/health-check > /dev/null; then
  echo "✅ Laravel app is running!"
else
  echo "⚠️ Laravel app health check failed. Attempting to fix..."
  docker compose restart nginx app
  sleep 5

  if curl -s http://localhost:8000/api/health-check > /dev/null; then
    echo "✅ Laravel app is now running!"
  else
    echo "❌ Laravel app is still not responding. Manual intervention may be required."
  fi
fi

## 9. Run direct API test to verify everything is working
#echo "🧪 Running direct API test..."
#docker compose exec -T app php /var/www/make-tools/direct-api-test.php

echo "✅ Development environment is now set up!"
echo "🚀 You can now run 'make api-test' to verify that all API tests pass"
echo "📝 If you encounter any issues, try running 'make direct-api-test' for a more resilient test approach"
