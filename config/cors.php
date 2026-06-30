<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://aseguradora-balamcore-front.vercel.app',
    ],

    'allowed_origins_patterns' => [
        '#^https://aseguradora-balamcore-front-.*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];