<?php

declare(strict_types=1);

namespace FortyFives\Infrastructure\Persistence;

final class GameRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function createGame(int $targetScore, string $ruleset, ?int $createdByUserId = null): int
    {
        $sql = 'INSERT INTO games (status, target_score, ruleset, dealer_seat, current_phase, current_turn_seat, hand_number, created_by_user_id)
                VALUES (:status, :target_score, :ruleset, :dealer_seat, :current_phase, :current_turn_seat, :hand_number, :created_by_user_id)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'status' => 'active',
            'target_score' => $targetScore,
            'ruleset' => $ruleset,
            'dealer_seat' => 0,
            'current_phase' => 'bidding',
            'current_turn_seat' => 0,
            'hand_number' => 1,
            'created_by_user_id' => $createdByUserId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function userExists(int $userId): bool
    {
        $sql = 'SELECT 1 FROM users WHERE id = :id LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    public function userRole(int $userId): ?string
    {
        $sql = 'SELECT role FROM users WHERE id = :id LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $role = $stmt->fetchColumn();

        return is_string($role) ? $role : null;
    }

    public function userIsOwnerOrAdmin(int $userId): bool
    {
        $role = $this->userRole($userId);
        return $role === 'owner' || $role === 'admin';
    }

    public function userInGame(int $userId, int $gameId): bool
    {
        $sql = 'SELECT 1
                FROM game_players
                WHERE game_id = :game_id AND user_id = :user_id
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
            'user_id' => $userId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function createUser(
        string $username,
        ?string $email,
        ?string $passwordHash,
        string $role = 'player',
        string $authProvider = 'local',
        ?string $externalSub = null
    ): int {
        $sql = 'INSERT INTO users (username, email, password_hash, role, auth_provider, external_sub)
                VALUES (:username, :email, :password_hash, :role, :auth_provider, :external_sub)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'auth_provider' => $authProvider,
            'external_sub' => $externalSub,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function findUserById(int $userId): ?array
    {
        $sql = 'SELECT id, username, email, password_hash, role, auth_provider, external_sub, google_sub, created_at, updated_at
                FROM users
                WHERE id = :id
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findUserByUsername(string $username): ?array
    {
        $sql = 'SELECT id, username, email, password_hash, role, auth_provider, external_sub, google_sub, created_at, updated_at
                FROM users
                WHERE username = :username
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findUserByProviderSub(string $authProvider, string $externalSub): ?array
    {
        $sql = 'SELECT id, username, email, password_hash, role, auth_provider, external_sub, google_sub, created_at, updated_at
                FROM users
                WHERE auth_provider = :auth_provider AND external_sub = :external_sub
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'auth_provider' => $authProvider,
            'external_sub' => $externalSub,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function createGoogleUser(string $username, ?string $email, string $googleSub, string $role = 'player'): int
    {
        $sql = 'INSERT INTO users (username, email, password_hash, google_sub, role, auth_provider, external_sub)
                VALUES (:username, :email, NULL, :google_sub, :role, :auth_provider, :external_sub)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'google_sub' => $googleSub,
            'role' => $role,
            'auth_provider' => 'google',
            'external_sub' => $googleSub,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateUserRole(int $userId, string $role): bool
    {
        $sql = 'UPDATE users SET role = :role WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'role' => $role,
            'id' => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function listUsers(int $limit = 200): array
    {
        $sql = 'SELECT id, username, email, role, auth_provider, external_sub, created_at, updated_at
                FROM users
                ORDER BY id ASC
                LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function listGames(int $limit = 200): array
    {
        $sql = 'SELECT id, status, target_score, ruleset, dealer_seat, current_phase, current_turn_seat, hand_number, created_by_user_id, created_at, updated_at
                FROM games
                ORDER BY id DESC
                LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function listGamesForUser(int $userId, int $limit = 200): array
    {
        $sql = 'SELECT g.id, g.status, g.target_score, g.ruleset, g.dealer_seat, g.current_phase, g.current_turn_seat, g.hand_number, g.created_by_user_id, g.created_at, g.updated_at
                FROM games g
                INNER JOIN game_players gp ON gp.game_id = g.id
                WHERE gp.user_id = :user_id
                ORDER BY g.id DESC
                LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function gameExists(int $gameId): bool
    {
        $sql = 'SELECT 1 FROM games WHERE id = :id LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $gameId]);

        return (bool) $stmt->fetchColumn();
    }

    public function addPlayerSeat(int $gameId, int $seat, ?int $userId, bool $isAi, bool $connected = true): void
    {
        $sql = 'INSERT INTO game_players (game_id, seat, user_id, is_ai, team, connected)
                VALUES (:game_id, :seat, :user_id, :is_ai, :team, :connected)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
            'seat' => $seat,
            'user_id' => $userId,
            'is_ai' => $isAi ? 1 : 0,
            'team' => $seat % 2,
            'connected' => $connected ? 1 : 0,
        ]);
    }

    public function findSeatForUser(int $gameId, int $userId): ?int
    {
        $sql = 'SELECT seat
                FROM game_players
                WHERE game_id = :game_id AND user_id = :user_id
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();

        return $row ? (int) $row['seat'] : null;
    }

    public function markSeatConnected(int $gameId, int $seat, bool $connected): void
    {
        $sql = 'UPDATE game_players
                SET connected = :connected
                WHERE game_id = :game_id AND seat = :seat';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'connected' => $connected ? 1 : 0,
            'game_id' => $gameId,
            'seat' => $seat,
        ]);
    }

    public function listJoinableGamesForUser(int $userId, int $limit = 200): array
    {
        $sql = 'SELECT
                    g.id,
                    g.status,
                    g.target_score,
                    g.ruleset,
                    g.current_phase,
                    g.created_at,
                    g.updated_at,
                    CASE WHEN invited.seat IS NOT NULL THEN 1 ELSE 0 END AS invited,
                    COALESCE(open_slots.open_human_seats, 0) AS open_human_seats
                FROM games g
                LEFT JOIN (
                    SELECT game_id, MIN(seat) AS seat
                    FROM game_players
                    WHERE user_id = :user_id
                    GROUP BY game_id
                ) invited ON invited.game_id = g.id
                LEFT JOIN (
                    SELECT game_id, COUNT(*) AS open_human_seats
                    FROM game_players
                    WHERE is_ai = 0 AND user_id IS NULL
                    GROUP BY game_id
                ) open_slots ON open_slots.game_id = g.id
                WHERE g.status IN ("lobby", "active")
                  AND (invited.seat IS NOT NULL OR COALESCE(open_slots.open_human_seats, 0) > 0)
                ORDER BY g.id DESC
                LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findOpenHumanSeat(int $gameId): ?int
    {
        $sql = 'SELECT gp.seat
                FROM game_players gp
                WHERE gp.game_id = :game_id AND gp.is_ai = 0 AND gp.user_id IS NULL
                ORDER BY gp.seat ASC
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        return $row ? (int) $row['seat'] : null;
    }

    public function assignUserToSeat(int $gameId, int $seat, int $userId): bool
    {
        $sql = 'UPDATE game_players
                SET user_id = :user_id, connected = 1
                WHERE game_id = :game_id AND seat = :seat AND user_id IS NULL AND is_ai = 0';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
            'seat' => $seat,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function listPlayers(int $gameId): array
    {
        $sql = 'SELECT seat, user_id, is_ai, team, connected
                FROM game_players
                WHERE game_id = :game_id
                ORDER BY seat ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetchAll();
    }

    public function listEventsAfter(int $gameId, int $afterSeq, int $limit = 100): array
    {
        $sql = 'SELECT seq_no, event_type, actor_seat, payload_json, created_at
                FROM game_events
                WHERE game_id = :game_id AND seq_no > :after_seq
                ORDER BY seq_no ASC
                LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':game_id', $gameId, \PDO::PARAM_INT);
        $stmt->bindValue(':after_seq', $afterSeq, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $events = $stmt->fetchAll();
        foreach ($events as &$event) {
            $event['payload'] = json_decode((string) $event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($event['payload_json']);
        }

        return $events;
    }

    public function appendEvent(int $gameId, string $eventType, ?int $actorSeat, array $payload): int
    {
        $nextSeq = $this->nextEventSeq($gameId);
        $sql = 'INSERT INTO game_events (game_id, seq_no, event_type, actor_seat, payload_json)
                VALUES (:game_id, :seq_no, :event_type, :actor_seat, :payload_json)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
            'seq_no' => $nextSeq,
            'event_type' => $eventType,
            'actor_seat' => $actorSeat,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $nextSeq;
    }

    public function bidSummary(int $gameId): array
    {
        $sql = 'SELECT actor_seat, payload_json
                FROM game_events
                WHERE game_id = :game_id AND event_type = :event_type
                ORDER BY seq_no ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
            'event_type' => 'bid_action',
        ]);
        $rows = $stmt->fetchAll();

        $highestBid = 0;
        $highestSeat = null;
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $bid = $payload['bid'] ?? null;
            if (is_int($bid) && $bid > $highestBid) {
                $highestBid = $bid;
                $highestSeat = (int) $row['actor_seat'];
            }
        }

        return [
            'count' => count($rows),
            'highest_bid' => $highestBid,
            'highest_seat' => $highestSeat,
        ];
    }

    public function setPhaseAndTurn(int $gameId, string $phase, int $turnSeat): void
    {
        $sql = 'UPDATE games SET current_phase = :phase, current_turn_seat = :turn_seat WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'phase' => $phase,
            'turn_seat' => $turnSeat,
            'id' => $gameId,
        ]);
    }

    public function setTurn(int $gameId, int $turnSeat): void
    {
        $sql = 'UPDATE games SET current_turn_seat = :turn_seat WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'turn_seat' => $turnSeat,
            'id' => $gameId,
        ]);
    }

    public function withTransaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }

    public function findGameState(int $gameId): ?array
    {
        $sql = 'SELECT id, status, target_score, ruleset, dealer_seat, current_phase, current_turn_seat, hand_number
                FROM games WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $gameId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function statsSummary(): array
    {
        $sql = 'SELECT
                    (SELECT COUNT(*) FROM users) AS total_users,
                    (SELECT COUNT(*) FROM games) AS total_games,
                    (SELECT COUNT(*) FROM games WHERE status = "active") AS active_games,
                    (SELECT COUNT(*) FROM games WHERE status = "finished") AS finished_games,
                    (SELECT COUNT(*) FROM games WHERE status = "lobby") AS lobby_games,
                    (SELECT COUNT(*) FROM game_events) AS total_events';
        $stmt = $this->db->pdo()->query($sql);
        $row = $stmt ? $stmt->fetch() : false;

        return $row ?: [
            'total_users' => 0,
            'total_games' => 0,
            'active_games' => 0,
            'finished_games' => 0,
            'lobby_games' => 0,
            'total_events' => 0,
        ];
    }

    public function statsUserLeaderboard(int $limit = 10): array
    {
        $sql = 'SELECT
                    u.id,
                    u.username,
                    COUNT(DISTINCT gp.game_id) AS games_played,
                    SUM(CASE WHEN g.status = "finished" THEN 1 ELSE 0 END) AS finished_games
                FROM users u
                LEFT JOIN game_players gp ON gp.user_id = u.id
                LEFT JOIN games g ON g.id = gp.game_id
                GROUP BY u.id, u.username
                ORDER BY games_played DESC, finished_games DESC, u.id ASC
                LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function nextEventSeq(int $gameId): int
    {
        $sql = 'SELECT COALESCE(MAX(seq_no), 0) + 1 AS next_seq FROM game_events WHERE game_id = :game_id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        return (int) ($row['next_seq'] ?? 1);
    }
}
