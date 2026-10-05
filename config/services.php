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

    'fflogs' => [
        // 公開版では利用者が自分のキーを設定する（App\Support\FFLogsCredentialStore）。
        // 下の .env のキーは allow_server_credentials が true のとき（開発用）だけ使う。本番では false にし、キーも置かない。
        'allow_server_credentials' => (bool) env('FFLOGS_ALLOW_SERVER_CREDENTIALS', false),
        'client_id' => env('FFLOGS_CLIENT_ID'),
        'client_secret' => env('FFLOGS_CLIENT_SECRET'),
        'token_url' => env('FFLOGS_TOKEN_URL', 'https://ja.fflogs.com/oauth/token'),
        'api_url' => env('FFLOGS_API_URL', 'https://ja.fflogs.com/api/v2/client'),
    ],

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
