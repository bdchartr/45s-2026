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
$app->setBasePath((string) ($config['base_path'] ?? '/45s'));
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

Routes::register($app);

$app->run();
