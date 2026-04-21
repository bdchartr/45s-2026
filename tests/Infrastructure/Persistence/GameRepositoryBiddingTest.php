<?php

declare(strict_types=1);

namespace FortyFives\Tests\Infrastructure\Persistence;

use FortyFives\Infrastructure\Persistence\Database;
use FortyFives\Infrastructure\Persistence\GameRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GameRepository::bidSummary().
 *
 * Verifies that the summary correctly identifies the highest bidder,
 * handles the dealer-steal (last equal bid wins), and ignores bids from
 * a previous hand.
 *
 * Uses an in-memory SQLite database — no server config required.
 */
final class GameRepositoryBiddingTest extends TestCase
{
    private PDO $pdo;
    private GameRepository $repo;
    private int $gameId;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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
            CREATE TABLE game_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id INTEGER NOT NULL,
                seq_no INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                actor_seat INTEGER NULL,
                payload_json TEXT NOT NULL DEFAULT "{}",
                created_at TEXT NULL
            )
        ');

        $this->pdo->exec('INSERT INTO games (status) VALUES ("active")');
        $this->gameId = (int) $this->pdo->lastInsertId();

        $this->repo = new GameRepository(new Database($this->pdo));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function appendEvent(string $type, int $seat, array $payload): void
    {
        $maxSeq = (int) $this->pdo->query("SELECT COALESCE(MAX(seq_no),0) FROM game_events WHERE game_id={$this->gameId}")->fetchColumn();
        $stmt   = $this->pdo->prepare(
            'INSERT INTO game_events (game_id, seq_no, event_type, actor_seat, payload_json)
             VALUES (:g, :s, :t, :a, :p)'
        );
        $stmt->execute([
            'g' => $this->gameId,
            's' => $maxSeq + 1,
            't' => $type,
            'a' => $seat,
            'p' => json_encode($payload),
        ]);
    }

    private function deal(): void
    {
        $this->appendEvent('hand_dealt', 0, []);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function testBidSummaryIsEmptyWithNoBids(): void
    {
        $this->deal();

        $summary = $this->repo->bidSummary($this->gameId);

        $this->assertSame(0, $summary['count']);
        $this->assertSame(0, $summary['highest_bid']);
        $this->assertNull($summary['highest_seat']);
    }

    public function testBidSummaryIdentifiesHighestBidder(): void
    {
        $this->deal();
        $this->appendEvent('bid_action', 1, ['bid' => 20]);
        $this->appendEvent('bid_action', 2, ['bid' => 'pass']);
        $this->appendEvent('bid_action', 3, ['bid' => 15]);

        $summary = $this->repo->bidSummary($this->gameId);

        $this->assertSame(3, $summary['count']);
        $this->assertSame(20, $summary['highest_bid']);
        $this->assertSame(1, $summary['highest_seat']);
    }

    public function testBidSummaryDealerStealLastEqualBidWins(): void
    {
        // Seat 1 bids 20; dealer (seat 3) matches 20 → dealer steals.
        $this->deal();
        $this->appendEvent('bid_action', 0, ['bid' => 'pass']);
        $this->appendEvent('bid_action', 1, ['bid' => 20]);
        $this->appendEvent('bid_action', 2, ['bid' => 'pass']);
        $this->appendEvent('bid_action', 3, ['bid' => 20]);  // dealer steal

        $summary = $this->repo->bidSummary($this->gameId);

        $this->assertSame(4, $summary['count']);
        $this->assertSame(20, $summary['highest_bid']);
        $this->assertSame(3, $summary['highest_seat'], 'Dealer should win when matching the current high');
    }

    public function testBidSummaryHigherBidBeatsEarlierEqual(): void
    {
        // Seat 2 outbids seat 1 cleanly.
        $this->deal();
        $this->appendEvent('bid_action', 0, ['bid' => 'pass']);
        $this->appendEvent('bid_action', 1, ['bid' => 20]);
        $this->appendEvent('bid_action', 2, ['bid' => 25]);
        $this->appendEvent('bid_action', 3, ['bid' => 'pass']);

        $summary = $this->repo->bidSummary($this->gameId);

        $this->assertSame(25, $summary['highest_bid']);
        $this->assertSame(2, $summary['highest_seat']);
    }

    public function testBidSummaryIgnoresBidsFromPreviousHand(): void
    {
        // Hand 1: seat 0 bids 25.
        $this->deal();
        $this->appendEvent('bid_action', 0, ['bid' => 25]);
        $this->appendEvent('bidding_closed', 0, []);

        // Hand 2: new deal; seat 1 bids 15. Seat 0's bid from hand 1 must not count.
        $this->deal();
        $this->appendEvent('bid_action', 1, ['bid' => 15]);

        $summary = $this->repo->bidSummary($this->gameId);

        $this->assertSame(1, $summary['count'], 'Only the current hand bids should be counted');
        $this->assertSame(15, $summary['highest_bid']);
        $this->assertSame(1, $summary['highest_seat']);
    }

    public function testBidSummaryAllPassReturnsNullSeat(): void
    {
        $this->deal();
        $this->appendEvent('bid_action', 0, ['bid' => 'pass']);
        $this->appendEvent('bid_action', 1, ['bid' => 'pass']);
        $this->appendEvent('bid_action', 2, ['bid' => 'pass']);
        $this->appendEvent('bid_action', 3, ['bid' => 'pass']);

        $summary = $this->repo->bidSummary($this->gameId);

        $this->assertSame(4, $summary['count']);
        $this->assertSame(0, $summary['highest_bid']);
        $this->assertNull($summary['highest_seat']);
    }
}
