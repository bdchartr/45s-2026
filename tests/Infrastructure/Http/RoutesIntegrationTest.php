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
            'base_path' => '/45',
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

    public function testArchiveGameRouteRequiresCsrf(): void
    {
        $app = $this->buildApp();

        $response = $app->handle($this->jsonRequest('POST', '/api/lobby/archive_game', ['game_id' => 1]));

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('invalid_csrf_token', $payload['error'] ?? null);
    }

    public function testArchiveGameRouteRequiresAuthentication(): void
    {
        $app = $this->buildApp();
        $csrf = $this->seedCsrfToken();

        $response = $app->handle($this->csrfRequest('POST', '/api/lobby/archive_game', ['game_id' => 1], $csrf));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testArchiveGameRouteReturns404WhenGameNotOwnedByPlayer(): void
    {
        [$pdo] = $this->bootstrapWithGames();
        $app = $this->buildApp();

        // Player (id=2) tries to archive a game created by admin (id=1).
        $csrf = $this->seedCsrfToken(userId: 2);
        $response = $app->handle($this->csrfRequest('POST', '/api/lobby/archive_game', ['game_id' => 1], $csrf));

        $this->assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('game_not_found_or_not_yours', $payload['error'] ?? null);
    }

    public function testArchiveGameRouteSucceedsForCreator(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        $app = $this->buildApp();

        // Admin (id=1) created the game; they can archive it.
        $csrf = $this->seedCsrfToken(userId: 1);
        $response = $app->handle($this->csrfRequest('POST', '/api/lobby/archive_game', ['game_id' => $gameId], $csrf));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['ok'] ?? false);
        $this->assertSame($gameId, $payload['archived_game_id']);

        // Row must still exist in the DB (soft-archive, not hard-delete).
        $row = $pdo->query("SELECT archived_at FROM games WHERE id = $gameId")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'game row should still exist after archiving');
        $this->assertNotNull($row['archived_at'], 'archived_at should be set');
    }

    public function testArchiveGameRouteAdminCanArchiveAnyGame(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        $app = $this->buildApp();

        // Admin (id=1) archives a game even though the route checks ownership for players.
        // (admin role → archiveGame bypasses creator check)
        $csrf = $this->seedCsrfToken(userId: 1);
        $response = $app->handle($this->csrfRequest('POST', '/api/lobby/archive_game', ['game_id' => $gameId], $csrf));

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Player profile endpoints ──────────────────────────────────────────────

    public function testPlayerStatsRequiresAuthentication(): void
    {
        $app = $this->buildApp();

        $response = $app->handle($this->jsonRequest('GET', '/api/player/stats', []));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testPlayerStatsReturnsProfileForAuthenticatedUser(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $uri     = new Uri('https', 'example.test', null, '/api/player/stats');
        $headers = new Headers(['Host' => ['example.test']]);
        $request = new \Slim\Psr7\Request('GET', $uri, $headers, [], ['REMOTE_ADDR' => '127.0.0.1'], new Stream(fopen('php://temp', 'r+')));

        $response = $app->handle($request);
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok'] ?? false);
        $this->assertSame('chartb', $payload['user']['username'] ?? null);
        $this->assertArrayHasKey('gravatar_url', $payload['user']);
        $this->assertStringContainsString('gravatar.com', $payload['user']['gravatar_url']);
        $this->assertArrayHasKey('stats', $payload);
        $this->assertArrayHasKey('games_played', $payload['stats']);
    }

    public function testPlayerStatsReturns404ForUnknownUser(): void
    {
        $app  = $this->buildApp();
        $this->seedCsrfToken(userId: 1);

        $uri     = new Uri('https', 'example.test', null, '/api/player/stats');
        $headers = new Headers(['Host' => ['example.test']]);
        $stream  = fopen('php://temp', 'r+');
        $request = new \Slim\Psr7\Request(
            'GET',
            new Uri('https', 'example.test', null, '/api/player/stats', 'user_id=9999'),
            $headers,
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            new Stream($stream)
        );

        $response = $app->handle($request);
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('user_not_found', $payload['error'] ?? null);
    }

    public function testUpdateNicknameRequiresCsrf(): void
    {
        $app = $this->buildApp();

        $response = $app->handle($this->jsonRequest('POST', '/api/player/update_nickname', ['nickname' => 'Bob']));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateNicknameSucceeds(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/player/update_nickname', ['nickname' => 'Bob'], $csrf));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok'] ?? false);

        $pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $row = $pdo->query("SELECT nickname FROM users WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Bob', $row['nickname']);
    }

    public function testUpdateNicknameTooLongReturns400(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest(
            'POST',
            '/api/player/update_nickname',
            ['nickname' => str_repeat('x', 61)],
            $csrf
        ));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('nickname_too_long', $payload['error'] ?? null);
    }

    public function testUpdateAvatarRequiresCsrf(): void
    {
        $app = $this->buildApp();

        $response = $app->handle($this->jsonRequest('POST', '/api/player/update_avatar', ['avatar_code' => 's-teal']));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateAvatarSucceeds(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/player/update_avatar', ['avatar_code' => 's-teal'], $csrf));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok'] ?? false);

        $pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $row = $pdo->query("SELECT avatar_code FROM users WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('s-teal', $row['avatar_code']);
    }

    public function testUpdateAvatarEmptyCodeReturns400(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/player/update_avatar', ['avatar_code' => ''], $csrf));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_avatar_code', $payload['error'] ?? null);
    }

    public function testUpdatePasswordRequiresCsrf(): void
    {
        $app = $this->buildApp();

        $response = $app->handle($this->jsonRequest('POST', '/api/player/update_password', [
            'current_password' => '16_Bubles',
            'new_password'     => 'newpass123',
        ]));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdatePasswordTooShortReturns400(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/player/update_password', [
            'current_password' => '16_Bubles',
            'new_password'     => 'short',
        ], $csrf));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('password_too_short', $payload['error'] ?? null);
    }

    public function testUpdatePasswordWrongCurrentReturns403(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/player/update_password', [
            'current_password' => 'wrong-password',
            'new_password'     => 'validnewpass',
        ], $csrf));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('wrong_current_password', $payload['error'] ?? null);
    }

    public function testUpdatePasswordSucceeds(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/player/update_password', [
            'current_password' => '16_Bubles',
            'new_password'     => 'validnewpass',
        ], $csrf));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok'] ?? false);

        $pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $row = $pdo->query("SELECT password_hash FROM users WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify('validnewpass', (string) $row['password_hash']));
    }

    public function testSubmitBidRejectsSeatNotOwnedBySessionUser(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        $pdo->exec("INSERT INTO game_players (game_id, seat, user_id, is_ai, team, connected) VALUES ($gameId, 1, 2, 0, 1, 1)");

        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/game/submit_bid', [
            'game_id' => $gameId,
            'seat'    => 1,
            'bid'     => 'pass',
        ], $csrf));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('seat_ownership_required', $payload['error'] ?? null);
    }

    // ── Seat-ownership IDOR (remaining 3 endpoints + no-seat case) ───────────

    public function testPlayCardRejectsSeatNotOwnedBySessionUser(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        $pdo->exec("INSERT INTO game_players (game_id, seat, user_id, is_ai, team, connected) VALUES ($gameId, 1, 2, 0, 1, 1)");

        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/game/play_card', [
            'game_id' => $gameId,
            'seat'    => 1,
            'card'    => 'AC',
        ], $csrf));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('seat_ownership_required', $payload['error'] ?? null);
    }

    public function testDeclareTrumpRejectsSeatNotOwnedBySessionUser(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        $pdo->exec("INSERT INTO game_players (game_id, seat, user_id, is_ai, team, connected) VALUES ($gameId, 1, 2, 0, 1, 1)");

        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/game/declare_trump', [
            'game_id' => $gameId,
            'seat'    => 1,
            'trump'   => 'C',
        ], $csrf));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('seat_ownership_required', $payload['error'] ?? null);
    }

    public function testDiscardCardsRejectsSeatNotOwnedBySessionUser(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        $pdo->exec("INSERT INTO game_players (game_id, seat, user_id, is_ai, team, connected) VALUES ($gameId, 1, 2, 0, 1, 1)");

        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/game/discard_cards', [
            'game_id' => $gameId,
            'seat'    => 1,
            'cards'   => [],
        ], $csrf));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('seat_ownership_required', $payload['error'] ?? null);
    }

    public function testSubmitBidRejectsWhenSessionUserHasNoSeatInGame(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        // user 1 is not seated in the game at all

        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/game/submit_bid', [
            'game_id' => $gameId,
            'seat'    => 0,
            'bid'     => 'pass',
        ], $csrf));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('seat_ownership_required', $payload['error'] ?? null);
    }

    // ── Lobby user_id ↔ session binding ──────────────────────────────────────

    public function testCreateGameRejectsUnauthenticated(): void
    {
        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(); // no userId

        $response = $app->handle($this->csrfRequest('POST', '/api/lobby/create_game', [
            'target_score' => 120,
            'ruleset'      => 'chartrand',
        ], $csrf));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('authentication_required', $payload['error'] ?? null);
    }

    public function testCreateGameRejectsUserIdSessionMismatch(): void
    {
        $this->bootstrapWithGames(); // seeds users 1 and 2

        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/lobby/create_game', [
            'target_score' => 120,
            'ruleset'      => 'chartrand',
            'user_id'      => 2, // body claims a different user
        ], $csrf));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('user_id_session_mismatch', $payload['error'] ?? null);
    }

    public function testJoinGameRejectsUserIdSessionMismatch(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();

        $app  = $this->buildApp();
        $csrf = $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->csrfRequest('POST', '/api/lobby/join_game', [
            'game_id' => $gameId,
            'user_id' => 2,
        ], $csrf));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('user_id_session_mismatch', $payload['error'] ?? null);
    }

    public function testMyGamesRejectsUnauthenticated(): void
    {
        $app = $this->buildApp();
        // no seedCsrfToken — session has no user_id

        $response = $app->handle($this->getRequest('/api/lobby/my_games?user_id=1'));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('authentication_required', $payload['error'] ?? null);
    }

    public function testMyGamesRejectsUserIdMismatch(): void
    {
        $this->bootstrapWithGames();

        $app = $this->buildApp();
        $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->getRequest('/api/lobby/my_games?user_id=2'));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('user_id_session_mismatch', $payload['error'] ?? null);
    }

    public function testListJoinableRejectsUnauthenticated(): void
    {
        $app = $this->buildApp();

        $response = $app->handle($this->getRequest('/api/lobby/list_joinable?user_id=1'));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('authentication_required', $payload['error'] ?? null);
    }

    // ── poll_events authz ─────────────────────────────────────────────────────

    public function testPollEventsRejectsUnauthenticated(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        $app = $this->buildApp();

        $response = $app->handle($this->getRequest('/api/game/poll_events?game_id=' . $gameId));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('authentication_required', $payload['error'] ?? null);
    }

    public function testPollEventsRejectsNonParticipantNonAdmin(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        // user 3: non-admin, no seat in the game
        $pdo->exec('INSERT INTO users (username, email, password_hash, role, auth_provider) VALUES ("player2", NULL, NULL, "player", "local")');

        $app = $this->buildApp();
        $this->seedCsrfToken(userId: 3);

        $response = $app->handle($this->getRequest('/api/game/poll_events?game_id=' . $gameId));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('forbidden_for_viewer', $payload['error'] ?? null);
    }

    public function testPollEventsAllowsAdminEvenIfNotSeated(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        // user 1 is admin (chartb) and is not seated in the game

        $app = $this->buildApp();
        $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->getRequest('/api/game/poll_events?game_id=' . $gameId));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok'] ?? false);
    }

    public function testPollEventsAllowsSeatedParticipant(): void
    {
        [$pdo, $gameId] = $this->bootstrapWithGames();
        $pdo->exec("INSERT INTO game_players (game_id, seat, user_id, is_ai, team, connected) VALUES ($gameId, 0, 2, 0, 0, 1)");

        $app = $this->buildApp();
        $this->seedCsrfToken(userId: 2);

        $response = $app->handle($this->getRequest('/api/game/poll_events?game_id=' . $gameId));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok'] ?? false);
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    public function testLoginRateLimitedPerIpAcrossDifferentUsernames(): void
    {
        $app = $this->buildApp();
        $this->bootstrapWithGames(); // ensure user table exists

        // Exhaust the per-IP bucket (30/900s) using a username that doesn't
        // exist, so the per-username bucket never fires (no stored attempts).
        for ($i = 0; $i < 30; $i++) {
            $response = $app->handle($this->jsonRequest('POST', '/api/auth/login', [
                'username' => 'ghost_user_' . $i,
                'password' => 'wrong',
            ]));
            $this->assertNotSame(429, $response->getStatusCode(), "Hit rate limit too early on attempt $i");
        }

        $response = $app->handle($this->jsonRequest('POST', '/api/auth/login', [
            'username' => 'ghost_user_final',
            'password' => 'wrong',
        ]));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('rate_limited', $payload['error'] ?? null);
    }

    // ── Lobby users — email not exposed ──────────────────────────────────────

    public function testLobbyUsersListDoesNotIncludeEmail(): void
    {
        $this->bootstrapWithGames();

        $app = $this->buildApp();
        $this->seedCsrfToken(userId: 1);

        $response = $app->handle($this->getRequest('/api/lobby/users'));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok'] ?? false);
        $users = $payload['users'] ?? [];
        $this->assertNotEmpty($users);
        foreach ($users as $user) {
            $this->assertArrayNotHasKey('email', $user, 'email must not be exposed in lobby user list');
        }
    }

    // ── Nickname XSS data contract ────────────────────────────────────────────

    public function testNicknameStoresAndReturnsHtmlMetacharactersVerbatim(): void
    {
        $this->bootstrapWithGames();

        $hostile = '<img src=x onerror=alert(1)>';
        $app     = $this->buildApp();
        $csrf    = $this->seedCsrfToken(userId: 1);

        $updateResponse = $app->handle($this->csrfRequest('POST', '/api/player/update_nickname', [
            'nickname' => $hostile,
        ], $csrf));
        $this->assertSame(200, $updateResponse->getStatusCode());

        // Session stays; re-seed CSRF and fetch via proper GET helper
        $this->seedCsrfToken(userId: 1);
        $statsResponse = $app->handle($this->getRequest('/api/player/stats?user_id=1'));
        $statsPayload  = json_decode((string) $statsResponse->getBody(), true);

        $this->assertSame(200, $statsResponse->getStatusCode());
        $this->assertSame($hostile, $statsPayload['user']['nickname'] ?? null,
            'Server must store and return hostile strings verbatim — frontend is the XSS boundary');
    }

    public function testLobbyUsersDisplayNameReturnsHtmlVerbatim(): void
    {
        $this->bootstrapWithGames(); // user 1 = admin chartb, user 2 = player1

        $hostile = '<script>alert(1)</script>';
        $app     = $this->buildApp();

        // Set hostile nickname on user 2 (authenticate as user 2)
        $csrf2 = $this->seedCsrfToken(userId: 2);
        $app->handle($this->csrfRequest('POST', '/api/player/update_nickname', [
            'nickname' => $hostile,
        ], $csrf2));

        // Fetch the users list as user 1 (the viewer); user 2 should appear
        $this->seedCsrfToken(userId: 1);
        $response = $app->handle($this->getRequest('/api/lobby/users'));
        $payload  = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $users = $payload['users'] ?? [];
        $user2 = array_values(array_filter($users, fn($u) => (int) ($u['id'] ?? 0) === 2))[0] ?? null;
        $this->assertNotNull($user2, 'user 2 should appear in lobby users list for user 1');
        $this->assertSame($hostile, $user2['display_name'] ?? null,
            'display_name must be returned verbatim — frontend is the XSS boundary');
    }

    /** Build a GET request, correctly separating path and query string. */
    private function getRequest(string $url): \Psr\Http\Message\ServerRequestInterface
    {
        $parts = explode('?', $url, 2);
        $path  = $parts[0];
        $query = $parts[1] ?? '';

        $uri = new Uri('https', 'example.test', null, $path, $query);
        $headers = new Headers(['Host' => ['example.test']]);
        return new \Slim\Psr7\Request(
            'GET',
            $uri,
            $headers,
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            new Stream(fopen('php://temp', 'r+'))
        );
    }

    private function seedCsrfToken(int $userId = 0): string
    {
        $token = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $token;
        if ($userId > 0) {
            $_SESSION['user_id'] = $userId;
        }
        return $token;
    }

    /**
     * Bootstrap the SQLite DB with a seeded game.
     * Returns [PDO, gameId].
     *
     * @return array{0: \PDO, 1: int}
     */
    private function bootstrapWithGames(): array
    {
        $pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Add games table (users table already created in setUp via bootstrapSqlite).
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS games (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status TEXT NOT NULL DEFAULT "active",
                target_score INTEGER NOT NULL DEFAULT 120,
                ruleset TEXT NOT NULL DEFAULT "chartrand",
                dealer_seat INTEGER NOT NULL DEFAULT 0,
                current_phase TEXT NOT NULL DEFAULT "bidding",
                current_turn_seat INTEGER NOT NULL DEFAULT 0,
                hand_number INTEGER NOT NULL DEFAULT 1,
                created_by_user_id INTEGER NULL,
                archived_at TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )
        ');

        // Add a player user (id will be 2, since admin is already 1).
        $pdo->exec('INSERT INTO users (username, email, password_hash, role, auth_provider) VALUES ("player1", NULL, NULL, "player", "local")');

        $pdo->exec('INSERT INTO games (status, created_by_user_id) VALUES ("active", 1)');
        $gameId = (int) $pdo->lastInsertId();

        return [$pdo, $gameId];
    }

    private function csrfRequest(string $method, string $path, array $body, string $csrfToken): \Psr\Http\Message\ServerRequestInterface
    {
        $uri = new Uri('https', 'example.test', null, $path);
        $headers = new Headers([
            'Content-Type'  => ['application/json'],
            'Host'          => ['example.test'],
            'X-CSRF-Token'  => [$csrfToken],
        ]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, (string) json_encode($body, JSON_THROW_ON_ERROR));
        rewind($stream);

        return new \Slim\Psr7\Request(
            $method,
            $uri,
            $headers,
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            new Stream($stream)
        );
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
                nickname TEXT NULL,
                avatar_code TEXT NULL,
                role TEXT NOT NULL DEFAULT "player",
                auth_provider TEXT NOT NULL DEFAULT "local",
                external_sub TEXT NULL,
                google_sub TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE games (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                status TEXT NOT NULL DEFAULT "active",
                target_score INTEGER NOT NULL DEFAULT 120,
                ruleset TEXT NOT NULL DEFAULT "chartrand",
                dealer_seat INTEGER NOT NULL DEFAULT 0,
                current_phase TEXT NOT NULL DEFAULT "bidding",
                current_turn_seat INTEGER NOT NULL DEFAULT 0,
                hand_number INTEGER NOT NULL DEFAULT 1,
                created_by_user_id INTEGER NULL,
                archived_at TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE game_players (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER NOT NULL,
                seat INTEGER NOT NULL,
                user_id INTEGER NULL,
                is_ai INTEGER NOT NULL DEFAULT 0,
                team INTEGER NOT NULL DEFAULT 0,
                connected INTEGER NOT NULL DEFAULT 1,
                display_name TEXT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE hands (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER NOT NULL,
                hand_number INTEGER NOT NULL DEFAULT 1,
                bid_winner_seat INTEGER NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE scores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER NOT NULL,
                hand_id INTEGER NOT NULL,
                team0_total INTEGER NOT NULL DEFAULT 0,
                team1_total INTEGER NOT NULL DEFAULT 0,
                team0_delta INTEGER NOT NULL DEFAULT 0,
                team1_delta INTEGER NOT NULL DEFAULT 0
            )'
        );

        $pdo->exec(
            'CREATE TABLE game_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER NOT NULL,
                seq_no INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                actor_seat INTEGER NULL,
                payload_json TEXT NOT NULL DEFAULT "{}",
                created_at TEXT NULL,
                UNIQUE (game_id, seq_no)
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
