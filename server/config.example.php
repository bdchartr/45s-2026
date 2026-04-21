<?php

return [
    'app_env' => 'development',
    'base_path' => '/45',
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
    // Social auth providers. Leave empty string to disable a provider.
    'auth' => [
        // Google: create an OAuth 2.0 Web Client ID in Google Cloud Console.
        // Authorized JS origins: https://wkapp.com
        // Authorized redirect URIs: https://wkapp.com/45/lobby.html
        'google_client_id' => '',

        // Facebook: create a Web app in Meta for Developers.
        // Add wkapp.com as an App Domain; set Site URL to https://wkapp.com/45/
        'facebook_app_id' => '',

        // Apple: create a Services ID in Apple Developer (requires paid membership).
        // Register wkapp.com, verify domain, set Return URL to https://wkapp.com/45/lobby.html
        'apple_service_id' => '',
    ],
];
