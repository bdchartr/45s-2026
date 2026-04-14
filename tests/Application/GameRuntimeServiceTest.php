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
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 12, 'current_turn_seat' => 0, 'current_phase' => 'bidding',
            'dealer_seat' => 0,
        ]);
        $repo->method('bidSummary')->willReturn([
            'count' => 1, 'highest_bid' => 15, 'highest_seat' => 0,
        ]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $repo->expects($this->once())->method('setTurn')->with(12, 1);
        $repo->expects($this->never())->method('setPhaseAndTurn');

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(12, 0, 'submit_bid', ['bid' => 15]));

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
        $hand = [
            'id' => 1, 'hand_number' => 1, 'dealer_seat' => 0, 'kitty' => ['2C', '3D', '4H'],
            'bid_winner_seat' => null, 'bid_value' => null,
        ];
        $repo->method('findCurrentHand')->willReturn($hand);
        $repo->method('getSeatCards')->willReturn(['AC', 'KC', 'QC', 'JC', '10C']);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $repo->expects($this->once())->method('setPhaseAndTurn')->with(13, 'declare_trump', 2);
        $repo->expects($this->never())->method('setTurn');

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
        $repo->method('findCurrentHand')->willReturn(['id' => 5, 'trump_suit' => null]);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $repo->expects($this->once())->method('setHandTrump')->with(5, 'S');
        $repo->expects($this->once())->method('setPhaseAndTurn')->with(32, 'discard_phase', 2);

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(32, 2, 'declare_trump', ['trump' => 'S']));

        $this->assertTrue($result->accepted);
        $this->assertSame('discard_phase', $result->statePatch['phase']);
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
        ]);
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
        ]);
        // Seat 1 has 5 non-trump cards; discarding 0 is valid (keep all 5)
        $repo->method('getSeatCards')->willReturn(['AC', 'KC', 'QC', 'JC', '10C']);
        // Simulate 3 discard_action events already happened (seats 0,2,3), this is seat 1 → 4th
        $trumpEvent = ['seq_no' => 5, 'event_type' => 'trump_declared'];
        $repo->method('latestEventByType')->willReturn($trumpEvent);
        $repo->method('listEventsAfter')->willReturn([
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
            ['event_type' => 'discard_action'],
        ]);
        // 4th discard → should trigger trick play start
        // For trick start we need: bid_winner_seat = 0
        $repo->method('createTrick')->willReturn(1);
        $repo->method('withTransaction')->willReturnCallback(static fn(callable $cb) => $cb());

        $result = (new GameRuntimeService($repo))
            ->handle(new ActionCommand(42, 1, 'discard_cards', ['cards' => []]));

        $this->assertTrue($result->accepted);
        // After 4th discard → trick_play
        $this->assertSame('trick_play', $result->statePatch['phase']);
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
}
