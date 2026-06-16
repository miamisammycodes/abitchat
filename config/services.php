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

    'groq' => [
        'model' => env('GROQ_MODEL', 'llama-3.1-8b-instant'),
    ],

    'ollama' => [
        'model' => env('OLLAMA_MODEL', 'gemma3:4b'),
    ],

    'embeddings' => [
        'provider' => env('EMBEDDING_PROVIDER', 'ollama'),
        'model' => env('EMBEDDING_MODEL', 'nomic-embed-text'),
    ],

    'dk_bank' => [
        'enabled' => env('DK_BANK_ENABLED', false),
        'base_url' => env('DK_BANK_BASE_URL'),
        'api_key' => env('DK_BANK_API_KEY'),
        'username' => env('DK_BANK_USERNAME'),
        'password' => env('DK_BANK_PASSWORD'),
        'client_id' => env('DK_BANK_CLIENT_ID'),
        'client_secret' => env('DK_BANK_CLIENT_SECRET'),
        'source_app' => env('DK_BANK_SOURCE_APP'),
        'beneficiary_account' => env('DK_BANK_BENEFICIARY_ACCOUNT'),
        // Credit-account match strategy: 'exact' (default — no behavior change until
        // DK confirms it returns a masked account) or 'suffix' (compare last N digits).
        'account_match' => env('DK_BANK_ACCOUNT_MATCH', 'exact'),
        'account_match_digits' => (int) env('DK_BANK_ACCOUNT_MATCH_DIGITS', 4),
        'mcc_code' => env('DK_BANK_MCC_CODE', '5817'),
        'private_key_path' => storage_path('app/dk_pg.pem'),
        'http_timeout' => env('DK_BANK_HTTP_TIMEOUT', 30),
    ],

    'crawler' => [
        'js_rendering' => env('CRAWLER_JS_RENDERING', false),
        'render_timeout' => (int) env('CRAWLER_RENDER_TIMEOUT', 45),
        'render_delay' => (int) env('CRAWLER_RENDER_DELAY', 3000),
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary' => env('BROWSERSHOT_NPM_BINARY'),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
        'egress_proxy' => env('CRAWLER_EGRESS_PROXY'),
        'render_budget' => (int) env('CRAWLER_RENDER_BUDGET', 25),
    ],

];
