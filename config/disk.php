<?php
return [
    'interbus-offloads' => [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'token' => env('AWS_SESSION_TOKEN'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('INTERBUS_OFFLOAD_BUCKET', 'heavy-payloads'),
        'root' => 'interbus/',
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'options' => [
            'ACL' => 'bucket-owner-full-control',
        ],
    ],
];