<?php

declare(strict_types=1);

namespace FortyFives\Infrastructure\Persistence;

use PDO;

final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromConfig(): self
    {
        $configPath = dirname(__DIR__, 3) . '/server/config.php';
        if (!file_exists($configPath)) {
            $configPath = dirname(__DIR__, 3) . '/server/config.example.php';
        }

        $config = require $configPath;
        $db = $config['db'] ?? [];

        $pdo = new PDO(
            (string) ($db['dsn'] ?? ''),
            (string) ($db['username'] ?? ''),
            (string) ($db['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
