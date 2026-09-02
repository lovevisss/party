<?php

return [

    'cas' => [
        'base_url' => env('CAS_BASE_URL', 'https://cas.paas.zufedfc.edu.cn/cas'),
        'login_path' => env('CAS_LOGIN_PATH', '/login'),
        'validate_path' => env('CAS_VALIDATE_PATH', '/serviceValidate'),
        'logout_path' => env('CAS_LOGOUT_PATH', '/logout'),
        'service_url' => env('CAS_SERVICE_URL'),
        'account_attribute' => env('CAS_ACCOUNT_ATTRIBUTE', 'user'),
        'ca_bundle' => env('CAS_CA_BUNDLE'),
        'timeout' => (int) env('CAS_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

];
