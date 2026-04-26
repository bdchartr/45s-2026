<?php

declare(strict_types=1);

namespace FortyFives\Tests\Infrastructure\Persistence;

use FortyFives\Infrastructure\Persistence\Database;
use FortyFives\Infrastructure\Persistence\GameRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers the per-game display_name column on game_players (used by the
 * AI family-name pool) and the latest-scores LEFT JOIN that
 * listGamesForUser does so the lobby card can show running team totals.
 */
final class GameRepositoryDisplayNameTest extends TestCase
{
    private PDO $pdo;
    private GameRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec('
            CREATE TABLE users (
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
            )
        ');

        $this->pdo->exec('
            CREATE TABLE games (
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

        $this->pdo->exec('
            CREATE TABLE game_players (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER NOT NULL,
                seat INTEGER NOT NULL,
                user_id INTEGER NULL,
                is_ai INTEGER NOT NULL DEFAULT 0,
                team INTEGER NOT NULL DEFAULT 0,
                connected INTEGER NOT NULL DEFAULT 1,
                display_name TEXT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE scores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER NOT NULL,
                hand_id INTEGER NOT NULL,
                team0_total INTEGER NOT NULL DEFAULT 0,
                team1_total INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->repo = new GameRepository(new Database($this->pdo));
    }

    // -------------------------------------------------------------------------
    // addPlayerSeat persists display_name
    // -------------------------------------------------------------------------

    public function testAddPlayerSeatStoresDisplayNameForAi(): void
    {
        $gameId = $this->insertGame();

        $this->repo->addPlayerSeat($gameId, 1, null, true, true, 'Cora');

        $row = $this->pdo->query(
            "SELECT display_name FROM game_players WHERE game_id = $gameId AND seat = 1"
        )->fetch();
        $this->assertSame('Cora', $row['display_name']);
    }

    public function testAddPlayerSeatStoresNullDisplayNameByDefault(): void
    {
        $userId = $this->insertUser('alice');
        $gameId = $this->insertGame();

        $this->repo->addPlayerSeat($gameId, 0, $userId, false);

        $row = $this->pdo->query(
            "SELECT display_name FROM game_players WHERE game_id = $gameId AND seat = 0"
        )->fetch();
        $this->assertNull($row['display_name']);
    }

    // -------------------------------------------------------------------------
    // listPlayers display_name resolution
    // -------------------------------------------------------------------------

    public function testListPlayersReturnsPerGameDisplayNameForAi(): void
    {
        $gameId = $this->insertGame();
        $this->repo->addPlayerSeat($gameId, 1, null, true, true, 'Lucienne');

        $players  = $this->repo->listPlayers($gameId);
        $aiSeat   = $this->seatRow($players, 1);

        $this->assertSame('Lucienne', $aiSeat['display_name']);
    }

    public function testListPlayersFallsBackToNicknameWhenDisplayNameUnset(): void
    {
        $userId = $this->insertUserWithNickname('alice', 'Allie');
        $gameId = $this->insertGame();
        $this->repo->addPlayerSeat($gameId, 0, $userId, false);

        $players = $this->repo->listPlayers($gameId);
        $row     = $this->seatRow($players, 0);

        $this->assertSame('Allie', $row['display_name']);
    }

    public function testListPlayersFallsBackToUsernameWhenNoNickname(): void
    {
        $userId = $this->insertUser('bob');
        $gameId = $this->insertGame();
        $this->repo->addPlayerSeat($gameId, 0, $userId, false);

        $players = $this->repo->listPlayers($gameId);
        $row     = $this->seatRow($players, 0);

        $this->assertSame('bob', $row['display_name']);
    }

    public function testListPlayersDisplayNameOverridesNicknameForHumanSeat(): void
    {
        // Per-game override: human players are normally identified by the
        // user nickname, but the display_name column wins if set. (Used by
        // future "rename me at the table" features.)
        $userId = $this->insertUserWithNickname('bob', 'Robert');
        $gameId = $this->insertGame();
        $this->repo->addPlayerSeat($gameId, 0, $userId, false, true, 'Bobby');

        $players = $this->repo->listPlayers($gameId);
        $row     = $this->seatRow($players, 0);

        $this->assertSame('Bobby', $row['display_name']);
    }

    // -------------------------------------------------------------------------
    // listGamesForUser includes latest-scores totals
    // -------------------------------------------------------------------------

    public function testListGamesForUserReturnsLatestScores(): void
    {
        $userId = $this->insertUser('alice');
        $gameId = $this->insertGame($userId);
        $this->repo->addPlayerSeat($gameId, 0, $userId, false);

        $this->insertScore($gameId, handId: 1, t0: 10, t1: 5);
        $this->insertScore($gameId, handId: 2, t0: 25, t1: 30);

        $games = $this->repo->listGamesForUser($userId);
        $game  = $this->gameRow($games, $gameId);

        $this->assertSame(25, (int) $game['team0_total']);
        $this->assertSame(30, (int) $game['team1_total']);
    }

    public function testListGamesForUserReturnsZeroScoresWhenNoneRecorded(): void
    {
        $userId = $this->insertUser('alice');
        $gameId = $this->insertGame($userId);
        $this->repo->addPlayerSeat($gameId, 0, $userId, false);

        $games = $this->repo->listGamesForUser($userId);
        $game  = $this->gameRow($games, $gameId);

        $this->assertSame(0, (int) $game['team0_total']);
        $this->assertSame(0, (int) $game['team1_total']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function insertUser(string $username): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, role, auth_provider) VALUES (:u, "player", "local")'
        );
        $stmt->execute(['u' => $username]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertUserWithNickname(string $username, string $nickname): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, nickname, role, auth_provider) VALUES (:u, :n, "player", "local")'
        );
        $stmt->execute(['u' => $username, 'n' => $nickname]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertGame(?int $createdBy = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO games (status, created_by_user_id) VALUES ("active", :c)'
        );
        $stmt->execute(['c' => $createdBy]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertScore(int $gameId, int $handId, int $t0, int $t1): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scores (game_id, hand_id, team0_total, team1_total) VALUES (:g, :h, :t0, :t1)'
        );
        $stmt->execute(['g' => $gameId, 'h' => $handId, 't0' => $t0, 't1' => $t1]);
    }

    private function seatRow(array $players, int $seat): array
    {
        foreach ($players as $p) {
            if ((int) $p['seat'] === $seat) {
                return $p;
            }
        }
        $this->fail("No player at seat $seat in result set");
    }

    private function gameRow(array $games, int $gameId): array
    {
        foreach ($games as $g) {
            if ((int) $g['id'] === $gameId) {
                return $g;
            }
        }
        $this->fail("No game with id $gameId in result set");
    }
}
