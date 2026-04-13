<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/server/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "Missing server/config.php\n");
    exit(1);
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

$username = 'chartb';
$email = 'chartb@45s.local';
$plainPassword = '16_Bubles';
$passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
$stmt->execute(['username' => $username]);
$existing = $stmt->fetch();

if ($existing) {
    $update = $pdo->prepare('UPDATE users
        SET email = :email,
            password_hash = :password_hash,
            role = :role,
            auth_provider = :auth_provider,
            external_sub = NULL,
            google_sub = NULL
        WHERE id = :id');
    $update->execute([
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => 'admin',
        'auth_provider' => 'local',
        'id' => (int) $existing['id'],
    ]);
    $userId = (int) $existing['id'];
    $mode = 'updated';
} else {
    $insert = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, auth_provider)
        VALUES (:username, :email, :password_hash, :role, :auth_provider)');
    $insert->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => 'admin',
        'auth_provider' => 'local',
    ]);
    $userId = (int) $pdo->lastInsertId();
    $mode = 'created';
}

echo json_encode([
    'ok' => true,
    'mode' => $mode,
    'user_id' => $userId,
    'username' => $username,
    'role' => 'admin',
    'password_hash' => $passwordHash,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
