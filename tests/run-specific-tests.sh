#!/bin/bash

# This script runs specific tests that we've refactored
# It focuses on the AdScript tests we've been working on

# Set environment variables to disable rate limiting
export DISABLE_RATE_LIMITING=true

# Run our refactored test files one by one
echo "Running BasicWorkflowTest..."
php artisan test --filter=Tests\\Feature\\AdScript\\BasicWorkflowTest

echo "Running SecurityValidationTest..."
php artisan test --filter=Tests\\Feature\\AdScript\\SecurityValidationTest

echo "Running AdvancedWorkflowTest..."
php artisan test --filter=Tests\\Feature\\AdScript\\AdvancedWorkflowTest

echo "Running ErrorHandlingTest..."
php artisan test --filter=Tests\\Feature\\AdScript\\ErrorHandlingTest
