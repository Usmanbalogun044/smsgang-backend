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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'cloudflare' => [
        'turnstile_site_key' => env('CLOUDFLARE_TURNSTILE_SITE_KEY'),
        'turnstile_secret' => env('CLOUDFLARE_TURNSTILE_SECRET'),
        'turnstile_hostname' => env('CLOUDFLARE_TURNSTILE_HOSTNAME'),
    ],

    'fivesim' => [
        'base_url' => env('FIVESIM_BASE_URL', 'https://5sim.net/v1'),
        'api_key' => env('FIVESIM_API_KEY'),
    ],

    'twilio' => [
        'base_url' => env('TWILIO_BASE_URL', 'https://api.twilio.com'),
        'api_version' => env('TWILIO_API_VERSION', '2010-04-01'),
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'default_country' => env('TWILIO_DEFAULT_COUNTRY', 'US'),
        'default_monthly_price_usd' => (float) env('TWILIO_DEFAULT_MONTHLY_PRICE_USD', 1.20),
        'inventory_cache_ttl' => (int) env('TWILIO_INVENTORY_CACHE_TTL', 90),
        'webhook_signature_validation' => filter_var(env('TWILIO_WEBHOOK_SIGNATURE_VALIDATION', true), FILTER_VALIDATE_BOOL),
        'webhook_base_url' => env('TWILIO_WEBHOOK_BASE_URL', env('APP_URL')),
    ],

    'lendoverify' => [
        'base_url' => env('LENDOVERIFY_BASE_URL', 'https://api.lendoverify.com'),
        'api_key' => env('LENDOVERIFY_API_KEY'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'enabled' => filter_var(env('TELEGRAM_NOTIFICATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    'crestpanel' => [
        'key' => env('CRESTPANEL_API_KEY'),
    ],

    'currency_api' => [
        'base_url' => env('CURRENCY_API_BASE_URL', env('RAPIDAPI_CURRENCY_BASE_URL', '')),
        'latest_path' => env('CURRENCY_API_LATEST_PATH', '/latest'),
        'convert_path' => env('CURRENCY_API_CONVERT_PATH', '/convert'),
        'api_key' => env('CURRENCY_API_KEY', env('RAPIDAPI_CURRENCY_API_KEY', env('RAPIDAPI_KEY', ''))),
        'host' => env('CURRENCY_API_HOST', env('RAPIDAPI_CURRENCY_API_HOST', 'currency-conversion-and-exchange-rates.p.rapidapi.com')),
        'from' => env('CURRENCY_API_FROM', 'USD'),
        'to' => env('CURRENCY_API_TO', 'NGN'),
        'amount' => env('CURRENCY_API_AMOUNT', 1),
        'timeout' => env('CURRENCY_API_TIMEOUT', 15),
    ],

];
