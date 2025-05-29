<?php
/**
 * Queue Connection Updater
 * 
 * This script sets the Laravel queue connection to synchronous mode for testing.
 * It's designed to be cross-platform compatible between macOS and Linux.
 */

// Path to .env file is the first argument
$env_file = $argv[1] ?? '/var/www/.env';
echo "Setting queue connection to sync mode in $env_file\n";

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

// Update QUEUE_CONNECTION to sync
$env_content = preg_replace("/^QUEUE_CONNECTION=.*$/m", "QUEUE_CONNECTION=sync", $env_content);

// Write the changes back to the .env file
if (file_put_contents($env_file, $env_content) === false) {
    echo "Error: Could not write to .env file\n";
    exit(1);
}

echo "✅ Queue connection set to sync mode.\n";
