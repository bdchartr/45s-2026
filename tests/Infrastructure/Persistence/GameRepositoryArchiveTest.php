<?php

declare(strict_types=1);

namespace FortyFives\Tests\Infrastructure\Persistence;

use FortyFives\Infrastructure\Persistence\Database;
use FortyFives\Infrastructure\Persistence\GameRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the soft-archive methods and the list-query filters that
 * exclude archived games.
 *
 * Uses an in-memory SQLite database — no server config required.
 */
final class GameRepositoryArchiveTest extends TestCase
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
                connected INTEGER NOT NULL DEFAULT 1
            )
        ');

        $this->repo = new GameRepository(new Database($this->pdo));
    }

    // -------------------------------------------------------------------------
    // archiveGame
    // -------------------------------------------------------------------------

    public function testArchiveGameReturnsTrueForExistingGame(): void
    {
        $id = $this->insertGame();

        $this->assertTrue($this->repo->archiveGame($id));
    }

    public function testArchiveGameSetsArchivedAt(): void
    {
        $id = $this->insertGame();
        $this->repo->archiveGame($id);

        $row = $this->pdo->query("SELECT archived_at FROM games WHERE id = $id")->fetch();
        $this->assertNotNull($row['archived_at']);
    }

    public function testArchiveGameReturnsFalseForNonExistentGame(): void
    {
        $this->assertFalse($this->repo->archiveGame(9999));
    }

    public function testArchiveGameIsIdempotentSecondCallReturnsFalse(): void
    {
        $id = $this->insertGame();
        $this->repo->archiveGame($id);

        $this->assertFalse($this->repo->archiveGame($id));
    }

    // -------------------------------------------------------------------------
    // archiveGameOwnedBy
    // -------------------------------------------------------------------------

    public function testArchiveGameOwnedByReturnsTrueForCreator(): void
    {
        $userId = $this->insertUser('alice');
        $id = $this->insertGame($userId);

        $this->assertTrue($this->repo->archiveGameOwnedBy($id, $userId));
    }

    public function testArchiveGameOwnedByReturnsFalseForWrongUser(): void
    {
        $alice = $this->insertUser('alice');
        $bob   = $this->insertUser('bob');
        $id    = $this->insertGame($alice);

        $this->assertFalse($this->repo->archiveGameOwnedBy($id, $bob));
    }

    public function testArchiveGameOwnedByReturnsFalseForNullCreator(): void
    {
        // Game with no created_by_user_id (e.g. legacy row).
        $id = $this->insertGame(null);

        $this->assertFalse($this->repo->archiveGameOwnedBy($id, 1));
    }

    public function testArchiveGameOwnedByDoesNotArchiveIfAlreadyArchived(): void
    {
        $userId = $this->insertUser('alice');
        $id = $this->insertGame($userId);
        $this->repo->archiveGame($id);

        $this->assertFalse($this->repo->archiveGameOwnedBy($id, $userId));
    }

    // -------------------------------------------------------------------------
    // listGames excludes archived
    // -------------------------------------------------------------------------

    public function testListGamesExcludesArchivedGames(): void
    {
        $visible  = $this->insertGame();
        $archived = $this->insertGame();
        $this->repo->archiveGame($archived);

        $ids = array_map('intval', array_column($this->repo->listGames(), 'id'));

        $this->assertContains($visible, $ids);
        $this->assertNotContains($archived, $ids);
    }

    // -------------------------------------------------------------------------
    // listGamesForUser excludes archived
    // -------------------------------------------------------------------------

    public function testListGamesForUserExcludesArchivedGames(): void
    {
        $userId   = $this->insertUser('alice');
        $visible  = $this->insertGame($userId);
        $archived = $this->insertGame($userId);

        $this->insertGamePlayer($visible,  $userId, seat: 0);
        $this->insertGamePlayer($archived, $userId, seat: 0);

        $this->repo->archiveGame($archived);

        $ids = array_map('intval', array_column($this->repo->listGamesForUser($userId), 'id'));

        $this->assertContains($visible, $ids);
        $this->assertNotContains($archived, $ids);
    }

    // -------------------------------------------------------------------------
    // listJoinableGamesForUser excludes archived
    // -------------------------------------------------------------------------

    public function testListJoinableGamesExcludesArchivedGames(): void
    {
        $userId   = $this->insertUser('alice');

        // Visible game with an open seat.
        $visible = $this->insertGame(null, status: 'lobby');
        $this->insertGamePlayer($visible, null, seat: 0, isAi: false); // open human seat

        // Archived game with an open seat — should not appear.
        $archived = $this->insertGame(null, status: 'lobby');
        $this->insertGamePlayer($archived, null, seat: 0, isAi: false);
        $this->repo->archiveGame($archived);

        $ids = array_map('intval', array_column($this->repo->listJoinableGamesForUser($userId), 'id'));

        $this->assertContains($visible, $ids);
        $this->assertNotContains($archived, $ids);
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

    private function insertGame(?int $createdBy = null, string $status = 'active'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO games (status, created_by_user_id) VALUES (:s, :c)'
        );
        $stmt->execute(['s' => $status, 'c' => $createdBy]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertGamePlayer(int $gameId, ?int $userId, int $seat, bool $isAi = false): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_players (game_id, seat, user_id, is_ai, team) VALUES (:g, :s, :u, :ai, 0)'
        );
        $stmt->execute(['g' => $gameId, 's' => $seat, 'u' => $userId, 'ai' => $isAi ? 1 : 0]);
    }
}
