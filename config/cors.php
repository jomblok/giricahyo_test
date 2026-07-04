<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Ganti dengan domain frontend produksi saat deploy
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Harus false jika allowed_origins pakai wildcard '*'
    'supports_credentials' => false,
];
