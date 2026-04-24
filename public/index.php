<?php

declare(strict_types=1);

use FortyFives\Infrastructure\Http\Routes;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/server/config.php';
if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__) . '/server/config.example.php';
}

$config = require $configPath;

$session = $config['session'] ?? [];
session_name($session['cookie_name'] ?? 'fortyfives_sid');
session_set_cookie_params([
    'secure' => (bool) ($session['secure'] ?? true),
    'httponly' => (bool) ($session['httponly'] ?? true),
    'samesite' => (string) ($session['samesite'] ?? 'Lax'),
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$app = AppFactory::create();
$app->setBasePath((string) ($config['base_path'] ?? '/45'));
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Baseline security response headers. Inline scripts/styles are allowed because
// the HTML prototypes rely on them; tighten to nonces once pages are refactored.
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    $csp = "default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' https://accounts.google.com https://appleid.cdn-apple.com https://connect.facebook.net; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "img-src 'self' data: https://www.gravatar.com; "
         . "connect-src 'self' https://accounts.google.com https://graph.facebook.com https://www.facebook.com https://staticxx.facebook.com https://appleid.apple.com; "
         . "frame-src https://accounts.google.com https://www.facebook.com https://staticxx.facebook.com https://appleid.apple.com; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "frame-ancestors 'none'";
    return $response
        ->withHeader('Content-Security-Policy', $csp)
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Referrer-Policy', 'same-origin');
});

Routes::register($app, $config);

$app->run();
