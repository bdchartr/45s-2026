<?php

declare(strict_types=1);

namespace FortyFives\Tests\Infrastructure\Http;

use FortyFives\Infrastructure\Http\Routes;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

final class RoutesIntegrationTest extends TestCase
{
    private const RATE_LIMIT_FILE = '45s_rate_limit.json';

    private string $serverConfigPath;
    private ?string $serverConfigBackup = null;
    private string $sqlitePath;
    private string $rateLimitPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverConfigPath = dirname(__DIR__, 3) . '/server/config.php';
        $this->sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '45s_test_' . uniqid('', true) . '.sqlite';
        $this->rateLimitPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::RATE_LIMIT_FILE;

        if (file_exists($this->serverConfigPath)) {
            $this->serverConfigBackup = (string) file_get_contents($this->serverConfigPath);
        }

        $config = [
            'app_env' => 'test',
            'base_path' => '/45s',
            'db' => [
                'dsn' => 'sqlite:' . $this->sqlitePath,
                'username' => '',
                'password' => '',
            ],
            'session' => [
                'cookie_name' => 'fortyfives_sid',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];

        file_put_contents(
            $this->serverConfigPath,
            "<?php\n\nreturn " . var_export($config, true) . ";\n"
        );

        $this->bootstrapSqlite();
        $this->resetRateLimiterState();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->serverConfigBackup !== null) {
            file_put_contents($this->serverConfigPath, $this->serverConfigBackup);
        } elseif (file_exists($this->serverConfigPath)) {
            unlink($this->serverConfigPath);
        }

        if (file_exists($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }

        $this->resetRateLimiterState();
        $_SESSION = [];

        parent::tearDown();
    }

    public function testProtectedRouteReturns403WhenCsrfTokenMissing(): void
    {
        $app = $this->buildApp();

        $request = $this->jsonRequest(
            'POST',
            '/api/lobby/create_game',
            [
                'target_score' => 120,
                'ruleset' => 'chartrand',
                'user_id' => 1,
                'ai_seats' => [1, 2, 3],
            ]
        );

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertIsArray($payload);
        $this->assertSame('invalid_csrf_token', $payload['error'] ?? null);
    }

    public function testLoginRouteReturns429WhenRateLimitExceeded(): void
    {
        $app = $this->buildApp();

        $requestBody = [
            'username' => 'chartb',
            'password' => 'wrong-password',
        ];

        for ($i = 0; $i < 8; $i++) {
            $response = $app->handle($this->jsonRequest('POST', '/api/auth/login', $requestBody));
            $this->assertSame(401, $response->getStatusCode());
        }

        $limitedResponse = $app->handle($this->jsonRequest('POST', '/api/auth/login', $requestBody));
        $limitedPayload = json_decode((string) $limitedResponse->getBody(), true);

        $this->assertSame(429, $limitedResponse->getStatusCode());
        $this->assertIsArray($limitedPayload);
        $this->assertSame('rate_limited', $limitedPayload['error'] ?? null);
        $this->assertArrayHasKey('retry_after', $limitedPayload);
        $this->assertGreaterThan(0, (int) $limitedPayload['retry_after']);
    }

    private function buildApp(): \Slim\App
    {
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        Routes::register($app);

        return $app;
    }

    private function jsonRequest(string $method, string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $uri = new Uri('https', 'example.test', null, $path);
        $headers = new Headers([
            'Content-Type' => ['application/json'],
            'Host' => ['example.test'],
        ]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, (string) json_encode($body, JSON_THROW_ON_ERROR));
        rewind($stream);

        $request = new \Slim\Psr7\Request(
            $method,
            $uri,
            $headers,
            [],
            [
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            new Stream($stream)
        );

        return $request;
    }

    private function bootstrapSqlite(): void
    {
        $pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT NULL,
                password_hash TEXT NULL,
                role TEXT NOT NULL DEFAULT "player",
                auth_provider TEXT NOT NULL DEFAULT "local",
                external_sub TEXT NULL,
                google_sub TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );

        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, auth_provider) VALUES (:username, :email, :password_hash, :role, :auth_provider)');
        $stmt->execute([
            'username' => 'chartb',
            'email' => 'chartb@45s.local',
            'password_hash' => password_hash('16_Bubles', PASSWORD_DEFAULT),
            'role' => 'admin',
            'auth_provider' => 'local',
        ]);
    }

    private function resetRateLimiterState(): void
    {
        if (file_exists($this->rateLimitPath)) {
            unlink($this->rateLimitPath);
        }
    }
}
