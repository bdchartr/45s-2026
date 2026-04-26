<?php

declare(strict_types=1);

namespace FortyFives\Infrastructure\Http;

use FortyFives\Application\Contracts\ActionCommand;
use FortyFives\Application\Services\GameRuntimeService;
use FortyFives\Infrastructure\AI\AlgorithmicMoveProvider;
use FortyFives\Infrastructure\Auth\SessionAuth;
use FortyFives\Infrastructure\Persistence\Database;
use FortyFives\Infrastructure\Persistence\GameRepository;
use FortyFives\Infrastructure\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

final class Routes
{
    public static function register(App $app, array $config = []): void
    {
        $authConfig = $config['auth'] ?? [];
        $json = static function (ResponseInterface $response, array $payload, int $status = 200): ResponseInterface {
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        };

        $requireAuthenticated = static function (ResponseInterface $response) use ($json): array {
            $auth = new SessionAuth();
            $userId = $auth->userId();
            if ($userId === null) {
                return ['error_response' => $json($response, ['ok' => false, 'error' => 'authentication_required'], 401)];
            }

            $repo = new GameRepository(Database::fromConfig());
            if (!$repo->userExists($userId)) {
                return ['error_response' => $json($response, ['ok' => false, 'error' => 'session_user_not_found'], 401)];
            }

            $role = $repo->userRole($userId) ?? 'player';
            return [
                'repo' => $repo,
                'user_id' => $userId,
                'role' => $role,
            ];
        };

        $requireOwnerOrAdmin = static function (ResponseInterface $response) use ($requireAuthenticated, $json): array {
            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn;
            }

            if (!in_array($authn['role'], ['owner', 'admin'], true)) {
                return ['error_response' => $json($response, ['ok' => false, 'error' => 'owner_or_admin_required'], 403)];
            }

            return $authn;
        };

        $requireCsrf = static function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ?ResponseInterface {
            $auth = new SessionAuth();
            $header = $request->getHeaderLine('X-CSRF-Token');
            if (!$auth->isValidCsrfToken($header)) {
                return $json($response, ['ok' => false, 'error' => 'invalid_csrf_token'], 403);
            }

            return null;
        };

        // Verify the authenticated session user actually occupies $seat in $gameId.
        // Reject on mismatch so clients with stale state see an error instead of
        // silently acting as the wrong player.
        $requireSeatOwnership = static function (
            ResponseInterface $response,
            int $gameId,
            int $seat
        ) use ($requireAuthenticated, $json): array {
            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn;
            }

            /** @var GameRepository $repo */
            $repo = $authn['repo'];
            $sessionSeat = $repo->findSeatForUser($gameId, (int) $authn['user_id']);
            if ($sessionSeat === null || $sessionSeat !== $seat) {
                return ['error_response' => $json($response, ['ok' => false, 'error' => 'seat_ownership_required'], 403)];
            }

            return $authn;
        };

        $buildShuffledDeck = static function (int $seed): array {
            $ranks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
            $suits = ['C', 'D', 'H', 'S'];
            $deck = [];
            foreach ($suits as $suit) {
                foreach ($ranks as $rank) {
                    $deck[] = $rank . $suit;
                }
            }

            mt_srand($seed);
            shuffle($deck);

            return $deck;
        };

        $app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $payload = [
                'ok' => true,
                'service' => '45s-backend',
                'time' => gmdate('c'),
            ];
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $accept = strtolower($request->getHeaderLine('Accept'));
            if ($accept === '' || strpos($accept, 'text/html') !== false) {
                return $response
                    ->withHeader('Location', '/45/lobby.html')
                    ->withStatus(302);
            }

            $payload = [
                'ok' => true,
                'service' => '45s-backend',
                'message' => 'Service is running',
                'health' => '/45/health',
            ];
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->get('/api/auth/me', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $auth = new SessionAuth();
            $userId = $auth->userId();
            $role = null;
            if ($userId !== null) {
                $repo = new GameRepository(Database::fromConfig());
                $role = $repo->userRole($userId);
            }

            $payload = [
                'authenticated' => $auth->isAuthenticated(),
                'user_id' => $userId,
                'role' => $role,
                'csrf_token' => $auth->csrfToken(),
            ];
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->get('/api/auth/csrf', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            $auth = new SessionAuth();
            return $json($response, [
                'ok' => true,
                'csrf_token' => $auth->csrfToken(),
            ]);
        });

        $app->get('/api/auth/providers', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $authConfig): ResponseInterface {
            $googleClientId  = trim((string) ($authConfig['google_client_id']  ?? ''));
            $facebookAppId   = trim((string) ($authConfig['facebook_app_id']   ?? ''));
            $appleServiceId  = trim((string) ($authConfig['apple_service_id']  ?? ''));

            return $json($response, [
                'google'   => $googleClientId !== ''
                    ? ['enabled' => true,  'client_id'  => $googleClientId]
                    : ['enabled' => false],
                'facebook' => $facebookAppId !== ''
                    ? ['enabled' => true,  'app_id'     => $facebookAppId]
                    : ['enabled' => false],
                'apple'    => $appleServiceId !== ''
                    ? ['enabled' => true,  'service_id' => $appleServiceId]
                    : ['enabled' => false],
            ]);
        });

        $app->post('/api/auth/register', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $limiter = RateLimiter::default();
            if (!$limiter->allow('auth_register:' . $ip, 10, 900)) {
                $retry = $limiter->retryAfterSeconds('auth_register:' . $ip, 900);
                return $json($response, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
            }

            $body = (array) ($request->getParsedBody() ?? []);
            $username = trim((string) ($body['username'] ?? ''));
            $email = isset($body['email']) ? trim((string) $body['email']) : null;
            $password = (string) ($body['password'] ?? '');

            if ($username === '' || $password === '') {
                return $json($response, ['ok' => false, 'error' => 'username_and_password_required'], 400);
            }
            if (strlen($password) < 8) {
                return $json($response, ['ok' => false, 'error' => 'password_min_8_chars'], 400);
            }

            $repo = new GameRepository(Database::fromConfig());
            if ($repo->findUserByUsername($username) !== null) {
                return $json($response, ['ok' => false, 'error' => 'username_already_exists'], 409);
            }

            try {
                $userId = $repo->createUser(
                    $username,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    'player',
                    'local',
                    null
                );
            } catch (\Throwable $ex) {
                error_log('[/api/auth/register] ' . $ex);
                return $json($response, ['ok' => false, 'error' => 'register_failed'], 409);
            }

            $auth = new SessionAuth();
            $auth->signIn($userId);

            return $json($response, [
                'ok' => true,
                'user_id' => $userId,
                'role' => 'player',
                'csrf_token' => $auth->csrfToken(),
            ], 201);
        });

        $app->post('/api/auth/login', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            $body = (array) ($request->getParsedBody() ?? []);
            $username = trim((string) ($body['username'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            if ($username === '' || $password === '') {
                return $json($response, ['ok' => false, 'error' => 'username_and_password_required'], 400);
            }

            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $limiter = RateLimiter::default();
            // Global per-IP cap — blunts credential stuffing that rotates usernames
            // to stay under the per-username bucket.
            $ipKey = 'auth_login_ip:' . $ip;
            if (!$limiter->allow($ipKey, 30, 900)) {
                $retry = $limiter->retryAfterSeconds($ipKey, 900);
                return $json($response, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
            }
            $key = 'auth_login:' . strtolower($username) . ':' . $ip;
            if (!$limiter->allow($key, 8, 900)) {
                $retry = $limiter->retryAfterSeconds($key, 900);
                return $json($response, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
            }

            $repo = new GameRepository(Database::fromConfig());
            $user = $repo->findUserByUsername($username);
            if ($user === null || !is_string($user['password_hash'] ?? null) || !password_verify($password, (string) $user['password_hash'])) {
                return $json($response, ['ok' => false, 'error' => 'invalid_credentials'], 401);
            }

            $auth = new SessionAuth();
            $auth->signIn((int) $user['id']);

            return $json($response, [
                'ok' => true,
                'user_id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'role' => (string) ($user['role'] ?? 'player'),
                'csrf_token' => $auth->csrfToken(),
            ]);
        });

        $app->post('/api/auth/google/login', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $authConfig): ResponseInterface {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $limiter = RateLimiter::default();
            if (!$limiter->allow('auth_google:' . $ip, 15, 900)) {
                $retry = $limiter->retryAfterSeconds('auth_google:' . $ip, 900);
                return $json($response, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
            }

            $body = (array) ($request->getParsedBody() ?? []);
            $idToken = trim((string) ($body['id_token'] ?? ''));
            if ($idToken === '') {
                return $json($response, ['ok' => false, 'error' => 'id_token_required'], 400);
            }

            $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
            $verifyJson = @file_get_contents($verifyUrl);
            if ($verifyJson === false) {
                return $json($response, ['ok' => false, 'error' => 'google_token_verification_failed'], 401);
            }

            $claims = json_decode($verifyJson, true);
            if (!is_array($claims) || !isset($claims['sub'])) {
                return $json($response, ['ok' => false, 'error' => 'invalid_google_token'], 401);
            }

            // Verify audience matches our client ID (prevents tokens issued for other apps)
            $expectedClientId = trim((string) ($authConfig['google_client_id'] ?? ''));
            if ($expectedClientId !== '' && ($claims['aud'] ?? '') !== $expectedClientId) {
                return $json($response, ['ok' => false, 'error' => 'invalid_google_token_audience'], 401);
            }

            $googleSub = (string) $claims['sub'];
            $email     = isset($claims['email']) ? (string) $claims['email'] : null;
            $nickname  = isset($claims['name'])  ? (string) $claims['name']  : null;

            $usernameFallback = $email !== null ? explode('@', $email)[0] : ('google_' . substr($googleSub, 0, 12));
            $usernameBase = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $usernameFallback) ?: 'google_user';

            $repo = new GameRepository(Database::fromConfig());
            $user = $repo->findUserByProviderSub('google', $googleSub);
            if ($user === null) {
                $candidate = $usernameBase;
                while ($repo->findUserByUsername($candidate) !== null) {
                    $candidate = $usernameBase . '_' . bin2hex(random_bytes(3));
                }

                $userId = $repo->createSocialUser($candidate, $email, 'google', $googleSub, $nickname, 'player');
                $user   = $repo->findUserById($userId);
            }

            if ($user === null) {
                return $json($response, ['ok' => false, 'error' => 'google_login_failed'], 500);
            }

            $auth = new SessionAuth();
            $auth->signIn((int) $user['id']);

            return $json($response, [
                'ok'           => true,
                'user_id'      => (int) $user['id'],
                'username'     => (string) $user['username'],
                'display_name' => (string) ($user['nickname'] ?? $user['username']),
                'role'         => (string) ($user['role'] ?? 'player'),
                'auth_provider' => 'google',
                'csrf_token'   => $auth->csrfToken(),
            ]);
        });

        $app->post('/api/auth/facebook/login', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $limiter = RateLimiter::default();
            if (!$limiter->allow('auth_facebook:' . $ip, 15, 900)) {
                $retry = $limiter->retryAfterSeconds('auth_facebook:' . $ip, 900);
                return $json($response, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
            }

            $body        = (array) ($request->getParsedBody() ?? []);
            $accessToken = trim((string) ($body['access_token'] ?? ''));
            $claimedId   = trim((string) ($body['user_id']     ?? ''));

            if ($accessToken === '' || $claimedId === '') {
                return $json($response, ['ok' => false, 'error' => 'access_token_and_user_id_required'], 400);
            }

            // Verify token by calling Graph API and confirming the user ID matches
            $meUrl    = 'https://graph.facebook.com/me?fields=id,name,first_name,last_name,email&access_token=' . rawurlencode($accessToken);
            $meJson   = @file_get_contents($meUrl);
            if ($meJson === false) {
                return $json($response, ['ok' => false, 'error' => 'facebook_token_verification_failed'], 401);
            }

            $me = json_decode($meJson, true);
            if (!is_array($me) || !isset($me['id'])) {
                return $json($response, ['ok' => false, 'error' => 'invalid_facebook_token'], 401);
            }
            if ((string) $me['id'] !== $claimedId) {
                return $json($response, ['ok' => false, 'error' => 'facebook_user_id_mismatch'], 401);
            }

            $fbSub    = (string) $me['id'];
            $email    = isset($me['email'])      ? (string) $me['email'] : null;
            $fullName = isset($me['name'])        ? (string) $me['name'] : null;

            $usernameFallback = $email !== null
                ? explode('@', $email)[0]
                : ('fb_' . substr($fbSub, 0, 10));
            $usernameBase = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $usernameFallback) ?: 'fb_user';

            $repo = new GameRepository(Database::fromConfig());
            $user = $repo->findUserByProviderSub('facebook', $fbSub);
            if ($user === null) {
                $candidate = $usernameBase;
                while ($repo->findUserByUsername($candidate) !== null) {
                    $candidate = $usernameBase . '_' . bin2hex(random_bytes(3));
                }

                $userId = $repo->createSocialUser($candidate, $email, 'facebook', $fbSub, $fullName, 'player');
                $user   = $repo->findUserById($userId);
            }

            if ($user === null) {
                return $json($response, ['ok' => false, 'error' => 'facebook_login_failed'], 500);
            }

            $auth = new SessionAuth();
            $auth->signIn((int) $user['id']);

            return $json($response, [
                'ok'           => true,
                'user_id'      => (int) $user['id'],
                'username'     => (string) $user['username'],
                'display_name' => (string) ($user['nickname'] ?? $user['username']),
                'role'         => (string) ($user['role'] ?? 'player'),
                'auth_provider' => 'facebook',
                'csrf_token'   => $auth->csrfToken(),
            ]);
        });

        $app->post('/api/auth/apple/login', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $authConfig): ResponseInterface {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $limiter = RateLimiter::default();
            if (!$limiter->allow('auth_apple:' . $ip, 15, 900)) {
                $retry = $limiter->retryAfterSeconds('auth_apple:' . $ip, 900);
                return $json($response, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
            }

            $body       = (array) ($request->getParsedBody() ?? []);
            $idToken    = trim((string) ($body['id_token']   ?? ''));
            $firstName  = isset($body['first_name']) ? trim((string) $body['first_name']) : null;
            $lastName   = isset($body['last_name'])  ? trim((string) $body['last_name'])  : null;

            if ($idToken === '') {
                return $json($response, ['ok' => false, 'error' => 'id_token_required'], 400);
            }

            $serviceId = trim((string) ($authConfig['apple_service_id'] ?? ''));
            $claims = self::verifyAppleIdToken($idToken, $serviceId);
            if ($claims === false) {
                return $json($response, ['ok' => false, 'error' => 'invalid_apple_token'], 401);
            }

            $appleSub = (string) $claims['sub'];
            $email    = isset($claims['email']) ? (string) $claims['email'] : null;

            $nickname = null;
            if ($firstName !== null || $lastName !== null) {
                $nickname = trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: null;
            }

            $usernameFallback = $email !== null
                ? explode('@', $email)[0]
                : ('apple_' . substr($appleSub, 0, 10));
            $usernameBase = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $usernameFallback) ?: 'apple_user';

            $repo = new GameRepository(Database::fromConfig());
            $user = $repo->findUserByProviderSub('apple', $appleSub);
            if ($user === null) {
                $candidate = $usernameBase;
                while ($repo->findUserByUsername($candidate) !== null) {
                    $candidate = $usernameBase . '_' . bin2hex(random_bytes(3));
                }

                $userId = $repo->createSocialUser($candidate, $email, 'apple', $appleSub, $nickname, 'player');
                $user   = $repo->findUserById($userId);
            }

            if ($user === null) {
                return $json($response, ['ok' => false, 'error' => 'apple_login_failed'], 500);
            }

            $auth = new SessionAuth();
            $auth->signIn((int) $user['id']);

            return $json($response, [
                'ok'           => true,
                'user_id'      => (int) $user['id'],
                'username'     => (string) $user['username'],
                'display_name' => (string) ($user['nickname'] ?? $user['username']),
                'role'         => (string) ($user['role'] ?? 'player'),
                'auth_provider' => 'apple',
                'csrf_token'   => $auth->csrfToken(),
            ]);
        });

        $app->post('/api/auth/logout', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $auth = new SessionAuth();
            $auth->signOut();
            return $json($response, ['ok' => true]);
        });

        $app->get('/api/system/db-health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $db = Database::fromConfig();
            $pdo = $db->pdo();
            $stmt = $pdo->query('SELECT 1 AS ok');
            $row = $stmt ? $stmt->fetch() : false;
            $payload = [
                'ok' => (bool) ($row['ok'] ?? false),
            ];
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->get('/api/stats/summary', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireOwnerOrAdmin): ResponseInterface {
            $authz = $requireOwnerOrAdmin($response);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authz['repo'];
            $summary = $repo->statsSummary();
            $totalGames = (int) ($summary['total_games'] ?? 0);
            $totalEvents = (int) ($summary['total_events'] ?? 0);

            $leaderboard = array_map(
                static fn (array $row): array => [
                    'user_id' => (int) ($row['id'] ?? 0),
                    'username' => (string) ($row['username'] ?? ''),
                    'games_played' => (int) ($row['games_played'] ?? 0),
                    'finished_games' => (int) ($row['finished_games'] ?? 0),
                ],
                $repo->statsUserLeaderboard(10)
            );

            return $json($response, [
                'ok' => true,
                'summary' => [
                    'total_users' => (int) ($summary['total_users'] ?? 0),
                    'total_games' => $totalGames,
                    'active_games' => (int) ($summary['active_games'] ?? 0),
                    'finished_games' => (int) ($summary['finished_games'] ?? 0),
                    'lobby_games' => (int) ($summary['lobby_games'] ?? 0),
                    'total_events' => $totalEvents,
                    'avg_events_per_game' => $totalGames > 0 ? round($totalEvents / $totalGames, 2) : 0.0,
                ],
                'leaderboard' => $leaderboard,
            ]);
        });

        $app->get('/api/admin/users', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireOwnerOrAdmin): ResponseInterface {
            $authz = $requireOwnerOrAdmin($response);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authz['repo'];
            return $json($response, [
                'ok' => true,
                'users' => $repo->listUsers(500),
            ]);
        });

        $app->get('/api/admin/games', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireOwnerOrAdmin): ResponseInterface {
            $authz = $requireOwnerOrAdmin($response);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authz['repo'];
            return $json($response, [
                'ok' => true,
                'games' => $repo->listGames(500),
            ]);
        });

        $app->post('/api/admin/users/create', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireOwnerOrAdmin, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authz = $requireOwnerOrAdmin($response);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authz['repo'];
            $body = (array) ($request->getParsedBody() ?? []);
            $username = trim((string) ($body['username'] ?? ''));
            $email = isset($body['email']) ? trim((string) $body['email']) : null;
            $role = strtolower(trim((string) ($body['role'] ?? 'player')));
            $authProvider = strtolower(trim((string) ($body['auth_provider'] ?? 'local')));
            $password = (string) ($body['password'] ?? '');
            $externalSub = isset($body['external_sub']) ? trim((string) $body['external_sub']) : null;

            if ($username === '') {
                return $json($response, ['ok' => false, 'error' => 'username is required'], 400);
            }
            if (!in_array($role, ['owner', 'admin', 'player'], true)) {
                return $json($response, ['ok' => false, 'error' => 'role must be owner, admin, or player'], 400);
            }
            if (!in_array($authProvider, ['local', 'google', 'sso'], true)) {
                return $json($response, ['ok' => false, 'error' => 'auth_provider must be local, google, or sso'], 400);
            }

            $passwordHash = null;
            if ($authProvider === 'local') {
                if ($password === '') {
                    return $json($response, ['ok' => false, 'error' => 'password is required for local auth'], 400);
                }
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            } elseif ($externalSub === null || $externalSub === '') {
                return $json($response, ['ok' => false, 'error' => 'external_sub is required for non-local auth'], 400);
            }

            try {
                $newUserId = $repo->createUser($username, $email, $passwordHash, $role, $authProvider, $externalSub);
            } catch (\Throwable $ex) {
                error_log('[/api/admin/users/create] ' . $ex);
                return $json($response, ['ok' => false, 'error' => 'failed_to_create_user'], 409);
            }

            return $json($response, [
                'ok' => true,
                'user_id' => $newUserId,
            ], 201);
        });

        $app->post('/api/admin/users/role', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireOwnerOrAdmin, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authz = $requireOwnerOrAdmin($response);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authz['repo'];
            $body = (array) ($request->getParsedBody() ?? []);
            $userId = (int) ($body['user_id'] ?? 0);
            $role = strtolower(trim((string) ($body['role'] ?? '')));

            if ($userId <= 0 || !in_array($role, ['owner', 'admin', 'player'], true)) {
                return $json($response, ['ok' => false, 'error' => 'user_id and valid role are required'], 400);
            }
            if (!$repo->userExists($userId)) {
                return $json($response, ['ok' => false, 'error' => 'user_id does not exist'], 404);
            }

            $repo->updateUserRole($userId, $role);
            return $json($response, ['ok' => true, 'user_id' => $userId, 'role' => $role]);
        });

        $app->post('/api/admin/delete_game', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireOwnerOrAdmin, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authz = $requireOwnerOrAdmin($response);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authz['repo'];
            $body = (array) ($request->getParsedBody() ?? []);
            $gameId = (int) ($body['game_id'] ?? 0);

            if ($gameId <= 0) {
                return $json($response, ['ok' => false, 'error' => 'game_id is required'], 400);
            }

            $archived = $repo->archiveGame($gameId);
            if (!$archived) {
                return $json($response, ['ok' => false, 'error' => 'game_not_found'], 404);
            }

            return $json($response, ['ok' => true, 'deleted_game_id' => $gameId]);
        });

        // Any authenticated user can archive a game they created.
        $app->post('/api/lobby/archive_game', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authn['repo'];
            $body = (array) ($request->getParsedBody() ?? []);
            $gameId = (int) ($body['game_id'] ?? 0);

            if ($gameId <= 0) {
                return $json($response, ['ok' => false, 'error' => 'game_id is required'], 400);
            }

            $userId = (int) $authn['user_id'];
            $role   = $authn['role'];

            // Admins and owners can archive any game; players only their own.
            if (in_array($role, ['owner', 'admin'], true)) {
                $archived = $repo->archiveGame($gameId);
            } else {
                $archived = $repo->archiveGameOwnedBy($gameId, $userId);
            }

            if (!$archived) {
                return $json($response, ['ok' => false, 'error' => 'game_not_found_or_not_yours'], 404);
            }

            return $json($response, ['ok' => true, 'archived_game_id' => $gameId]);
        });

        $app->post('/api/lobby/create_game', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf, $requireAuthenticated): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            $body = (array) ($request->getParsedBody() ?? []);
            $targetScore = (int) ($body['target_score'] ?? 120);
            if (!in_array($targetScore, [45, 120], true)) {
                return $json($response, ['ok' => false, 'error' => 'target_score must be 45 or 120'], 400);
            }

            $playerCount = (int) ($body['player_count'] ?? 4);
            if (!in_array($playerCount, [4, 6], true)) {
                return $json($response, ['ok' => false, 'error' => 'player_count must be 4 or 6'], 400);
            }

            $ruleset = (string) ($body['ruleset'] ?? 'chartrand');
            // Always use the authenticated session user as the creator. If the client
            // sent user_id, require it to match (reject rather than silently substitute).
            $sessionUserId = (int) $authn['user_id'];
            if (isset($body['user_id']) && (int) $body['user_id'] !== $sessionUserId) {
                return $json($response, ['ok' => false, 'error' => 'user_id_session_mismatch'], 403);
            }
            $userId = $sessionUserId;
            $aiSeats = (array) ($body['ai_seats'] ?? []);
            $inviteMode = strtolower((string) ($body['invite_mode'] ?? 'open'));
            $invites = (array) ($body['invites'] ?? []);
            $aiMap = [];
            for ($s = 0; $s < $playerCount; $s++) {
                $aiMap[$s] = false;
            }
            foreach ($aiSeats as $seat) {
                $seatInt = (int) $seat;
                if (array_key_exists($seatInt, $aiMap)) {
                    $aiMap[$seatInt] = true;
                }
            }

            if (!in_array($inviteMode, ['open', 'specific'], true)) {
                return $json($response, ['ok' => false, 'error' => 'invite_mode must be open or specific'], 400);
            }

            $inviteBySeat = [];
            $inviteUserIds = [];
            foreach ($invites as $invite) {
                if (!is_array($invite)) {
                    return $json($response, ['ok' => false, 'error' => 'each invite must be an object'], 400);
                }
                $seat = (int) ($invite['seat'] ?? -1);
                $inviteUserId = (int) ($invite['user_id'] ?? 0);
                if ($seat < 1 || $seat >= $playerCount) {
                    return $json($response, ['ok' => false, 'error' => 'invite seat out of range'], 400);
                }
                if ($inviteUserId <= 0) {
                    return $json($response, ['ok' => false, 'error' => 'invite user_id must be positive'], 400);
                }
                if (isset($inviteBySeat[$seat])) {
                    return $json($response, ['ok' => false, 'error' => 'duplicate invite seat'], 400);
                }
                if (isset($inviteUserIds[$inviteUserId])) {
                    return $json($response, ['ok' => false, 'error' => 'duplicate invite user_id'], 400);
                }

                $inviteBySeat[$seat] = $inviteUserId;
                $inviteUserIds[$inviteUserId] = true;
                $aiMap[$seat] = false;
            }

            if ($inviteMode === 'specific' && count($inviteBySeat) === 0) {
                return $json($response, ['ok' => false, 'error' => 'specific invite_mode requires invites'], 400);
            }

            // Seat 0 is always the human creator — never AI.
            $aiMap[0] = false;
            // In non-open modes, any seat without an invite and not already marked AI
            // becomes AI — prevents ghost slots that would stall the game forever.
            if ($inviteMode !== 'open') {
                for ($s = 1; $s < $playerCount; $s++) {
                    if (!$aiMap[$s] && !isset($inviteBySeat[$s])) {
                        $aiMap[$s] = true;
                    }
                }
            }

            /** @var GameRepository $repo */
            $repo = $authn['repo'];

            foreach (array_keys($inviteUserIds) as $inviteUserId) {
                if (!$repo->userExists((int) $inviteUserId)) {
                    return $json($response, ['ok' => false, 'error' => 'invited user_id does not exist'], 400);
                }
            }

            if (isset($inviteUserIds[$userId])) {
                return $json($response, ['ok' => false, 'error' => 'creator cannot also be invited'], 400);
            }

            // Pool of family names used to identify AI players at the table.
            // Shuffled once per game so each bot has a distinct, recognizable name.
            $aiNamePool = ['George', 'Herve', 'Cora', 'Lucienne', 'Alice', 'Gene', 'Jules', 'Roland'];
            shuffle($aiNamePool);
            $aiNameCursor = 0;

            try {
                $gameId = $repo->withTransaction(function () use ($repo, $targetScore, $ruleset, $userId, $aiMap, $inviteBySeat, $inviteMode, $playerCount, $aiNamePool, &$aiNameCursor): int {
                    $gameId = $repo->createGame($targetScore, $ruleset, $userId, $playerCount);
                    for ($seat = 0; $seat < $playerCount; $seat++) {
                        $isAi = $aiMap[$seat];
                        $seatUserId = null;
                        $connected = false;
                        $displayName = null;
                        if ($seat === 0 && !$isAi) {
                            $seatUserId = $userId;
                            $connected = $userId !== null;
                        }

                        if (array_key_exists($seat, $inviteBySeat)) {
                            $seatUserId = (int) $inviteBySeat[$seat];
                            $connected = false;
                        }

                        if ($isAi) {
                            $connected = true;
                            $displayName = $aiNamePool[$aiNameCursor % count($aiNamePool)];
                            $aiNameCursor++;
                        }

                        $repo->addPlayerSeat($gameId, $seat, $seatUserId, $isAi, $connected, $displayName);
                    }
                    $repo->appendEvent($gameId, 'game_created', 0, [
                        'target_score' => $targetScore,
                        'ruleset' => $ruleset,
                        'invite_mode' => $inviteMode,
                        'invites' => $inviteBySeat,
                        'at' => gmdate('c'),
                    ]);

                    // Deal hand 1 immediately so authoritative card state exists from the start
                    $runtime = new GameRuntimeService($repo);
                    $runtime->dealNewHand($gameId, 1, 0);

                    return $gameId;
                });
            } catch (\Throwable $ex) {
                error_log('[/api/lobby/create_game] ' . $ex);
                return $json($response, ['ok' => false, 'error' => 'create_game_failed'], 500);
            }

            return $json($response, ['ok' => true, 'game_id' => $gameId], 201);
        });

        $app->post('/api/lobby/join_game', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf, $requireAuthenticated): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            $body = (array) ($request->getParsedBody() ?? []);
            $gameId = (int) ($body['game_id'] ?? 0);
            $sessionUserId = (int) $authn['user_id'];
            if ($gameId <= 0) {
                return $json($response, ['ok' => false, 'error' => 'game_id is required'], 400);
            }
            // Always join as the authenticated user; reject on mismatch.
            if (isset($body['user_id']) && (int) $body['user_id'] !== $sessionUserId) {
                return $json($response, ['ok' => false, 'error' => 'user_id_session_mismatch'], 403);
            }
            $userId = $sessionUserId;

            /** @var GameRepository $repo */
            $repo = $authn['repo'];
            if (!$repo->gameExists($gameId)) {
                return $json($response, ['ok' => false, 'error' => 'Game not found'], 404);
            }

            $existingSeat = $repo->findSeatForUser($gameId, $userId);
            if ($existingSeat !== null) {
                $repo->markSeatConnected($gameId, $existingSeat, true);
                return $json($response, ['ok' => true, 'seat' => $existingSeat, 'invited' => true]);
            }

            $seat = $repo->findOpenHumanSeat($gameId);
            if ($seat === null) {
                return $json($response, ['ok' => false, 'error' => 'No open human seat found or user is not invited'], 409);
            }

            $assigned = $repo->assignUserToSeat($gameId, $seat, $userId);
            if (!$assigned) {
                return $json($response, ['ok' => false, 'error' => 'Seat assignment failed'], 409);
            }

            $repo->appendEvent($gameId, 'player_joined', $seat, [
                'user_id' => $userId,
                'seat' => $seat,
                'at' => gmdate('c'),
            ]);

            return $json($response, ['ok' => true, 'seat' => $seat]);
        });

        // ── Player profile & stats ────────────────────────────────────────────────

        $app->get('/api/player/stats', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated): ResponseInterface {
            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            $limiter = RateLimiter::default();
            $statsKey = 'player_stats:' . (int) $authn['user_id'];
            if (!$limiter->allow($statsKey, 60, 60)) {
                $retry = $limiter->retryAfterSeconds($statsKey, 60);
                return $json($response, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
            }

            /** @var GameRepository $repo */
            $repo         = $authn['repo'];
            $params       = $request->getQueryParams();
            $targetUserId = (int) ($params['user_id'] ?? 0);
            if ($targetUserId <= 0) {
                $targetUserId = (int) $authn['user_id'];
            }

            $user = $repo->findUserById($targetUserId);
            if ($user === null) {
                return $json($response, ['ok' => false, 'error' => 'user_not_found'], 404);
            }

            $includeAi = !empty($params['include_ai']);

            return $json($response, [
                'ok'    => true,
                'user'  => [
                    'id'           => (int) $user['id'],
                    'username'     => (string) $user['username'],
                    'nickname'     => $user['nickname'] ?? null,
                    'display_name' => $user['nickname'] ?: $user['username'],
                    'avatar_code'  => $user['avatar_code'] ?? null,
                    'gravatar_url' => 'https://www.gravatar.com/avatar/' . md5(strtolower(trim((string) ($user['email'] ?? '')))) . '?s=80&d=identicon',
                    'role'         => (string) ($user['role'] ?? 'player'),
                    'member_since' => substr((string) ($user['created_at'] ?? ''), 0, 10),
                    'email'        => (int) $authn['user_id'] === $targetUserId ? (string) ($user['email'] ?? '') : null,
                ],
                'stats' => $repo->statsForUser($targetUserId, $includeAi),
            ]);
        });

        $app->post('/api/player/update_nickname', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            /** @var GameRepository $repo */
            $repo     = $authn['repo'];
            $userId   = (int) $authn['user_id'];
            $body     = (array) ($request->getParsedBody() ?? []);
            $nickname = trim((string) ($body['nickname'] ?? ''));

            if (mb_strlen($nickname) > 60) {
                return $json($response, ['ok' => false, 'error' => 'nickname_too_long'], 400);
            }

            $repo->updateNickname($userId, $nickname ?: null);
            return $json($response, ['ok' => true, 'display_name' => $nickname ?: null]);
        });

        $app->post('/api/player/update_avatar', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            /** @var GameRepository $repo */
            $repo       = $authn['repo'];
            $userId     = (int) $authn['user_id'];
            $body       = (array) ($request->getParsedBody() ?? []);
            $avatarCode = trim((string) ($body['avatar_code'] ?? ''));

            if ($avatarCode === '' || mb_strlen($avatarCode) > 20) {
                return $json($response, ['ok' => false, 'error' => 'invalid_avatar_code'], 400);
            }

            $repo->updateAvatar($userId, $avatarCode);
            return $json($response, ['ok' => true]);
        });

        $app->post('/api/player/update_password', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            /** @var GameRepository $repo */
            $repo            = $authn['repo'];
            $userId          = (int) $authn['user_id'];
            $body            = (array) ($request->getParsedBody() ?? []);
            $currentPassword = (string) ($body['current_password'] ?? '');
            $newPassword     = (string) ($body['new_password']     ?? '');

            if (mb_strlen($newPassword) < 8) {
                return $json($response, ['ok' => false, 'error' => 'password_too_short'], 400);
            }

            $user = $repo->findUserById($userId);
            if ($user === null || !password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
                return $json($response, ['ok' => false, 'error' => 'wrong_current_password'], 403);
            }

            $repo->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
            return $json($response, ['ok' => true]);
        });

        $app->post('/api/player/update_email', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            /** @var GameRepository $repo */
            $repo   = $authn['repo'];
            $userId = (int) $authn['user_id'];
            $body   = (array) ($request->getParsedBody() ?? []);
            $email  = trim((string) ($body['email'] ?? ''));

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $json($response, ['ok' => false, 'error' => 'invalid_email'], 400);
            }

            $repo->updateEmail($userId, $email);
            $gravatarUrl = 'https://www.gravatar.com/avatar/' . md5(strtolower($email)) . '?s=80&d=identicon';
            return $json($response, ['ok' => true, 'gravatar_url' => $gravatarUrl]);
        });

        // ── Lobby ────────────────────────────────────────────────────────────────

        $app->get('/api/lobby/my_games', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated): ResponseInterface {
            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            $params = $request->getQueryParams();
            $sessionUserId = (int) $authn['user_id'];
            if (isset($params['user_id']) && (int) $params['user_id'] !== $sessionUserId) {
                return $json($response, ['ok' => false, 'error' => 'user_id_session_mismatch'], 403);
            }
            $userId = $sessionUserId;

            /** @var GameRepository $repo */
            $repo = $authn['repo'];

            $games   = $repo->listGamesForUser($userId, 200);
            $gameIds = array_column($games, 'id');
            $flat    = $repo->listPlayersWithUsernamesForGames($gameIds);
            $byGame  = [];
            foreach ($flat as $p) {
                $byGame[$p['game_id']][] = $p;
            }
            foreach ($games as &$g) {
                $g['players'] = $byGame[$g['id']] ?? [];
            }
            unset($g);

            return $json($response, ['ok' => true, 'games' => $games]);
        });

        $app->get('/api/lobby/list_joinable', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated): ResponseInterface {
            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            $params = $request->getQueryParams();
            $sessionUserId = (int) $authn['user_id'];
            if (isset($params['user_id']) && (int) $params['user_id'] !== $sessionUserId) {
                return $json($response, ['ok' => false, 'error' => 'user_id_session_mismatch'], 403);
            }
            $userId = $sessionUserId;

            /** @var GameRepository $repo */
            $repo = $authn['repo'];

            $games   = $repo->listJoinableGamesForUser($userId, 200);
            $gameIds = array_column($games, 'id');
            $flat    = $repo->listPlayersWithUsernamesForGames($gameIds);
            $byGame  = [];
            foreach ($flat as $p) {
                $byGame[$p['game_id']][] = $p;
            }
            foreach ($games as &$g) {
                $g['players'] = $byGame[$g['id']] ?? [];
            }
            unset($g);

            return $json($response, ['ok' => true, 'games' => $games]);
        });

        $app->get('/api/lobby/users', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            $auth = new SessionAuth();
            $viewerUserId = $auth->userId();
            if ($viewerUserId === null) {
                return $json($response, ['ok' => false, 'error' => 'authentication_required'], 401);
            }
            $repo = new GameRepository(Database::fromConfig());
            $users = $repo->listUsersForLobby($viewerUserId);
            return $json($response, ['ok' => true, 'users' => $users]);
        });

        $app->get('/api/game/get_state', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            try {
                $params = $request->getQueryParams();
                $gameId = (int) ($params['game_id'] ?? 0);
                if ($gameId <= 0) {
                    return $json($response, ['ok' => false, 'error' => 'game_id is required'], 400);
                }

                $repo = new GameRepository(Database::fromConfig());
                $auth = new SessionAuth();
                $viewerUserId = $auth->userId();
                if ($viewerUserId === null) {
                    return $json($response, ['ok' => false, 'error' => 'authentication_required'], 401);
                }

                $state = $repo->findGameState($gameId);
                if ($state === null) {
                    return $json($response, ['ok' => false, 'error' => 'Game not found'], 404);
                }

                if (!$repo->userExists($viewerUserId)) {
                    return $json($response, ['ok' => false, 'error' => 'session_user_not_found'], 401);
                }

                $isPrivileged = $repo->userIsOwnerOrAdmin($viewerUserId);
                if (!$isPrivileged && !$repo->userInGame($viewerUserId, $gameId)) {
                    return $json($response, ['ok' => false, 'error' => 'forbidden_for_viewer'], 403);
                }

                // Advance AI turns so the returned state already reflects AI moves.
                (new GameRuntimeService($repo))->runAiTurns($gameId, new AlgorithmicMoveProvider());

                // Re-fetch state after AI may have mutated it
                $state = $repo->findGameState($gameId) ?? $state;

                $players    = $repo->listPlayers($gameId);
                $events     = $repo->listEventsAfter($gameId, 0, 300);
                $viewerSeat = $repo->findSeatForUser($gameId, $viewerUserId);
                $currentHand = $repo->findCurrentHand($gameId);

                // Authoritative hand from hand_cards table
                $viewerHand = [];
                if ($viewerSeat !== null && $currentHand !== null) {
                    $viewerHand = $repo->getSeatCards((int) $currentHand['id'], $viewerSeat) ?? [];
                }

                // Sanitised hand info for the client
                $handInfo = null;
                if ($currentHand !== null) {
                    $handInfo = [
                        'trump_suit'               => $currentHand['trump_suit'],
                        'bid_winner_seat'           => $currentHand['bid_winner_seat'],
                        'bid_value'                 => $currentHand['bid_value'],
                        'is_30_for_60'              => (bool) ($currentHand['is_30_for_60'] ?? false),
                        'dealer_extra_draw_pending' => (bool) ($currentHand['dealer_extra_draw_pending'] ?? false),
                    ];
                }

                $scores = $repo->getRunningScores($gameId);

                return $json($response, [
                    'ok'          => true,
                    'game'        => $state,
                    'players'     => $players,
                    'events'      => $events,
                    'viewer_seat' => $viewerSeat,
                    'viewer_hand' => $viewerHand,
                    'hand'        => $handInfo,
                    'scores'      => $scores,
                ]);
            } catch (\Throwable $ex) {
                error_log('[/api/game/get_state] ' . $ex);
                return $json($response, ['ok' => false, 'error' => 'get_state_failed'], 500);
            }
        });

        $app->get('/api/game/poll_events', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireAuthenticated): ResponseInterface {
            $authn = $requireAuthenticated($response);
            if (isset($authn['error_response'])) {
                return $authn['error_response'];
            }

            $params = $request->getQueryParams();
            $gameId = (int) ($params['game_id'] ?? 0);
            $afterSeq = (int) ($params['after_seq'] ?? 0);
            if ($gameId <= 0) {
                return $json($response, ['ok' => false, 'error' => 'game_id is required'], 400);
            }

            /** @var GameRepository $repo */
            $repo = $authn['repo'];
            $viewerUserId = (int) $authn['user_id'];
            $isPrivileged = $repo->userIsOwnerOrAdmin($viewerUserId);
            if (!$isPrivileged && !$repo->userInGame($viewerUserId, $gameId)) {
                return $json($response, ['ok' => false, 'error' => 'forbidden_for_viewer'], 403);
            }

            $events = $repo->listEventsAfter($gameId, $afterSeq, 100);

            return $json($response, [
                'ok' => true,
                'events' => $events,
                'last_seq' => empty($events) ? $afterSeq : (int) $events[array_key_last($events)]['seq_no'],
            ]);
        });

        $app->post('/api/game/submit_bid', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf, $requireSeatOwnership): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $body = (array) ($request->getParsedBody() ?? []);
            $gameId = (int) ($body['game_id'] ?? 0);
            $seat = (int) ($body['seat'] ?? -1);
            if ($gameId <= 0 || $seat < 0 || $seat > 5) {
                return $json($response, ['ok' => false, 'error' => 'game_id and seat are required'], 400);
            }

            $authz = $requireSeatOwnership($response, $gameId, $seat);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authz['repo'];
            $runtime = new GameRuntimeService($repo);
            $result = $runtime->handle(new ActionCommand($gameId, $seat, 'submit_bid', [
                'bid' => $body['bid'] ?? 'pass',
            ]));

            $status = $result->accepted ? 200 : 409;
            return $json($response, [
                'ok' => $result->accepted,
                'code' => $result->code,
                'message' => $result->message,
                'state_patch' => $result->statePatch,
            ], $status);
        });

        $app->post('/api/game/play_card', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf, $requireSeatOwnership): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $body = (array) ($request->getParsedBody() ?? []);
            $gameId = (int) ($body['game_id'] ?? 0);
            $seat = (int) ($body['seat'] ?? -1);
            $card = (string) ($body['card'] ?? '');
            if ($gameId <= 0 || $seat < 0 || $seat > 5 || $card === '') {
                return $json($response, ['ok' => false, 'error' => 'game_id, seat, and card are required'], 400);
            }

            $authz = $requireSeatOwnership($response, $gameId, $seat);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo = $authz['repo'];
            $runtime = new GameRuntimeService($repo);
            $result = $runtime->handle(new ActionCommand($gameId, $seat, 'play_card', [
                'card' => $card,
            ]));

            $status = $result->accepted ? 200 : 409;
            return $json($response, [
                'ok' => $result->accepted,
                'code' => $result->code,
                'message' => $result->message,
                'state_patch' => $result->statePatch,
            ], $status);
        });

        $app->post('/api/game/declare_trump', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf, $requireSeatOwnership): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $body   = (array) ($request->getParsedBody() ?? []);
            $gameId = (int) ($body['game_id'] ?? 0);
            $seat   = (int) ($body['seat'] ?? -1);
            $trump  = strtoupper(trim((string) ($body['trump'] ?? '')));
            if ($gameId <= 0 || $seat < 0 || $seat > 5 || $trump === '') {
                return $json($response, ['ok' => false, 'error' => 'game_id, seat, and trump are required'], 400);
            }

            $authz = $requireSeatOwnership($response, $gameId, $seat);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo    = $authz['repo'];
            $runtime = new GameRuntimeService($repo);
            $result  = $runtime->handle(new ActionCommand($gameId, $seat, 'declare_trump', [
                'trump' => $trump,
            ]));

            $status = $result->accepted ? 200 : 409;
            return $json($response, [
                'ok'          => $result->accepted,
                'code'        => $result->code,
                'message'     => $result->message,
                'state_patch' => $result->statePatch,
            ], $status);
        });

        $app->post('/api/game/discard_cards', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf, $requireSeatOwnership): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $body    = (array) ($request->getParsedBody() ?? []);
            $gameId  = (int) ($body['game_id'] ?? 0);
            $seat    = (int) ($body['seat'] ?? -1);
            $cards   = $body['cards'] ?? [];
            if ($gameId <= 0 || $seat < 0 || $seat > 5 || !is_array($cards)) {
                return $json($response, ['ok' => false, 'error' => 'game_id, seat, and cards[] are required'], 400);
            }

            $authz = $requireSeatOwnership($response, $gameId, $seat);
            if (isset($authz['error_response'])) {
                return $authz['error_response'];
            }

            /** @var GameRepository $repo */
            $repo    = $authz['repo'];
            $runtime = new GameRuntimeService($repo);
            $result  = $runtime->handle(new ActionCommand($gameId, $seat, 'discard_cards', [
                'cards' => $cards,
            ]));

            $status = $result->accepted ? 200 : 409;
            return $json($response, [
                'ok'          => $result->accepted,
                'code'        => $result->code,
                'message'     => $result->message,
                'state_patch' => $result->statePatch,
            ], $status);
        });
    }

    /**
     * Verify an Apple id_token JWT using Apple's public JWKS.
     * Returns the decoded payload array on success, or false on failure.
     *
     * @return array<string,mixed>|false
     */
    private static function verifyAppleIdToken(string $idToken, string $expectedAud): array|false
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return false;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header  = json_decode(base64_decode(strtr($headerB64,  '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);

        if (!is_array($header) || !is_array($payload)) {
            return false;
        }

        // Validate standard claims
        if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
            return false;
        }
        if ($expectedAud !== '' && ($payload['aud'] ?? '') !== $expectedAud) {
            return false;
        }
        if (($payload['exp'] ?? 0) < time()) {
            return false;
        }
        if (!isset($payload['sub'])) {
            return false;
        }

        // Fetch Apple's public JWKS
        $jwksJson = @file_get_contents('https://appleid.apple.com/auth/keys');
        if ($jwksJson === false) {
            return false;
        }
        $jwks = json_decode($jwksJson, true);
        if (!is_array($jwks)) {
            return false;
        }

        // Find the key matching the token's kid header
        $kid       = (string) ($header['kid'] ?? '');
        $matchedKey = null;
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? '') === $kid) {
                $matchedKey = $key;
                break;
            }
        }
        if ($matchedKey === null) {
            return false;
        }

        // Build RSA public key PEM from JWK n/e
        $pem = self::rsaJwkToPem($matchedKey);
        if ($pem === null) {
            return false;
        }

        // Verify RS256 signature
        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature    = base64_decode(strtr($signatureB64, '-_', '+/'));
        $result       = openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256);

        if ($result !== 1) {
            return false;
        }

        return $payload;
    }

    /**
     * Convert an RSA JWK (with n and e fields) into a PEM public key string.
     *
     * @param array<string,mixed> $jwk
     */
    private static function rsaJwkToPem(array $jwk): ?string
    {
        $n = isset($jwk['n']) ? base64_decode(strtr((string) $jwk['n'], '-_', '+/')) : null;
        $e = isset($jwk['e']) ? base64_decode(strtr((string) $jwk['e'], '-_', '+/')) : null;

        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }

        // Encode value as ASN.1 DER integer (prepend 0x00 if high bit is set)
        $encodeInt = static function (string $bytes): string {
            if (ord($bytes[0]) & 0x80) {
                $bytes = "\x00" . $bytes;
            }
            return $bytes;
        };

        // Encode a DER length in minimal form
        $encodeLen = static function (int $len): string {
            if ($len < 0x80) {
                return chr($len);
            }
            if ($len < 0x100) {
                return "\x81" . chr($len);
            }
            return "\x82" . chr($len >> 8) . chr($len & 0xff);
        };

        $nDer  = "\x02" . $encodeLen(strlen($encodeInt($n))) . $encodeInt($n);
        $eDer  = "\x02" . $encodeLen(strlen($encodeInt($e))) . $encodeInt($e);
        $inner = $nDer . $eDer;
        $seq   = "\x30" . $encodeLen(strlen($inner)) . $inner;

        // rsaEncryption OID: 1.2.840.113549.1.1.1, null params
        $algId  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitStr = "\x03" . $encodeLen(strlen($seq) + 1) . "\x00" . $seq;
        $spki   = "\x30" . $encodeLen(strlen($algId) + strlen($bitStr)) . $algId . $bitStr;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
