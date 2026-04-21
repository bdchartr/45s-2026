<?php

declare(strict_types=1);

namespace FortyFives\Tests\Infrastructure\Persistence;

use FortyFives\Infrastructure\Persistence\Database;
use FortyFives\Infrastructure\Persistence\GameRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for user-profile repository methods:
 * findUserById, updateAvatar, updateNickname, updatePassword, statsForUser.
 *
 * Uses an in-memory SQLite database — no server config required.
 */
final class GameRepositoryPlayerTest extends TestCase
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
    // findUserById
    // -------------------------------------------------------------------------

    public function testFindUserByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repo->findUserById(9999));
    }

    public function testFindUserByIdReturnsUserWithProfileFields(): void
    {
        $id = $this->insertUser('alice', 'alice@example.com');

        $user = $this->repo->findUserById($id);

        $this->assertNotNull($user);
        $this->assertSame('alice', $user['username']);
        $this->assertSame('alice@example.com', $user['email']);
        $this->assertArrayHasKey('avatar_code', $user);
        $this->assertArrayHasKey('nickname', $user);
        $this->assertNull($user['avatar_code']);
        $this->assertNull($user['nickname']);
    }

    // -------------------------------------------------------------------------
    // updateAvatar
    // -------------------------------------------------------------------------

    public function testUpdateAvatarPersistsAvatarCode(): void
    {
        $id = $this->insertUser('alice');

        $this->repo->updateAvatar($id, 's-teal');

        $row = $this->pdo->query("SELECT avatar_code FROM users WHERE id = $id")->fetch();
        $this->assertSame('s-teal', $row['avatar_code']);
    }

    public function testUpdateAvatarOverwritesPreviousCode(): void
    {
        $id = $this->insertUser('alice');
        $this->repo->updateAvatar($id, 's-teal');
        $this->repo->updateAvatar($id, 'h-rose');

        $row = $this->pdo->query("SELECT avatar_code FROM users WHERE id = $id")->fetch();
        $this->assertSame('h-rose', $row['avatar_code']);
    }

    // -------------------------------------------------------------------------
    // updateNickname
    // -------------------------------------------------------------------------

    public function testUpdateNicknamePersistsNickname(): void
    {
        $id = $this->insertUser('alice');

        $this->repo->updateNickname($id, 'Bob');

        $row = $this->pdo->query("SELECT nickname FROM users WHERE id = $id")->fetch();
        $this->assertSame('Bob', $row['nickname']);
    }

    public function testUpdateNicknameWithNullClearsNickname(): void
    {
        $id = $this->insertUser('alice');
        $this->repo->updateNickname($id, 'Bob');

        $this->repo->updateNickname($id, null);

        $row = $this->pdo->query("SELECT nickname FROM users WHERE id = $id")->fetch();
        $this->assertNull($row['nickname']);
    }

    // -------------------------------------------------------------------------
    // updatePassword
    // -------------------------------------------------------------------------

    public function testUpdatePasswordSetsNewHash(): void
    {
        $id = $this->insertUser('alice');
        $newHash = password_hash('newpass123', PASSWORD_DEFAULT);

        $this->repo->updatePassword($id, $newHash);

        $row = $this->pdo->query("SELECT password_hash FROM users WHERE id = $id")->fetch();
        $this->assertTrue(password_verify('newpass123', (string) $row['password_hash']));
    }

    // -------------------------------------------------------------------------
    // statsForUser — zero-game baseline
    // -------------------------------------------------------------------------

    public function testStatsForUserWithNoGamesReturnsZeros(): void
    {
        $id = $this->insertUser('alice');

        $stats = $this->repo->statsForUser($id);

        $this->assertSame(0, $stats['games_played']);
        $this->assertSame(0, $stats['games_finished']);
        $this->assertSame(0, $stats['wins']);
        $this->assertSame(0.0, $stats['win_pct']);
        $this->assertSame([], $stats['partners']);
        $this->assertSame([], $stats['opponents']);
        $this->assertSame([], $stats['monthly']);
    }

    // -------------------------------------------------------------------------
    // statsForUser — with finished games
    // -------------------------------------------------------------------------

    public function testStatsForUserCountsFinishedGamesAndWins(): void
    {
        $alice = $this->insertUser('alice');
        $bob   = $this->insertUser('bob');

        // Game 1: Alice wins (team 0 hits target 120)
        $g1 = $this->insertFinishedGame('2025-10');
        $this->insertGamePlayer($g1, $alice, team: 0);
        $this->insertGamePlayer($g1, $bob,   team: 1);
        $this->insertScore($g1, team0: 125, team1: 85);

        // Game 2: Alice loses (team 0 below target, team 1 higher)
        $g2 = $this->insertFinishedGame('2025-11');
        $this->insertGamePlayer($g2, $alice, team: 0);
        $this->insertGamePlayer($g2, $bob,   team: 1);
        $this->insertScore($g2, team0: 80, team1: 125);

        $stats = $this->repo->statsForUser($alice);

        $this->assertSame(2, $stats['games_played']);
        $this->assertSame(2, $stats['games_finished']);
        $this->assertSame(1, $stats['wins']);
        $this->assertSame(50.0, $stats['win_pct']);
    }

    public function testStatsForUserBuildsPartnerAndOpponentTables(): void
    {
        $alice = $this->insertUser('alice');
        $bob   = $this->insertUser('bob');
        $carol = $this->insertUser('carol');

        // Bob is Alice's partner in one finished game, Carol is opponent.
        $g1 = $this->insertFinishedGame('2025-12');
        $this->insertGamePlayer($g1, $alice, team: 0, seat: 0);
        $this->insertGamePlayer($g1, $bob,   team: 0, seat: 2); // same team → partner
        $this->insertGamePlayer($g1, $carol, team: 1, seat: 1); // diff team → opponent
        $this->insertScore($g1, team0: 125, team1: 85);

        $stats = $this->repo->statsForUser($alice);

        $partnerUserIds  = array_column($stats['partners'],  'user_id');
        $opponentUserIds = array_column($stats['opponents'], 'user_id');

        $this->assertContains($bob,   $partnerUserIds);
        $this->assertContains($carol, $opponentUserIds);
        $this->assertNotContains($carol, $partnerUserIds);
        $this->assertNotContains($bob,   $opponentUserIds);
    }

    public function testStatsForUserBuildsMonthlyHistory(): void
    {
        $alice = $this->insertUser('alice');
        $bob   = $this->insertUser('bob');

        $g1 = $this->insertFinishedGame('2025-10');
        $this->insertGamePlayer($g1, $alice, team: 0);
        $this->insertGamePlayer($g1, $bob,   team: 1);
        $this->insertScore($g1, team0: 125, team1: 85);

        $g2 = $this->insertFinishedGame('2025-11');
        $this->insertGamePlayer($g2, $alice, team: 0);
        $this->insertGamePlayer($g2, $bob,   team: 1);
        $this->insertScore($g2, team0: 80, team1: 125);

        $stats = $this->repo->statsForUser($alice);

        $months = array_column($stats['monthly'], 'month');
        $this->assertContains('2025-10', $months);
        $this->assertContains('2025-11', $months);

        $oct = array_values(array_filter($stats['monthly'], fn($m) => $m['month'] === '2025-10'))[0];
        $this->assertSame(1, $oct['games']);
        $this->assertSame(1, $oct['wins']);
        $this->assertSame(100.0, $oct['win_pct']);
    }

    public function testStatsForUserExcludesArchivedGames(): void
    {
        $alice = $this->insertUser('alice');
        $bob   = $this->insertUser('bob');

        $g1 = $this->insertFinishedGame('2025-10');
        $this->insertGamePlayer($g1, $alice, team: 0);
        $this->insertGamePlayer($g1, $bob,   team: 1);
        $this->insertScore($g1, team0: 125, team1: 85);

        // Archive the game — should not count.
        $this->pdo->exec("UPDATE games SET archived_at = CURRENT_TIMESTAMP WHERE id = $g1");

        $stats = $this->repo->statsForUser($alice);

        $this->assertSame(0, $stats['games_played']);
        $this->assertSame(0, $stats['games_finished']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function insertUser(string $username, string $email = 'test@example.com'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, email, role, auth_provider) VALUES (:u, :e, "player", "local")'
        );
        $stmt->execute(['u' => $username, 'e' => $email]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertFinishedGame(string $ym): int
    {
        $updatedAt = $ym . '-15 12:00:00';
        $stmt = $this->pdo->prepare(
            'INSERT INTO games (status, target_score, updated_at) VALUES ("finished", 120, :ua)'
        );
        $stmt->execute(['ua' => $updatedAt]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertGamePlayer(int $gameId, int $userId, int $team = 0, int $seat = 0): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_players (game_id, seat, user_id, is_ai, team) VALUES (:g, :s, :u, 0, :t)'
        );
        $stmt->execute(['g' => $gameId, 's' => $seat, 'u' => $userId, 't' => $team]);
    }

    private function insertScore(int $gameId, int $team0, int $team1): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scores (game_id, hand_id, team0_total, team1_total) VALUES (:g, 1, :t0, :t1)'
        );
        $stmt->execute(['g' => $gameId, 't0' => $team0, 't1' => $team1]);
    }
}
