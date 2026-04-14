<?php
// One-shot migration runner. Run from project root on the server:
//   php sql/run_migration.php
// Reads server/config.php for DB credentials. Safe to run more than once.

$configPath = __DIR__ . '/../server/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "ERROR: server/config.php not found.\n");
    exit(1);
}

$config = require $configPath;
$db     = $config['db'] ?? [];
$dsn    = $db['dsn']      ?? '';
$user   = $db['username'] ?? '';
$pass   = $db['password'] ?? '';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "ERROR: DB config is incomplete.\n");
    exit(1);
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/migrate_add_hand_tables.sql');
if ($sql === false) {
    fwrite(STDERR, "ERROR: Cannot read migration file.\n");
    exit(1);
}

// Split on semicolons to run each statement individually
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    static fn(string $s) => $s !== '' && !preg_match('/^--/', $s)
);

$ok = true;
foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        // Print table name from CREATE TABLE statement
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $stmt, $m)) {
            echo "  OK: {$m[1]}\n";
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Statement: " . substr($stmt, 0, 120) . "...\n");
        $ok = false;
    }
}

echo $ok ? "Migration complete.\n" : "Migration finished with errors.\n";
exit($ok ? 0 : 1);
