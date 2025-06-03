#!/bin/bash

# Script to update n8n workflow ID in environment files
# From: ad-script-processing -> ad-script-refactor-openrouter
# And: /webhook/ -> /webhook-test/ (since test webhooks actually work)

echo "🔄 Updating n8n workflow ID and webhook paths in environment files..."

# Update .env file
if [ -f .env ]; then
    echo "📝 Updating .env file..."
    sed -i.bak 's|ad-script-processing|ad-script-refactor-openrouter|g' .env
    sed -i.bak2 's|/webhook/|/webhook-test/|g' .env
    echo "✅ Updated .env (backups created as .env.bak and .env.bak2)"
fi

# Update .env.example file
if [ -f .env.example ]; then
    echo "📝 Updating .env.example file..."
    sed -i.bak 's|ad-script-processing|ad-script-refactor-openrouter|g' .env.example
    sed -i.bak2 's|/webhook/|/webhook-test/|g' .env.example
    echo "✅ Updated .env.example (backups created as .env.example.bak and .env.example.bak2)"
fi

# Update .env.testing file
if [ -f .env.testing ]; then
    echo "📝 Updating .env.testing file..."
    sed -i.bak 's|ad-script-processing|ad-script-refactor-openrouter|g' .env.testing
    sed -i.bak2 's|/webhook/|/webhook-test/|g' .env.testing
    echo "✅ Updated .env.testing (backups created as .env.testing.bak and .env.testing.bak2)"
fi

# Update .env.testing.example file  
if [ -f .env.testing.example ]; then
    echo "📝 Updating .env.testing.example file..."
    sed -i.bak 's|ad-script-processing|ad-script-refactor-openrouter|g' .env.testing.example
    sed -i.bak2 's|/webhook/|/webhook-test/|g' .env.testing.example
    echo "✅ Updated .env.testing.example (backups created as .env.testing.example.bak and .env.testing.example.bak2)"
fi

# Update .env.testing.local file
if [ -f .env.testing.local ]; then
    echo "📝 Updating .env.testing.local file..."
    sed -i.bak 's|ad-script-processing|ad-script-refactor-openrouter|g' .env.testing.local
    sed -i.bak2 's|/webhook/|/webhook-test/|g' .env.testing.local
    echo "✅ Updated .env.testing.local (backups created as .env.testing.local.bak and .env.testing.local.bak2)"
fi

echo ""
echo "🎉 All environment files have been updated!"
echo ""
echo "📋 Summary of changes:"
echo "  • Workflow ID: ad-script-processing → ad-script-refactor-openrouter"
echo "  • Webhook path: /webhook/ → /webhook-test/"
echo ""
echo "💡 The test webhook endpoints (/webhook-test/) are what actually work in n8n."
echo "   Production webhooks (/webhook/) require additional configuration in n8n interface."
echo ""
echo "🔄 To apply the changes to your running Docker containers, run:"
echo "   docker-compose restart app" 