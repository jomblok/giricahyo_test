<?php

return [
    'defaults' => [
        'guard'     => 'api',
        'passwords' => 'accounts',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'accounts',
        ],
        'api' => [
            'driver'   => 'jwt',
            'provider' => 'accounts',
        ],
    ],

    'providers' => [
        'accounts' => [
            'driver' => 'eloquent',
            'model'  => App\Models\Account::class,
        ],
    ],

    'passwords' => [
        'accounts' => [
            'provider' => 'accounts',
            'table'    => 'password_reset_tokens',
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
