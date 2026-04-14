<?php

declare(strict_types=1);

namespace FortyFives\Infrastructure\Http;

use FortyFives\Application\Contracts\ActionCommand;
use FortyFives\Application\Services\GameRuntimeService;
use FortyFives\Infrastructure\Auth\SessionAuth;
use FortyFives\Infrastructure\Persistence\Database;
use FortyFives\Infrastructure\Persistence\GameRepository;
use FortyFives\Infrastructure\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

final class Routes
{
    public static function register(App $app): void
    {
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
                    ->withHeader('Location', '/45s/lobby.html')
                    ->withStatus(302);
            }

            $payload = [
                'ok' => true,
                'service' => '45s-backend',
                'message' => 'Service is running',
                'health' => '/45s/health',
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
                return $json($response, ['ok' => false, 'error' => 'register_failed', 'detail' => $ex->getMessage()], 409);
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

        $app->post('/api/auth/google/login', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
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

            $googleSub = (string) $claims['sub'];
            $email = isset($claims['email']) ? (string) $claims['email'] : null;
            $usernameFallback = $email !== null ? explode('@', $email)[0] : ('google_' . substr($googleSub, 0, 12));
            $usernameBase = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $usernameFallback) ?: 'google_user';

            $repo = new GameRepository(Database::fromConfig());
            $user = $repo->findUserByProviderSub('google', $googleSub);
            if ($user === null) {
                $candidate = $usernameBase;
                $suffix = 1;
                while ($repo->findUserByUsername($candidate) !== null) {
                    $suffix++;
                    $candidate = $usernameBase . '_' . $suffix;
                }

                $userId = $repo->createGoogleUser($candidate, $email, $googleSub, 'player');
                $user = $repo->findUserById($userId);
            }

            if ($user === null) {
                return $json($response, ['ok' => false, 'error' => 'google_login_failed'], 500);
            }

            $auth = new SessionAuth();
            $auth->signIn((int) $user['id']);

            return $json($response, [
                'ok' => true,
                'user_id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'role' => (string) ($user['role'] ?? 'player'),
                'auth_provider' => 'google',
                'csrf_token' => $auth->csrfToken(),
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
                return $json($response, ['ok' => false, 'error' => 'failed_to_create_user', 'detail' => $ex->getMessage()], 409);
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

        $app->post('/api/lobby/create_game', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $body = (array) ($request->getParsedBody() ?? []);
            $targetScore = (int) ($body['target_score'] ?? 120);
            if (!in_array($targetScore, [45, 120], true)) {
                return $json($response, ['ok' => false, 'error' => 'target_score must be 45 or 120'], 400);
            }

            $ruleset = (string) ($body['ruleset'] ?? 'chartrand');
            $userId = isset($body['user_id']) ? (int) $body['user_id'] : null;
            $aiSeats = (array) ($body['ai_seats'] ?? []);
            $inviteMode = strtolower((string) ($body['invite_mode'] ?? 'open'));
            $invites = (array) ($body['invites'] ?? []);
            $aiMap = [0 => false, 1 => false, 2 => false, 3 => false];
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
                if ($seat < 1 || $seat > 5) {
                    return $json($response, ['ok' => false, 'error' => 'invite seat must be in range 1..3'], 400);
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

            $aiMap[0] = false;

            $repo = new GameRepository(Database::fromConfig());
            if ($userId !== null && !$repo->userExists($userId)) {
                return $json($response, ['ok' => false, 'error' => 'user_id does not exist'], 400);
            }

            foreach (array_keys($inviteUserIds) as $inviteUserId) {
                if (!$repo->userExists((int) $inviteUserId)) {
                    return $json($response, ['ok' => false, 'error' => 'invited user_id does not exist'], 400);
                }
            }

            if ($userId !== null && isset($inviteUserIds[$userId])) {
                return $json($response, ['ok' => false, 'error' => 'creator cannot also be invited'], 400);
            }

            try {
                $gameId = $repo->withTransaction(function () use ($repo, $targetScore, $ruleset, $userId, $aiMap, $inviteBySeat, $inviteMode): int {
                    $gameId = $repo->createGame($targetScore, $ruleset, $userId);
                    for ($seat = 0; $seat < 4; $seat++) {
                        $isAi = $aiMap[$seat];
                        $seatUserId = null;
                        $connected = false;
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
                        }

                        $repo->addPlayerSeat($gameId, $seat, $seatUserId, $isAi, $connected);
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
                return $json($response, ['ok' => false, 'error' => 'create_game_failed', 'detail' => $ex->getMessage()], 500);
            }

            return $json($response, ['ok' => true, 'game_id' => $gameId], 201);
        });

        $app->post('/api/lobby/join_game', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf): ResponseInterface {
            $csrfError = $requireCsrf($request, $response);
            if ($csrfError !== null) {
                return $csrfError;
            }

            $body = (array) ($request->getParsedBody() ?? []);
            $gameId = (int) ($body['game_id'] ?? 0);
            $userId = (int) ($body['user_id'] ?? 0);
            if ($gameId <= 0 || $userId <= 0) {
                return $json($response, ['ok' => false, 'error' => 'game_id and user_id are required'], 400);
            }

            $repo = new GameRepository(Database::fromConfig());
            if (!$repo->gameExists($gameId)) {
                return $json($response, ['ok' => false, 'error' => 'Game not found'], 404);
            }
            if (!$repo->userExists($userId)) {
                return $json($response, ['ok' => false, 'error' => 'user_id does not exist'], 400);
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

        $app->get('/api/lobby/my_games', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            $params = $request->getQueryParams();
            $userId = (int) ($params['user_id'] ?? 0);
            if ($userId <= 0) {
                return $json($response, ['ok' => false, 'error' => 'user_id is required'], 400);
            }

            $repo = new GameRepository(Database::fromConfig());
            if (!$repo->userExists($userId)) {
                return $json($response, ['ok' => false, 'error' => 'user_id does not exist'], 400);
            }

            return $json($response, [
                'ok' => true,
                'games' => $repo->listGamesForUser($userId, 200),
            ]);
        });

        $app->get('/api/lobby/list_joinable', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            $params = $request->getQueryParams();
            $userId = (int) ($params['user_id'] ?? 0);
            if ($userId <= 0) {
                return $json($response, ['ok' => false, 'error' => 'user_id is required'], 400);
            }

            $repo = new GameRepository(Database::fromConfig());
            if (!$repo->userExists($userId)) {
                return $json($response, ['ok' => false, 'error' => 'user_id does not exist'], 400);
            }

            return $json($response, [
                'ok' => true,
                'games' => $repo->listJoinableGamesForUser($userId, 200),
            ]);
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

                $players    = $repo->listPlayers($gameId);
                $events     = $repo->listEventsAfter($gameId, 0, 200);
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
                return $json($response, ['ok' => false, 'error' => 'get_state_failed', 'detail' => $ex->getMessage()], 500);
            }
        });

        $app->get('/api/game/poll_events', function (ServerRequestInterface $request, ResponseInterface $response) use ($json): ResponseInterface {
            $params = $request->getQueryParams();
            $gameId = (int) ($params['game_id'] ?? 0);
            $afterSeq = (int) ($params['after_seq'] ?? 0);
            if ($gameId <= 0) {
                return $json($response, ['ok' => false, 'error' => 'game_id is required'], 400);
            }

            $repo = new GameRepository(Database::fromConfig());
            $events = $repo->listEventsAfter($gameId, $afterSeq, 100);

            return $json($response, [
                'ok' => true,
                'events' => $events,
                'last_seq' => empty($events) ? $afterSeq : (int) $events[array_key_last($events)]['seq_no'],
            ]);
        });

        $app->post('/api/game/submit_bid', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf): ResponseInterface {
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

            $repo = new GameRepository(Database::fromConfig());
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

        $app->post('/api/game/play_card', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf): ResponseInterface {
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

            $repo = new GameRepository(Database::fromConfig());
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

        $app->post('/api/game/declare_trump', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf): ResponseInterface {
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

            $repo    = new GameRepository(Database::fromConfig());
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

        $app->post('/api/game/discard_cards', function (ServerRequestInterface $request, ResponseInterface $response) use ($json, $requireCsrf): ResponseInterface {
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

            $repo    = new GameRepository(Database::fromConfig());
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
}
