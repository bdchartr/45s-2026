<?php

declare(strict_types=1);

namespace FortyFives\Tests\Application;

use FortyFives\Application\Contracts\ActionCommand;
use FortyFives\Application\Services\GameRuntimeService;
use FortyFives\Infrastructure\Persistence\GameRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GameRuntimeService.
 *
 * These tests stub GameRepository so no database is required.
 * They verify phase gating, action routing, event emission, and turn advancement.
 */
final class GameRuntimeServiceTest extends TestCase
{
    // =========================================================================
    // dealNewHand — bidding order
    // =========================================================================

    public function testDealNewHandSetsBiddingTurnToLeftOfDealer(): void
    {
        // Dealer is seat 2; the player to the left (seat 3) must open bidding.
        $dealerSeat  = 2;
        $playerCount = 4;
        $firstBidder = ($dealerSeat + 1) % $playerCount; // 3

        $repo = $this->createMock(GameRepository::class);
        $repo->method('getPlayerCount')->willReturn($playerCount);
        $repo->method('createHand')->willReturn(99);
        $repo->method('appendEvent')->willReturn(1);

        $repo->expects($this->once())
            ->method('setPhaseAndTurn')
            ->with(10, 'bidding', $firstBidder);

        (new GameRuntimeService($repo))->dealNewHand(10, 1, $dealerSeat);
    }

    public function testDealNewHandFirstBidderWrapsAroundWhenDealerIsLastSeat(): void
    {
        // Dealer is seat 3 (last seat in 4-player); first bidder must be seat 0.
        $dealerSeat  = 3;
        $playerCount = 4;
        $firstBidder = ($dealerSeat + 1) % $playerCount; // 0

        $repo = $this->createMock(GameRepository::class);
        $repo->method('getPlayerCount')->willReturn($playerCount);
        $repo->method('createHand')->willReturn(99);
        $repo->method('appendEvent')->willReturn(1);

        $repo->expects($this->once())
            ->method('setPhaseAndTurn')
            ->with(10, 'bidding', $firstBidder);

        (new GameRuntimeService($repo))->dealNewHand(10, 2, $dealerSeat);
    }

    // =========================================================================
    // Guard checks
    // =========================================================================

    public function testRejectsWhenGameDoesNotExist(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn(null);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(999, 0, 'submit_bid', ['bid' => 15]));

        $this->assertFalse($result->accepted);
        $this->assertSame('game_not_found', $result->code);
    }

    public function testRejectsWhenNotPlayersTurn(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 10, 'current_turn_seat' => 2, 'current_phase' => 'bidding',
            'dealer_seat' => 0,
        ]);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(10, 1, 'submit_bid', ['bid' => 15]));

        $this->assertFalse($result->accepted);
        $this->assertSame('not_your_turn', $result->code);
    }

    public function testRejectsInvalidBidValue(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 11, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 0,
        ]);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(11, 0, 'submit_bid', ['bid' => 17]));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_bid', $result->code);
    }

    public function testRejectsUnsupportedAction(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 20, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 0,
        ]);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(20, 0, 'teleport', []));

        $this->assertFalse($result->accepted);
        $this->assertSame('unsupported_action', $result->code);
    }

    // =========================================================================
    // Bidding phase
    // =========================================================================

    public function testSubmitBidAdvancesTurnAfterPartialBidding(): void
    {
        // Non-dealer (seat 0) bids 20 above the current high of 15 — turn advances to seat 1.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 12, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 1, 'highest_bid' => 15, 'highest_seat' => 2,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $repo->expects($this->once())->method('setTurn')->with(12, 1);
        $repo->expects($this->never())->method('setPhaseAndTurn');

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(12, 0, 'submit_bid', ['bid' => 20]));

        $this->assertTrue($result->accepted);
        $this->assertSame('bidding', $result->statePatch['phase']);
        $this->assertSame(1, $result->statePatch['current_turn_seat']);
    }

    public function testBiddingClosesAfterFourthBidAndTransitsToDeclare(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 13, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 0,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 4, 'highest_bid' => 25, 'highest_seat' => 2,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);
        $hand = [
            'id' => 1, 'hand_number' => 1, 'dealer_seat' => 0, 'kitty' => ['2C', '3D', '4H'],
            'bid_winner_seat' => null, 'bid_value' => null,
        ];
        $repo->method('findCurrentHand')->willReturn($hand);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $repo->expects($this->once())->method('setPhaseAndTurn')->with(13, 'declare_trump', 2);
        $repo->expects($this->never())->method('setTurn');
        // Kitty must NOT be merged here — only after trump is declared
        $repo->expects($this->never())->method('updateSeatCards');

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(13, 3, 'submit_bid', ['bid' => 'pass']));

        $this->assertTrue($result->accepted);
        $this->assertSame('declare_trump', $result->statePatch['phase']);
        $this->assertSame(2, $result->statePatch['current_turn_seat']);
    }

    public function testForcedDealerBidWhenAllPass(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 14, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 4, 'highest_bid' => 0, 'highest_seat' => null,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);
        $hand = [
            'id' => 1, 'hand_number' => 1, 'dealer_seat' => 3, 'kitty' => ['2C', '3D', '4H'],
            'bid_winner_seat' => null, 'bid_value' => null,
        ];
        $repo->method('findCurrentHand')->willReturn($hand);
        $repo->method('getSeatCards')->willReturn(['AC', 'KC', 'QC', 'JC', '10C']);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        // Forced dealer bid event should be emitted
        $appendEventCalls = [];
        $repo->method('appendEvent')
            ->willReturnCallback(function (int $gid, string $type, $actor, array $payload) use (&$appendEventCalls): int {
                $appendEventCalls[] = $type;
                return 1;
            });

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(14, 3, 'submit_bid', ['bid' => 'pass']));

        $this->assertTrue($result->accepted);
        $this->assertContains('forced_dealer_bid', $appendEventCalls);
        $this->assertSame('declare_trump', $result->statePatch['phase']);
        // Dealer seat (3) is forced bidder
        $this->assertSame(3, $result->statePatch['current_turn_seat']);
    }

    public function testDealerNumericBidIsRejected(): void
    {
        // Dealer is seat 3. Numeric bids are not allowed on the dealer's initial turn —
        // the dealer must reject or concede.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 15, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 3, 'highest_bid' => 25, 'highest_seat' => 1,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(15, 3, 'submit_bid', ['bid' => 25]));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_action', $result->code);
    }

    public function testNonDealerBidEqualToCurrentHighIsRejected(): void
    {
        // Seat 1 already bid 20. Seat 2 (non-dealer) tries to bid 20 — not allowed.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 16, 'current_turn_seat' => 2, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 1, 'highest_bid' => 20, 'highest_seat' => 1,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(16, 2, 'submit_bid', ['bid' => 20]));

        $this->assertFalse($result->accepted);
        $this->assertSame('bid_too_low', $result->code);
    }

    public function testNonDealerBidBelowCurrentHighIsRejected(): void
    {
        // Seat 1 bid 25. Seat 2 tries to bid 20 — must be higher than 25.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 17, 'current_turn_seat' => 2, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 1, 'highest_bid' => 25, 'highest_seat' => 1,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(17, 2, 'submit_bid', ['bid' => 20]));

        $this->assertFalse($result->accepted);
        $this->assertSame('bid_too_low', $result->code);
    }

    public function testDealerBidBelowCurrentHighIsRejected(): void
    {
        // Seat 1 bid 25. Dealer (seat 3) tries to bid 20 — numeric bids not allowed for dealer.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 18, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 3, 'highest_bid' => 25, 'highest_seat' => 1,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(18, 3, 'submit_bid', ['bid' => 20]));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_action', $result->code);
    }

    // =========================================================================
    // Trump declaration phase
    // =========================================================================

    public function testDeclareTrumpRejectsWrongPhase(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 30, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 0,
        ]);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(30, 0, 'declare_trump', ['trump' => 'H']));

        $this->assertFalse($result->accepted);
        $this->assertSame('wrong_phase', $result->code);
    }

    public function testDeclareTrumpRejectsInvalidSuit(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 31, 'current_turn_seat' => 0, 'current_phase' => 'declare_trump',
            'dealer_seat' => 0,
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());
        $repo->method('findCurrentHand')->willReturn(['id' => 1]);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(31, 0, 'declare_trump', ['trump' => 'X']));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_trump', $result->code);
    }

    public function testDeclareTrumpTransitionsToDiscardPhase(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 32, 'current_turn_seat' => 2, 'current_phase' => 'declare_trump',
            'dealer_seat' => 0,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 5, 'trump_suit' => null,
            'bid_winner_seat' => 2, 'kitty' => ['2C', '3D', '4H'],
        ]);
        $repo->method('getSeatCards')->willReturn(['AC', 'KC', 'QC', 'JC', '10C']);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $appendedTypes = [];
        $repo->method('appendEvent')
            ->willReturnCallback(function (int $gid, string $type) use (&$appendedTypes): int {
                $appendedTypes[] = $type;
                return 1;
            });

        $repo->expects($this->once())->method('setHandTrump')->with(5, 'S');
        $repo->expects($this->once())->method('setPhaseAndTurn')->with(32, 'discard_phase', 2);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(32, 2, 'declare_trump', ['trump' => 'S']));

        $this->assertTrue($result->accepted);
        $this->assertSame('discard_phase', $result->statePatch['phase']);
        $this->assertContains('kitty_picked_up', $appendedTypes);
    }

    // =========================================================================
    // Discard phase
    // =========================================================================

    public function testDiscardRejectsWrongPhase(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 40, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 0,
        ]);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(40, 0, 'discard_cards', ['cards' => ['2C']]));

        $this->assertFalse($result->accepted);
        $this->assertSame('wrong_phase', $result->code);
    }

    public function testDiscardRejectsCardNotInHand(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 41, 'current_turn_seat' => 0, 'current_phase' => 'discard_phase',
            'dealer_seat' => 0,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 1, 'trump_suit' => 'H', 'bid_winner_seat' => 0, 'bid_value' => 20,
            'dealer_seat' => 0, 'dealer_extra_draw_pending' => 0,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('getSeatCards')->willReturn(['AC', 'KC', 'QC', 'JC', '10C']);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(41, 0, 'discard_cards', ['cards' => ['2H']]));

        $this->assertFalse($result->accepted);
        $this->assertSame('card_not_in_hand', $result->code);
    }

    public function testDiscardAcceptsValidDiscard(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 42, 'current_turn_seat' => 1, 'current_phase' => 'discard_phase',
            'dealer_seat' => 0,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 2, 'trump_suit' => 'H', 'bid_winner_seat' => 0, 'bid_value' => 20,
            'dealer_seat' => 0, 'dealer_extra_draw_pending' => 0,
        ]);
        // Seat 1 has 5 non-trump cards; discarding 0 is valid (keep all 5)
        $repo->method('getSeatCards')->willReturn(['AC', 'KC', 'QC', 'JC', '10C']);
        $repo->method('getPlayerCount')->willReturn(4);
        // Simulate 3 discard_action events already happened (seats 0,2,3), this is seat 1 → 4th
        $trumpEvent = ['seq_no' => 5, 'event_type' => 'trump_declared'];
        $repo->method('latestEventByType')->willReturn($trumpEvent);
        $repo->method('listEventsAfter')->willReturn([
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
        ]);
        // 4th discard → should trigger trick play start
        $repo->method('createTrick')->willReturn(1);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(42, 1, 'discard_cards', ['cards' => []]));

        $this->assertTrue($result->accepted);
        // After 4th discard → trick_play
        $this->assertSame('trick_play', $result->statePatch['phase']);
    }

    public function testDiscardDrawsReplacementCardsFromDeck(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 43, 'current_turn_seat' => 1, 'current_phase' => 'discard_phase',
            'dealer_seat' => 0,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 3, 'trump_suit' => 'S', 'bid_winner_seat' => 0, 'bid_value' => 20,
            'dealer_seat' => 0, 'dealer_extra_draw_pending' => 0,
        ]);
        // Seat 1 discards 2 cards; should draw 2 from deck
        $repo->method('getSeatCards')->willReturn(['2C', '3C', '4C', '5D', '6D']);
        $repo->method('getDeckRemaining')->willReturn(['7H', '8H', '9H', '10H', 'JD']);
        $repo->method('getPlayerCount')->willReturn(4);
        $trumpEvent = ['seq_no' => 5, 'event_type' => 'trump_declared'];
        $repo->method('latestEventByType')->willReturn($trumpEvent);
        // Only 1 prior discard; 2nd after this one leaves 2 remaining → stay in discard_phase
        $repo->method('listEventsAfter')->willReturn([
            ['event_type' => 'discard_action'],
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        // Capture the cards saved back so we can assert the draws were added
        $savedCards = null;
        $repo->method('updateSeatCards')->willReturnCallback(
            static function (int $handId, int $seat, array $cards) use (&$savedCards): void {
                $savedCards = $cards;
            }
        );

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(43, 1, 'discard_cards', ['cards' => ['5D', '6D']]));

        $this->assertTrue($result->accepted);
        $this->assertNotNull($savedCards);
        $this->assertCount(5, $savedCards);
        // Kept 3 original cards + 2 drawn from deck
        $this->assertContains('2C', $savedCards);
        $this->assertContains('3C', $savedCards);
        $this->assertContains('4C', $savedCards);
        $this->assertContains('7H', $savedCards);
        $this->assertContains('8H', $savedCards);
    }

    // =========================================================================
    // 6-player discard rules
    // =========================================================================

    public function testSixPlayerDiscardRejectsTooManyCards(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 70, 'current_turn_seat' => 2, 'current_phase' => 'discard_phase',
            'dealer_seat' => 5,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 10, 'trump_suit' => 'H', 'bid_winner_seat' => 0, 'bid_value' => 20,
            'dealer_seat' => 5, 'dealer_extra_draw_pending' => 0,
        ]);
        $repo->method('getPlayerCount')->willReturn(6);
        $repo->method('getSeatCards')->willReturn(['2C', '3C', '4C', '5D', '6D']);
        $repo->method('latestEventByType')->willReturn(null);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        // Attempting to discard 4 cards in a 6-player game
        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(70, 2, 'discard_cards', ['cards' => ['2C', '3C', '4C', '5D']]));

        $this->assertFalse($result->accepted);
        $this->assertSame('too_many_discards', $result->code);
    }

    public function testSixPlayerDiscardAcceptsUpToThreeCards(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 71, 'current_turn_seat' => 2, 'current_phase' => 'discard_phase',
            'dealer_seat' => 5,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 11, 'trump_suit' => 'H', 'bid_winner_seat' => 0, 'bid_value' => 20,
            'dealer_seat' => 5, 'dealer_extra_draw_pending' => 0,
        ]);
        $repo->method('getPlayerCount')->willReturn(6);
        $repo->method('getSeatCards')->willReturn(['2C', '3C', '4C', '5D', '6D']);
        $repo->method('getDeckRemaining')->willReturn(['7H', '8H', '9H', '10H', 'JD', 'QD', 'KD', 'AD']);
        $repo->method('latestEventByType')->willReturn(null);
        // Only 3 prior discards of 6; still more players to go
        $repo->method('listEventsAfter')->willReturn([
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(71, 2, 'discard_cards', ['cards' => ['5D', '6D', '2C']]));

        $this->assertTrue($result->accepted);
        $this->assertSame('discard_phase', $result->statePatch['phase']);
    }

    public function testSixPlayerAfterAllDiscardsGivesDealerExtraCards(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 72, 'current_turn_seat' => 4, 'current_phase' => 'discard_phase',
            'dealer_seat' => 5,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 12, 'trump_suit' => 'H', 'bid_winner_seat' => 0, 'bid_value' => 20,
            'dealer_seat' => 5, 'dealer_extra_draw_pending' => 0,
        ]);
        $repo->method('getPlayerCount')->willReturn(6);
        // Seat 4 discards 0 — this is the 6th (final) discard
        $repo->method('getSeatCards')->willReturnOnConsecutiveCalls(
            ['2C', '3C', '4C', '5D', '6D'], // seat 4's hand
            ['7H', '8H', '9H', '10H', 'JD']  // dealer's (seat 5) current hand
        );
        // 3 leftover cards in deck after all draws
        $repo->method('getDeckRemaining')->willReturn(['AC', 'KC', 'QC']);
        $trumpEvent = ['seq_no' => 5, 'event_type' => 'trump_declared'];
        $repo->method('latestEventByType')->willReturn($trumpEvent);
        // After seat 4's discard is appended, listEventsAfter returns 6 total
        $repo->method('listEventsAfter')->willReturn([
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        // Capture the dealer's updated hand
        $dealerCards = null;
        $repo->method('updateSeatCards')->willReturnCallback(
            static function (int $handId, int $seat, array $cards) use (&$dealerCards): void {
                if ($seat === 5) {
                    $dealerCards = $cards;
                }
            }
        );

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(72, 4, 'discard_cards', ['cards' => []]));

        $this->assertTrue($result->accepted);
        // Phase stays discard_phase, turn advances to dealer (seat 5)
        $this->assertSame('discard_phase', $result->statePatch['phase']);
        $this->assertSame(5, $result->statePatch['current_turn_seat']);
        // Dealer received the 3 extra deck cards merged into their hand (5 + 3 = 8)
        $this->assertNotNull($dealerCards);
        $this->assertCount(8, $dealerCards);
    }

    public function testSixPlayerDealerExtraDrawTransitionsToTrickPlay(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 73, 'current_turn_seat' => 5, 'current_phase' => 'discard_phase',
            'dealer_seat' => 5,
        ]);
        // dealer_extra_draw_pending = 1 signals it's the dealer's final discard step
        $repo->method('findCurrentHand')->willReturn([
            'id' => 13, 'trump_suit' => 'H', 'bid_winner_seat' => 0, 'bid_value' => 20,
            'dealer_seat' => 5, 'dealer_extra_draw_pending' => 1,
        ]);
        $repo->method('getPlayerCount')->willReturn(6);
        // Dealer has 8 cards (5 original + 3 extra from deck); discards 3 to reach 5
        $repo->method('getSeatCards')->willReturn(['7H', '8H', '9H', '10H', 'JD', 'AC', 'KC', 'QC']);
        $repo->method('createTrick')->willReturn(1);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(73, 5, 'discard_cards', ['cards' => ['AC', 'KC', 'QC']]));

        $this->assertTrue($result->accepted);
        $this->assertSame('trick_play', $result->statePatch['phase']);
    }

    public function testSixPlayerDealerExtraDrawRejectsWrongSeat(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 74, 'current_turn_seat' => 4, 'current_phase' => 'discard_phase',
            'dealer_seat' => 5,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 14, 'trump_suit' => 'H', 'bid_winner_seat' => 0, 'bid_value' => 20,
            'dealer_seat' => 5, 'dealer_extra_draw_pending' => 1,
        ]);
        $repo->method('getPlayerCount')->willReturn(6);
        $repo->method('getSeatCards')->willReturn(['7H', '8H', '9H', '10H', 'JD', 'AC', 'KC', 'QC']);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        // Seat 4 tries to discard during dealer extra-draw — not allowed
        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(74, 4, 'discard_cards', ['cards' => []]));

        $this->assertFalse($result->accepted);
        $this->assertSame('not_your_turn', $result->code);
    }

    // =========================================================================
    // Play card phase
    // =========================================================================

    public function testPlayCardRejectsWrongPhase(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 50, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 0,
        ]);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(50, 0, 'play_card', ['card' => 'AS']));

        $this->assertFalse($result->accepted);
        $this->assertSame('wrong_phase', $result->code);
    }

    public function testPlayCardRejectsInvalidCardCode(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 51, 'current_turn_seat' => 0, 'current_phase' => 'trick_play',
            'dealer_seat' => 0,
        ]);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(51, 0, 'play_card', ['card' => 'BADCARD']));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_card', $result->code);
    }

    public function testPlayCardRejectsCardNotInHand(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 52, 'current_turn_seat' => 0, 'current_phase' => 'trick_play',
            'dealer_seat' => 0,
        ]);
        $repo->method('findCurrentHand')->willReturn(['id' => 3, 'trump_suit' => 'H']);
        $repo->method('getSeatCards')->willReturn(['2C', '3C', '4C', '5C', '6C']);
        $repo->method('findCurrentTrick')->willReturn(['id' => 1, 'trick_number' => 1, 'cards' => []]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(52, 0, 'play_card', ['card' => 'AS']));

        $this->assertFalse($result->accepted);
        $this->assertSame('card_not_in_hand', $result->code);
    }

    public function testPlayCardAdvancesTurnToNextSeat(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 53, 'current_turn_seat' => 1, 'current_phase' => 'trick_play',
            'dealer_seat' => 0,
        ]);
        $repo->method('findCurrentHand')->willReturn(['id' => 3, 'trump_suit' => 'H']);
        $repo->method('getSeatCards')->willReturn(['AS', 'KC', 'QC', 'JC', '10C']);
        $repo->method('findCurrentTrick')->willReturn(['id' => 1, 'trick_number' => 1, 'cards' => []]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $repo->expects($this->once())->method('setTurn')->with(53, 2);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(53, 1, 'play_card', ['card' => 'AS']));

        $this->assertTrue($result->accepted);
        $this->assertSame('trick_play', $result->statePatch['phase']);
        $this->assertSame(2, $result->statePatch['current_turn_seat']);
    }

    public function testPlayCardCompletesAndEmitsTrickWonOnFourthCard(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 54, 'current_turn_seat' => 3, 'current_phase' => 'trick_play',
            'dealer_seat' => 0,
        ]);
        $repo->method('findCurrentHand')->willReturn(['id' => 4, 'trump_suit' => 'H']);
        $repo->method('getSeatCards')->willReturn(['KH', '2C', '3C', '4C', '5C']);
        // 3 cards already played in the trick; seat 3 plays 4th
        $repo->method('findCurrentTrick')->willReturn([
            'id'           => 10,
            'trick_number' => 1,
            'lead_seat'    => 0,
            'cards'        => [
                ['seat' => 0, 'card' => '2S'],
                ['seat' => 1, 'card' => '3S'],
                ['seat' => 2, 'card' => '4S'],
            ],
        ]);
        $repo->method('countCompletedTricks')->willReturn(1); // 1 trick done, not 5 yet
        $repo->method('createTrick')->willReturn(11);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $emittedEvents = [];
        $repo->method('appendEvent')
            ->willReturnCallback(function ($gid, string $type) use (&$emittedEvents): int {
                $emittedEvents[] = $type;
                return 1;
            });

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(54, 3, 'play_card', ['card' => 'KH']));

        $this->assertTrue($result->accepted);
        $this->assertContains('trick_won', $emittedEvents);
        // KH is trump (trump=H), so seat 3 wins
        $this->assertSame(3, $result->statePatch['current_turn_seat']);
    }

    public function testPlayCardIllegalMoveIsRejected(): void
    {
        // Trump = H; Clubs led; seat 0 has clubs but tries to play a spade
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 55, 'current_turn_seat' => 0, 'current_phase' => 'trick_play',
            'dealer_seat' => 0,
        ]);
        $repo->method('findCurrentHand')->willReturn(['id' => 5, 'trump_suit' => 'H']);
        $repo->method('getSeatCards')->willReturn(['2C', '3C', '4S', '5S', '6S']); // has clubs
        $repo->method('findCurrentTrick')->willReturn([
            'id'           => 20,
            'trick_number' => 1,
            'lead_seat'    => 1,
            'cards'        => [['seat' => 1, 'card' => 'KC']], // clubs led
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(55, 0, 'play_card', ['card' => '4S']));

        $this->assertFalse($result->accepted);
        $this->assertSame('illegal_move', $result->code);
    }

    // =========================================================================
    // Lead rule: player to the LEFT of bid winner leads trick 1
    // =========================================================================

    public function testFirstTrickIsLedByPlayerLeftOfBidWinner(): void
    {
        // Bid winner is seat 2; in a 4-player game seat 3 leads the first trick
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 80, 'current_turn_seat' => 2, 'current_phase' => 'discard_phase',
            'dealer_seat' => 1,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 20, 'trump_suit' => 'S', 'bid_winner_seat' => 2, 'bid_value' => 15,
            'dealer_seat' => 1, 'dealer_extra_draw_pending' => 0,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('getSeatCards')->willReturn(['2C', '3C', '4C', '5C', '6C']);
        $trumpEvent = ['seq_no' => 10, 'event_type' => 'trump_declared'];
        $repo->method('latestEventByType')->willReturn($trumpEvent);
        // 4 discard_action events already in → this is the 4th, triggers trick_play
        $repo->method('listEventsAfter')->willReturn([
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
        ]);
        $repo->method('createTrick')->willReturn(1);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(80, 2, 'discard_cards', ['cards' => []]));

        $this->assertTrue($result->accepted);
        $this->assertSame('trick_play', $result->statePatch['phase']);
        // Seat 3 = (2 + 1) % 4
        $this->assertSame(3, $result->statePatch['current_turn_seat']);
    }

    public function testFirstTrickWrapAroundLeadSeat(): void
    {
        // Bid winner is seat 3 (last seat); seat 0 leads (wraps around)
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 81, 'current_turn_seat' => 3, 'current_phase' => 'discard_phase',
            'dealer_seat' => 2,
        ]);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 21, 'trump_suit' => 'H', 'bid_winner_seat' => 3, 'bid_value' => 20,
            'dealer_seat' => 2, 'dealer_extra_draw_pending' => 0,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('getSeatCards')->willReturn(['2C', '3C', '4C', '5C', '6C']);
        $trumpEvent = ['seq_no' => 10, 'event_type' => 'trump_declared'];
        $repo->method('latestEventByType')->willReturn($trumpEvent);
        $repo->method('listEventsAfter')->willReturn([
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
        ]);
        $repo->method('createTrick')->willReturn(1);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(81, 3, 'discard_cards', ['cards' => []]));

        $this->assertTrue($result->accepted);
        $this->assertSame('trick_play', $result->statePatch['phase']);
        // Seat 0 = (3 + 1) % 4
        $this->assertSame(0, $result->statePatch['current_turn_seat']);
    }

    // =========================================================================
    // Dealer reject mechanic
    // =========================================================================

    public function testDealerCanRejectCurrentBid(): void
    {
        // Dealer (seat 3) rejects the standing high bid of 15 from seat 0.
        // After reject: turn goes to high bidder (seat 0), reject loop begins.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 90, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        // Pre-validation call: no reject loop yet, current high is 15 from seat 0
        // Post-append call: reject loop is now active
        $repo->method('bidSummary')->willReturnOnConsecutiveCalls(
            ['count' => 3, 'highest_bid' => 15, 'highest_seat' => 0, 'in_reject_loop' => false],
            ['count' => 4, 'highest_bid' => 15, 'highest_seat' => 0, 'in_reject_loop' => true, 'last_bid' => 'reject', 'last_bid_seat' => 3]
        );
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(90, 3, 'submit_bid', ['bid' => 'reject']));

        $this->assertTrue($result->accepted);
        $this->assertSame('bidding', $result->statePatch['phase']);
        // Turn goes to the high bidder (seat 0)
        $this->assertSame(0, $result->statePatch['current_turn_seat']);
    }

    public function testNonDealerCannotReject(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 91, 'current_turn_seat' => 2, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 2, 'highest_bid' => 15, 'highest_seat' => 0, 'in_reject_loop' => false,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(91, 2, 'submit_bid', ['bid' => 'reject']));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_action', $result->code);
    }

    public function testDealerCannotRejectWhenNoBid(): void
    {
        // All previous seats passed — dealer can't reject what doesn't exist.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 92, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 3, 'highest_bid' => 0, 'highest_seat' => null, 'in_reject_loop' => false,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(92, 3, 'submit_bid', ['bid' => 'reject']));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_action', $result->code);
    }

    public function testDealerCannotReject30For60(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 93, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 3, 'highest_bid' => 60, 'highest_seat' => 0, 'in_reject_loop' => false,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(93, 3, 'submit_bid', ['bid' => 'reject']));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_action', $result->code);
    }

    public function testBidderRaisesInRejectLoop(): void
    {
        // In reject loop: seat 0 (high bidder) raises from 15 to 20.
        // Turn should go back to dealer (seat 3).
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 94, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturnOnConsecutiveCalls(
            ['count' => 4, 'highest_bid' => 15, 'highest_seat' => 0, 'in_reject_loop' => true],
            ['count' => 5, 'highest_bid' => 20, 'highest_seat' => 0, 'in_reject_loop' => true]
        );
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(94, 0, 'submit_bid', ['bid' => 20]));

        $this->assertTrue($result->accepted);
        $this->assertSame('bidding', $result->statePatch['phase']);
        // Turn returns to dealer
        $this->assertSame(3, $result->statePatch['current_turn_seat']);
    }

    public function testDealerConcedesToBidderInRejectLoop(): void
    {
        // Dealer (seat 3) passes in reject loop → bidder (seat 0) declares trump.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 95, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturnOnConsecutiveCalls(
            ['count' => 4, 'highest_bid' => 20, 'highest_seat' => 0, 'in_reject_loop' => true],
            ['count' => 5, 'highest_bid' => 20, 'highest_seat' => 0, 'in_reject_loop' => true]
        );
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 30, 'bid_winner_seat' => null, 'bid_value' => null, 'kitty' => [],
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(95, 3, 'submit_bid', ['bid' => 'pass']));

        $this->assertTrue($result->accepted);
        $this->assertSame('declare_trump', $result->statePatch['phase']);
        // Bidder (seat 0) declares trump — dealer conceded
        $this->assertSame(0, $result->statePatch['current_turn_seat']);
    }

    public function testBidderConcedesToDealerInRejectLoop(): void
    {
        // High bidder (seat 0) passes in reject loop → dealer (seat 3) declares trump.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 96, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturnOnConsecutiveCalls(
            ['count' => 4, 'highest_bid' => 15, 'highest_seat' => 0, 'in_reject_loop' => true],
            ['count' => 5, 'highest_bid' => 15, 'highest_seat' => 0, 'in_reject_loop' => true]
        );
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 31, 'bid_winner_seat' => null, 'bid_value' => null, 'kitty' => [],
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(96, 0, 'submit_bid', ['bid' => 'pass']));

        $this->assertTrue($result->accepted);
        $this->assertSame('declare_trump', $result->statePatch['phase']);
        // Dealer (seat 3) declares trump — bidder conceded
        $this->assertSame(3, $result->statePatch['current_turn_seat']);
    }

    public function testBidder30For60ClosesRejectLoopImmediately(): void
    {
        // High bidder raises to 60 in reject loop → bidding closes, no further dealer action.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 97, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturnOnConsecutiveCalls(
            ['count' => 5, 'highest_bid' => 25, 'highest_seat' => 0, 'in_reject_loop' => true],
            ['count' => 6, 'highest_bid' => 60, 'highest_seat' => 0, 'in_reject_loop' => true]
        );
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('findCurrentHand')->willReturn([
            'id' => 32, 'bid_winner_seat' => null, 'bid_value' => null, 'kitty' => [],
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(97, 0, 'submit_bid', ['bid' => 60]));

        $this->assertTrue($result->accepted);
        $this->assertSame('declare_trump', $result->statePatch['phase']);
        // Bidder (seat 0) wins and declares trump
        $this->assertSame(0, $result->statePatch['current_turn_seat']);
    }

    public function testDealerRejectsAgainInRejectLoop(): void
    {
        // Dealer (seat 3) rejects a second time after bidder raised to 20.
        // Turn goes back to high bidder (seat 0).
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 98, 'current_turn_seat' => 3, 'current_phase' => 'bidding',
            'dealer_seat' => 3,
        ]);
        $repo->method('bidSummary')->willReturnOnConsecutiveCalls(
            ['count' => 5, 'highest_bid' => 20, 'highest_seat' => 0, 'in_reject_loop' => true],
            ['count' => 6, 'highest_bid' => 20, 'highest_seat' => 0, 'in_reject_loop' => true]
        );
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(98, 3, 'submit_bid', ['bid' => 'reject']));

        $this->assertTrue($result->accepted);
        $this->assertSame('bidding', $result->statePatch['phase']);
        // Turn returns to high bidder (seat 0)
        $this->assertSame(0, $result->statePatch['current_turn_seat']);
    }

    // =========================================================================
    // 30-for-60 hand scoring
    // =========================================================================

    public function testScoreHand30For60MadeWhenAllTricksAndHighTrump(): void
    {
        // Team 0 (seats 0,2) wins all 5 tricks; 5H (5 of Hearts) is the best trump played.
        // Scoring: 5 tricks × 5 = 25 pts + 5 high-trump bonus = 30 pts.
        // bid_value=60 is the reward, NOT the threshold; threshold is 30, so bid is made → +60.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturnOnConsecutiveCalls(
            ['id' => 200, 'current_turn_seat' => 3, 'current_phase' => 'trick_play', 'dealer_seat' => 0],
            ['target_score' => 120]
        );
        $repo->method('findCurrentHand')->willReturn([
            'id'              => 50,
            'hand_number'     => 1,
            'dealer_seat'     => 0,
            'trump_suit'      => 'H',
            'bid_winner_seat' => 0,
            'bid_value'       => 60,
            'is_30_for_60'    => 1,
        ]);
        $repo->method('getSeatCards')->willReturn(['4C', '5C', '6C', '7C', '8C']);
        $repo->method('findCurrentTrick')->willReturn([
            'id'                => 55,
            'trick_number'      => 5,
            'lead_seat'         => 0,
            'best_trump_played' => null,
            'cards'             => [
                ['seat' => 0, 'card' => 'AC'],
                ['seat' => 1, 'card' => '2C'],
                ['seat' => 2, 'card' => '3C'],
            ],
        ]);
        $repo->method('countCompletedTricks')->willReturn(5);
        $repo->method('listTricks')->willReturn([
            ['winner_seat' => 0, 'best_trump_played' => '5H', 'cards' => []],
            ['winner_seat' => 2, 'best_trump_played' => null,  'cards' => []],
            ['winner_seat' => 0, 'best_trump_played' => null,  'cards' => []],
            ['winner_seat' => 2, 'best_trump_played' => null,  'cards' => []],
            ['winner_seat' => 0, 'best_trump_played' => null,  'cards' => []],
        ]);
        $repo->method('getRunningScores')->willReturn([
            'team0_total' => 0, 'team1_total' => 0, 'team0_sets' => 0, 'team1_sets' => 0,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('createHand')->willReturn(51);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $handScoredPayload = null;
        $repo->method('appendEvent')
            ->willReturnCallback(
                function (int $gid, string $type, ?int $seat, array $payload) use (&$handScoredPayload): int {
                    if ($type === 'hand_scored') {
                        $handScoredPayload = $payload;
                    }
                    return 1;
                }
            );

        (new GameRuntimeService($repo))->handle(new ActionCommand(200, 3, 'play_card', ['card' => '4C']));

        $this->assertNotNull($handScoredPayload, 'hand_scored event must be emitted');
        $this->assertTrue($handScoredPayload['bid_made'], '30-for-60 bid must be made with 30 pts (25 tricks + 5 high trump)');
        $this->assertSame(60,  $handScoredPayload['team0_delta'], 'bid team earns +60 on success');
        $this->assertSame(0,   $handScoredPayload['team1_delta']);
        $this->assertSame(0,   $handScoredPayload['team0_sets']);
    }

    public function testScoreHand30For60SetWhenNoHighTrumpAndOnlyTwentyFivePoints(): void
    {
        // Team 0 wins all 5 tricks but no trump is played in any trick.
        // Score = 25 pts (tricks only) < 30 required → bid set, team loses 60.
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturnOnConsecutiveCalls(
            ['id' => 201, 'current_turn_seat' => 3, 'current_phase' => 'trick_play', 'dealer_seat' => 0],
            ['target_score' => 120]
        );
        $repo->method('findCurrentHand')->willReturn([
            'id'              => 60,
            'hand_number'     => 1,
            'dealer_seat'     => 0,
            'trump_suit'      => 'H',
            'bid_winner_seat' => 0,
            'bid_value'       => 60,
            'is_30_for_60'    => 1,
        ]);
        $repo->method('getSeatCards')->willReturn(['4C', '5C', '6C', '7C', '8C']);
        $repo->method('findCurrentTrick')->willReturn([
            'id'                => 65,
            'trick_number'      => 5,
            'lead_seat'         => 0,
            'best_trump_played' => null,
            'cards'             => [
                ['seat' => 0, 'card' => 'AC'],
                ['seat' => 1, 'card' => '2C'],
                ['seat' => 2, 'card' => '3C'],
            ],
        ]);
        $repo->method('countCompletedTricks')->willReturn(5);
        $repo->method('listTricks')->willReturn([
            ['winner_seat' => 0, 'best_trump_played' => null, 'cards' => []],
            ['winner_seat' => 2, 'best_trump_played' => null, 'cards' => []],
            ['winner_seat' => 0, 'best_trump_played' => null, 'cards' => []],
            ['winner_seat' => 2, 'best_trump_played' => null, 'cards' => []],
            ['winner_seat' => 0, 'best_trump_played' => null, 'cards' => []],
        ]);
        $repo->method('getRunningScores')->willReturn([
            'team0_total' => 0, 'team1_total' => 0, 'team0_sets' => 0, 'team1_sets' => 0,
        ]);
        $repo->method('getPlayerCount')->willReturn(4);
        $repo->method('createHand')->willReturn(61);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $handScoredPayload = null;
        $repo->method('appendEvent')
            ->willReturnCallback(
                function (int $gid, string $type, ?int $seat, array $payload) use (&$handScoredPayload): int {
                    if ($type === 'hand_scored') {
                        $handScoredPayload = $payload;
                    }
                    return 1;
                }
            );

        (new GameRuntimeService($repo))->handle(new ActionCommand(201, 3, 'play_card', ['card' => '4C']));

        $this->assertNotNull($handScoredPayload, 'hand_scored event must be emitted');
        $this->assertFalse($handScoredPayload['bid_made'], '30-for-60 bid must fail with only 25 pts (no high trump)');
        $this->assertSame(-60, $handScoredPayload['team0_delta'], 'bid team loses 60 on set');
        $this->assertSame(0,   $handScoredPayload['team1_delta']);
        $this->assertSame(1,   $handScoredPayload['team0_sets']);
    }
}
