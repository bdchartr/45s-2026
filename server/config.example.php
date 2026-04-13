<?php

return [
    'app_env' => 'development',
    'base_path' => '/45s',
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=forty_fives;charset=utf8mb4',
        'username' => 'replace_me',
        'password' => 'replace_me',
    ],
    'session' => [
        'cookie_name' => 'fortyfives_sid',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];
