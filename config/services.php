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

    /*
    |--------------------------------------------------------------------------
    | TWSE API 設定
    |--------------------------------------------------------------------------
    | 台灣證券交易所 API 設定
    | 官方網站: https://www.twse.com.tw
    */
    'twse' => [
        'base_url' => env('TWSE_BASE_URL', 'https://www.twse.com.tw'),
        'timeout' => env('TWSE_TIMEOUT', 30),
        'retries' => env('TWSE_RETRIES', 3),
        'cache_ttl' => env('TWSE_CACHE_TTL', 600), // 快取時間 (秒)
        'delay_between_requests' => env('TWSE_DELAY', 500), // 請求間隔 (毫秒)
    ],

    /*
    |--------------------------------------------------------------------------
    | 爬蟲設定
    |--------------------------------------------------------------------------
    */
    'crawler' => [
        'enabled' => env('CRAWLER_ENABLED', true),
        'schedule_time' => env('CRAWLER_SCHEDULE_TIME', '13:30'), // 每天執行時間
        'batch_size' => env('CRAWLER_BATCH_SIZE', 50), // 批次處理數量
        'max_retries' => env('CRAWLER_MAX_RETRIES', 3),
    ],

];
