#!/bin/bash

# This script runs the tests with rate limiting disabled
# It sets the necessary environment variables to bypass rate limiting

# Set environment variables to disable rate limiting
export DISABLE_RATE_LIMITING=true

# Run the tests
php artisan test "$@"
