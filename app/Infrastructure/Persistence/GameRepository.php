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
            $payloadRaw = (string) $event['payload_json'];
            try {
                $decoded = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
                $event['payload'] = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $event['payload'] = [
                    '_decode_error' => true,
                ];
            }
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

    public function countEventsByType(int $gameId, string $eventType): int
    {
        $sql = 'SELECT COUNT(*)
                FROM game_events
                WHERE game_id = :game_id AND event_type = :event_type';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
            'event_type' => $eventType,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function listRecentEventsByType(int $gameId, string $eventType, int $limit = 4): array
    {
        $sql = 'SELECT seq_no, event_type, actor_seat, payload_json, created_at
                FROM game_events
                WHERE game_id = :game_id AND event_type = :event_type
                ORDER BY seq_no DESC
                LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':game_id', $gameId, \PDO::PARAM_INT);
        $stmt->bindValue(':event_type', $eventType, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $events = $stmt->fetchAll();
        $events = array_reverse($events);
        foreach ($events as &$event) {
            $payloadRaw = (string) $event['payload_json'];
            try {
                $decoded = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
                $event['payload'] = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $event['payload'] = [
                    '_decode_error' => true,
                ];
            }
            unset($event['payload_json']);
        }

        return $events;
    }

    public function latestEventByType(int $gameId, string $eventType): ?array
    {
        $sql = 'SELECT seq_no, event_type, actor_seat, payload_json, created_at
                FROM game_events
                WHERE game_id = :game_id AND event_type = :event_type
                ORDER BY seq_no DESC
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
            'event_type' => $eventType,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $payloadRaw = (string) $row['payload_json'];
        try {
            $decoded = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
            $row['payload'] = is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            $row['payload'] = [
                '_decode_error' => true,
            ];
        }
        unset($row['payload_json']);
        return $row;
    }

    public function latestSeatHand(int $gameId, int $seat): ?array
    {
        $sql = 'SELECT payload_json
                FROM game_events
                WHERE game_id = :game_id AND event_type IN ("hand_dealt", "hand_updated")
                ORDER BY seq_no DESC
                LIMIT 300';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
        ]);

        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            try {
                $decoded = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            $payload = is_array($decoded) ? $decoded : [];
            $eventSeat = isset($payload['seat']) ? (int) $payload['seat'] : -1;
            if ($eventSeat !== $seat) {
                continue;
            }

            $cards = (array) ($payload['cards'] ?? []);
            $clean = [];
            foreach ($cards as $card) {
                $cardCode = strtoupper(trim((string) $card));
                if ($cardCode !== '') {
                    $clean[] = $cardCode;
                }
            }
            return $clean;
        }

        return null;
    }

    public function listEventsByType(int $gameId, string $eventType, int $limit = 1000): array
    {
        $sql = 'SELECT seq_no, event_type, actor_seat, payload_json, created_at
                FROM game_events
                WHERE game_id = :game_id AND event_type = :event_type
                ORDER BY seq_no ASC
                LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':game_id', $gameId, \PDO::PARAM_INT);
        $stmt->bindValue(':event_type', $eventType, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $events = $stmt->fetchAll();
        foreach ($events as &$event) {
            $payloadRaw = (string) $event['payload_json'];
            try {
                $decoded = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
                $event['payload'] = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $event['payload'] = [
                    '_decode_error' => true,
                ];
            }
            unset($event['payload_json']);
        }

        return $events;
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

    // -------------------------------------------------------------------------
    // Hand persistence
    // -------------------------------------------------------------------------

    public function createHand(int $gameId, int $handNumber, int $dealerSeat, int $deckSeed, array $kitty): int
    {
        $sql = 'INSERT INTO hands (game_id, hand_number, dealer_seat, deck_seed, kitty_json, phase)
                VALUES (:game_id, :hand_number, :dealer_seat, :deck_seed, :kitty_json, :phase)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id'     => $gameId,
            'hand_number' => $handNumber,
            'dealer_seat' => $dealerSeat,
            'deck_seed'   => $deckSeed,
            'kitty_json'  => json_encode($kitty, JSON_THROW_ON_ERROR),
            'phase'       => 'bidding',
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function findCurrentHand(int $gameId): ?array
    {
        $sql = 'SELECT id, game_id, hand_number, dealer_seat, deck_seed, kitty_json,
                       bid_winner_seat, bid_value, is_30_for_60, trump_suit, phase
                FROM hands
                WHERE game_id = :game_id
                ORDER BY hand_number DESC
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['kitty'] = json_decode((string) $row['kitty_json'], true) ?? [];
        return $row;
    }

    public function setHandBid(int $handId, int $winnerSeat, int $bidValue, bool $is30For60): void
    {
        $sql = 'UPDATE hands SET bid_winner_seat = :seat, bid_value = :bid, is_30_for_60 = :flag WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'seat' => $winnerSeat,
            'bid'  => $bidValue,
            'flag' => $is30For60 ? 1 : 0,
            'id'   => $handId,
        ]);
    }

    public function setHandTrump(int $handId, string $trumpSuit): void
    {
        $sql = 'UPDATE hands SET trump_suit = :trump, phase = :phase WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['trump' => $trumpSuit, 'phase' => 'discard_phase', 'id' => $handId]);
    }

    public function setHandPhase(int $handId, string $phase): void
    {
        $sql = 'UPDATE hands SET phase = :phase WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['phase' => $phase, 'id' => $handId]);
    }

    // -------------------------------------------------------------------------
    // Hand cards
    // -------------------------------------------------------------------------

    public function dealSeatCards(int $handId, int $seat, array $cards): void
    {
        $sql = 'INSERT INTO hand_cards (hand_id, seat, cards_json)
                VALUES (:hand_id, :seat, :cards_json)
                ON DUPLICATE KEY UPDATE cards_json = VALUES(cards_json)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'hand_id'    => $handId,
            'seat'       => $seat,
            'cards_json' => json_encode($cards, JSON_THROW_ON_ERROR),
        ]);
    }

    public function getSeatCards(int $handId, int $seat): ?array
    {
        $sql = 'SELECT cards_json FROM hand_cards WHERE hand_id = :hand_id AND seat = :seat';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hand_id' => $handId, 'seat' => $seat]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return json_decode((string) $row['cards_json'], true) ?? [];
    }

    public function updateSeatCards(int $handId, int $seat, array $cards): void
    {
        $sql = 'UPDATE hand_cards SET cards_json = :cards_json WHERE hand_id = :hand_id AND seat = :seat';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'cards_json' => json_encode($cards, JSON_THROW_ON_ERROR),
            'hand_id'    => $handId,
            'seat'       => $seat,
        ]);
    }

    /** Returns [seat => cards[]] for all four seats */
    public function getAllSeatCards(int $handId): array
    {
        $sql = 'SELECT seat, cards_json FROM hand_cards WHERE hand_id = :hand_id ORDER BY seat ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hand_id' => $handId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['seat']] = json_decode((string) $row['cards_json'], true) ?? [];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Tricks
    // -------------------------------------------------------------------------

    public function createTrick(int $handId, int $trickNumber, int $leadSeat): int
    {
        $sql = 'INSERT INTO tricks (hand_id, trick_number, lead_seat, cards_json)
                VALUES (:hand_id, :trick_number, :lead_seat, :cards_json)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'hand_id'      => $handId,
            'trick_number' => $trickNumber,
            'lead_seat'    => $leadSeat,
            'cards_json'   => json_encode([], JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function findCurrentTrick(int $handId): ?array
    {
        $sql = 'SELECT id, trick_number, lead_seat, winner_seat, cards_json, best_trump_played
                FROM tricks
                WHERE hand_id = :hand_id
                ORDER BY trick_number DESC
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hand_id' => $handId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['cards'] = json_decode((string) $row['cards_json'], true) ?? [];
        return $row;
    }

    public function addCardToTrick(int $trickId, int $seat, string $cardCode): void
    {
        // Fetch current plays, append, write back
        $sql = 'SELECT cards_json FROM tricks WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $trickId]);
        $row = $stmt->fetch();
        $cards = $row ? (json_decode((string) $row['cards_json'], true) ?? []) : [];
        $cards[] = ['seat' => $seat, 'card' => $cardCode];

        $sql2 = 'UPDATE tricks SET cards_json = :cards_json WHERE id = :id';
        $stmt2 = $this->db->pdo()->prepare($sql2);
        $stmt2->execute([
            'cards_json' => json_encode($cards, JSON_THROW_ON_ERROR),
            'id'         => $trickId,
        ]);
    }

    public function closeTrick(int $trickId, int $winnerSeat, ?string $bestTrumpPlayed): void
    {
        $sql = 'UPDATE tricks SET winner_seat = :winner, best_trump_played = :best_trump WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['winner' => $winnerSeat, 'best_trump' => $bestTrumpPlayed, 'id' => $trickId]);
    }

    public function countCompletedTricks(int $handId): int
    {
        $sql = 'SELECT COUNT(*) FROM tricks WHERE hand_id = :hand_id AND winner_seat IS NOT NULL';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hand_id' => $handId]);
        return (int) $stmt->fetchColumn();
    }

    public function listTricks(int $handId): array
    {
        $sql = 'SELECT id, trick_number, lead_seat, winner_seat, cards_json, best_trump_played
                FROM tricks WHERE hand_id = :hand_id ORDER BY trick_number ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['hand_id' => $handId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['cards'] = json_decode((string) $row['cards_json'], true) ?? [];
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Scores
    // -------------------------------------------------------------------------

    public function getRunningScores(int $gameId): array
    {
        $sql = 'SELECT team0_total, team1_total, team0_sets, team1_sets
                FROM scores
                WHERE game_id = :game_id
                ORDER BY id DESC
                LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();
        return $row ?: ['team0_total' => 0, 'team1_total' => 0, 'team0_sets' => 0, 'team1_sets' => 0];
    }

    public function insertScore(
        int $gameId,
        int $handId,
        int $team0Delta,
        int $team1Delta,
        int $team0Total,
        int $team1Total,
        int $team0Sets,
        int $team1Sets
    ): void {
        $sql = 'INSERT INTO scores
                    (game_id, hand_id, team0_delta, team1_delta, team0_total, team1_total, team0_sets, team1_sets)
                VALUES
                    (:game_id, :hand_id, :t0d, :t1d, :t0t, :t1t, :t0s, :t1s)
                ON DUPLICATE KEY UPDATE
                    team0_delta  = VALUES(team0_delta),
                    team1_delta  = VALUES(team1_delta),
                    team0_total  = VALUES(team0_total),
                    team1_total  = VALUES(team1_total),
                    team0_sets   = VALUES(team0_sets),
                    team1_sets   = VALUES(team1_sets)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'game_id' => $gameId,
            'hand_id' => $handId,
            't0d'     => $team0Delta,
            't1d'     => $team1Delta,
            't0t'     => $team0Total,
            't1t'     => $team1Total,
            't0s'     => $team0Sets,
            't1s'     => $team1Sets,
        ]);
    }

    public function setGameStatus(int $gameId, string $status): void
    {
        $sql = 'UPDATE games SET status = :status WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['status' => $status, 'id' => $gameId]);
    }

    public function advanceHand(int $gameId, int $newHandNumber, int $newDealerSeat): void
    {
        $sql = 'UPDATE games SET hand_number = :hand_number, dealer_seat = :dealer_seat WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'hand_number' => $newHandNumber,
            'dealer_seat' => $newDealerSeat,
            'id'          => $gameId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function nextEventSeq(int $gameId): int
    {
        $sql = 'SELECT COALESCE(MAX(seq_no), 0) + 1 AS next_seq FROM game_events WHERE game_id = :game_id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['game_id' => $gameId]);
        $row = $stmt->fetch();

        return (int) ($row['next_seq'] ?? 1);
    }
}
