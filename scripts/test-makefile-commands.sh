#!/bin/bash
# Test script for Makefile commands - following task-driven development workflow
#
# This script allows testing individual Makefile commands without running a full build
# Usage: ./scripts/test-makefile-commands.sh [command-name]

set -e  # Exit on error

# Define test directory
TEST_DIR="$(pwd)/test-env"
mkdir -p "$TEST_DIR"

# Function to create test environment
create_test_env() {
  echo "🔨 Creating test environment in $TEST_DIR"
  
  # Create test .env file
  cat > "$TEST_DIR/.env" <<EOL
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:abcdefghijklmnopqrstuvwxyz0123456789=
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

N8N_TRIGGER_WEBHOOK_URL=http://localhost:5678/webhook/ad-script-processing
N8N_AUTH_HEADER_KEY=X-Laravel-Trigger-Auth
N8N_AUTH_HEADER_VALUE=a-very-strong-static-secret-laravel-sends-to-n8n
N8N_CALLBACK_HMAC_SECRET=very-strong-shared-secret-for-hmac-verification
EOL

  echo "✅ Test environment created"
}

# Function to test PHP commands from env-setup target
test_env_setup() {
  echo "🧪 Testing env-setup commands..."
  
  echo "Original .env file:"
  cat "$TEST_DIR/.env" | grep -E "^(DB_HOST|DB_DATABASE|DB_USERNAME|DB_PASSWORD|REDIS_HOST)="
  echo "---------------------"

  # Create PHP script for testing
  cat > "$TEST_DIR/update_env.php" <<'EOL'
<?php
$env_file = $argv[1];
$env_content = file_get_contents($env_file);

// Update DB_HOST if it has the default value
$env_content = preg_replace("/^DB_HOST=127\.0\.0\.1$/m", "DB_HOST=mysql", $env_content);

// Handle DB_DATABASE replacements
if (preg_match("/^DB_DATABASE=laravel_n8n_ad_refactor_n8n_ad_refactor/m", $env_content)) {
  $env_content = preg_replace("/^DB_DATABASE=.*$/m", "DB_DATABASE=laravel_n8n_ad_refactor", $env_content);
}
if (preg_match("/^DB_DATABASE=laravel$/m", $env_content)) {
  $env_content = preg_replace("/^DB_DATABASE=laravel$/m", "DB_DATABASE=laravel_n8n_ad_refactor", $env_content);
}

// Update DB_USERNAME if it has the default value
$env_content = preg_replace("/^DB_USERNAME=root$/m", "DB_USERNAME=laravel", $env_content);

// Update DB_PASSWORD if it is empty
$env_content = preg_replace("/^DB_PASSWORD=$/m", "DB_PASSWORD=secret", $env_content);

// Fix duplicated DB_PASSWORD
$env_content = preg_replace("/^DB_PASSWORD=secretsecret.*$/m", "DB_PASSWORD=secret", $env_content);

// Update REDIS_HOST if it has the default value
$env_content = preg_replace("/^REDIS_HOST=127\.0\.0\.1$/m", "REDIS_HOST=redis", $env_content);

// Write the changes back to the .env file
file_put_contents($env_file, $env_content);
echo "Environment configuration updated successfully.\n";
EOL

  echo "Running PHP update commands..."
  php "$TEST_DIR/update_env.php" "$TEST_DIR/.env"

  echo "Modified .env file:"
  cat "$TEST_DIR/.env" | grep -E "^(DB_HOST|DB_DATABASE|DB_USERNAME|DB_PASSWORD|REDIS_HOST)="
  echo "---------------------"
  
  echo "✅ env-setup tests completed"
}

# Function to test verify-integration commands
test_verify_integration() {
  echo "🧪 Testing verify-integration commands..."
  
  echo "Testing QUEUE_CONNECTION PHP command..."
  echo "Original QUEUE_CONNECTION setting:"
  cat "$TEST_DIR/.env" | grep "^QUEUE_CONNECTION"
  
  # Create PHP script for testing
  cat > "$TEST_DIR/update_queue.php" <<'EOL'
<?php
$env_file = $argv[1];
$env_content = file_get_contents($env_file);
$env_content = preg_replace("/^QUEUE_CONNECTION=.*$/m", "QUEUE_CONNECTION=sync", $env_content);
file_put_contents($env_file, $env_content);
echo "Queue connection set to sync mode.\n";
EOL

  php "$TEST_DIR/update_queue.php" "$TEST_DIR/.env"
  
  echo "Modified QUEUE_CONNECTION setting:"
  cat "$TEST_DIR/.env" | grep "^QUEUE_CONNECTION"
  
  echo "✅ verify-integration tests completed"
}

# Function to clean up
cleanup() {
  echo "🧹 Cleaning up test environment..."
  rm -rf "$TEST_DIR"
  echo "✅ Cleanup completed"
}

# Main execution logic
main() {
  local command="$1"
  
  create_test_env
  
  case "$command" in
    "env-setup")
      test_env_setup
      ;;
    "verify-integration")
      test_verify_integration
      ;;
    *)
      # Run all tests by default
      test_env_setup
      test_verify_integration
      ;;
  esac
  
  cleanup
}

# Run main function with command line argument
main "$1"
