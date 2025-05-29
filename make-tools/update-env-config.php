<?php
/**
 * Environment Configuration Updater
 * 
 * This script updates environment variables in the .env file for Laravel+n8n integration.
 * It's designed to be cross-platform compatible between macOS and Linux.
 */

// Path to .env file is the first argument
$env_file = $argv[1] ?? '/var/www/.env';
echo "Updating environment configuration in $env_file\n";

// Check if file exists
if (!file_exists($env_file)) {
    echo "Error: .env file not found at $env_file\n";
    exit(1);
}

// Read the .env file
$env_content = file_get_contents($env_file);
if ($env_content === false) {
    echo "Error: Could not read .env file\n";
    exit(1);
}

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
if (file_put_contents($env_file, $env_content) === false) {
    echo "Error: Could not write to .env file\n";
    exit(1);
}

echo "✅ Environment configuration updated successfully.\n";
