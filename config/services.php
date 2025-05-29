<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'n8n' => [
        // For Laravel to authenticate to n8n's trigger webhook
        'trigger_webhook_url' => env('N8N_TRIGGER_WEBHOOK_URL'),
        'auth_header_key' => env('N8N_AUTH_HEADER_KEY', 'X-Laravel-Trigger-Auth'),
        'auth_header_value' => env('N8N_AUTH_HEADER_VALUE'),

        // For Laravel to verify HMAC signatures from n8n callbacks
        'callback_hmac_secret' => env('N8N_CALLBACK_HMAC_SECRET'),

        // For development and testing, allows bypassing signature verification
        'disable_auth' => env('N8N_DISABLE_AUTH', false),
    ],

];
