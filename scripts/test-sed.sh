#!/bin/bash
# Test script for sed commands

# Create a temporary .env file
cat > test.env <<EOL
DB_HOST=127.0.0.1
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=
REDIS_HOST=127.0.0.1
QUEUE_CONNECTION=database
EOL

echo "Original test.env file:"
cat test.env
echo "---------------------"

# Test the sed command
echo "Testing sed command..."
sed -i '' 's/^DB_HOST=127\.0\.0\.1$/DB_HOST=mysql/' test.env

echo "After sed command:"
cat test.env
echo "---------------------"

# Clean up
rm test.env
echo "Test complete!"
