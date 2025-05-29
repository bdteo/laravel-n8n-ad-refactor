<?php

// Create a simple script to fix the test file
$file = file_get_contents('/Users/boris/DevEnvs/laravel-n8n-ad-refactor/tests/Feature/N8n/N8nQueueIntegrationTest.php');
$file = str_replace(
    '->andReturn(new N8nWebhookPayload((string), \'Test script\', \'Test outcome\'));',
    '->andReturn(new N8nWebhookPayload((string)$task->id, \'Test script\', \'Test outcome\'));',
    $file
);
file_put_contents('/Users/boris/DevEnvs/laravel-n8n-ad-refactor/tests/Feature/N8n/N8nQueueIntegrationTest.php', $file);
echo "Test file fixed successfully\n";
