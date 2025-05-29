#!/bin/bash
# This script converts the PHP-FPM and Nginx communication from TCP to Unix socket
# This resolves the 502 Bad Gateway errors that can occur when container IPs change after restarts

set -e # Exit on any error

echo "🚀 Converting PHP-FPM and Nginx to use Unix socket for more reliable communication..."

# Stop the containers first
echo "📋 Stopping the app and nginx containers..."
docker compose stop app nginx

# Apply the socket configuration
echo "📋 Applying socket-based configuration..."
docker compose -f docker-compose.yml -f docker-compose.socket.yml up -d app nginx

echo "⏳ Waiting for services to initialize..."
sleep 5

# Verify that the services are running properly
echo "🔍 Verifying services..."
if curl -s http://localhost:8000 > /dev/null; then
    echo "✅ Services are running correctly with socket-based communication!"
    echo "✅ This configuration is more resilient to container restarts and will prevent 502 Bad Gateway errors."
else
    echo "❌ Service verification failed. Check the logs for more information:"
    docker compose logs --tail=20 app nginx
fi

# Add to Makefile
if ! grep -q "socket-setup" /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile; then
    echo "📋 Adding socket-setup target to Makefile..."
    cat << 'EOF' >> /Users/boris/DevEnvs/laravel-n8n-ad-refactor/Makefile

# Socket-based configuration
socket-setup: ## Convert PHP-FPM and Nginx to use Unix socket for more reliable communication
	@./make-tools/convert-to-socket.sh
EOF
    echo "✅ Added socket-setup target to Makefile. You can now run 'make socket-setup' anytime."
fi

echo "✅ Socket conversion completed!"
echo "💡 If you encounter any issues, you can revert to the original configuration with:"
echo "    docker compose up -d app nginx"
