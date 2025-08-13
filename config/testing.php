<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Parallel Testing Configuration
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of Laravel's parallel testing.
    |
    */

    'parallel' => [
        'enabled' => env('LARAVEL_PARALLEL_TESTING', false),
        'processes' => env('PARATEST_PROCESSES', 4),
        'database_prefix' => 'test_',
        'recreate_databases' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Test Database Configuration
    |--------------------------------------------------------------------------
    */
    
    'database' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
        'database' => env('DB_DATABASE', 'gigapdf_test'),
        'username' => env('DB_USERNAME', 'gigapdf_user'),
        'password' => env('DB_PASSWORD', 'G1g@PdF2024Secure'),
    ],
];