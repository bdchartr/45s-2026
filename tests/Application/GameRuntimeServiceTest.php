<?php

declare(strict_types=1);

namespace FortyFives\Tests\Application;

use FortyFives\Application\Contracts\ActionCommand;
use FortyFives\Application\Services\GameRuntimeService;
use FortyFives\Infrastructure\Persistence\GameRepository;
use PHPUnit\Framework\TestCase;

final class GameRuntimeServiceTest extends TestCase
{
    public function testRejectsWhenGameDoesNotExist(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->expects($this->once())
            ->method('findGameState')
            ->with(999)
            ->willReturn(null);

        $service = new GameRuntimeService($repo);
        $result = $service->handle(new ActionCommand(999, 0, 'submit_bid', ['bid' => 15]));

        $this->assertFalse($result->accepted);
        $this->assertSame('game_not_found', $result->code);
    }

    public function testRejectsWhenNotPlayersTurn(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 10,
            'current_turn_seat' => 2,
            'current_phase' => 'bidding',
        ]);

        $service = new GameRuntimeService($repo);
        $result = $service->handle(new ActionCommand(10, 1, 'submit_bid', ['bid' => 15]));

        $this->assertFalse($result->accepted);
        $this->assertSame('not_your_turn', $result->code);
    }

    public function testRejectsInvalidBidValue(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 11,
            'current_turn_seat' => 0,
            'current_phase' => 'bidding',
        ]);

        $service = new GameRuntimeService($repo);
        $result = $service->handle(new ActionCommand(11, 0, 'submit_bid', ['bid' => 17]));

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_bid', $result->code);
    }

    public function testSubmitBidAdvancesTurnDuringBidding(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 12,
            'current_turn_seat' => 0,
            'current_phase' => 'bidding',
        ]);

        $repo->expects($this->once())
            ->method('appendEvent')
            ->with(12, 'bid_action', 0, $this->arrayHasKey('bid'));

        $repo->expects($this->once())
            ->method('bidSummary')
            ->with(12)
            ->willReturn([
                'count' => 1,
                'highest_bid' => 15,
                'highest_seat' => 0,
            ]);

        $repo->expects($this->once())
            ->method('setTurn')
            ->with(12, 1);

        $repo->expects($this->never())->method('setPhaseAndTurn');

        $repo->method('withTransaction')->willReturnCallback(static fn (callable $cb) => $cb());

        $service = new GameRuntimeService($repo);
        $result = $service->handle(new ActionCommand(12, 0, 'submit_bid', ['bid' => 15]));

        $this->assertTrue($result->accepted);
        $this->assertSame('bidding', $result->statePatch['phase']);
        $this->assertSame(1, $result->statePatch['current_turn_seat']);
    }

    public function testSubmitBidClosesBiddingAfterFourthAction(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 13,
            'current_turn_seat' => 3,
            'current_phase' => 'bidding',
        ]);

        $repo->expects($this->exactly(5))
            ->method('appendEvent')
            ->withConsecutive(
                [13, 'bid_action', 3, $this->arrayHasKey('bid')],
                [13, 'bidding_closed', 2, $this->arrayHasKey('winning_bid')],
                [13, 'kitty_picked_up', 2, $this->arrayHasKey('winner_seat')],
                [13, 'discard_completed', 2, $this->arrayHasKey('seat')],
                [13, 'restock_completed', 2, $this->arrayHasKey('target_hand_size')]
            );

        $repo->expects($this->once())
            ->method('bidSummary')
            ->willReturn([
                'count' => 4,
                'highest_bid' => 25,
                'highest_seat' => 2,
            ]);

        $repo->expects($this->once())
            ->method('setPhaseAndTurn')
            ->with(13, 'trick_play', 2);

        $repo->expects($this->never())->method('setTurn');
        $repo->method('withTransaction')->willReturnCallback(static fn (callable $cb) => $cb());

        $service = new GameRuntimeService($repo);
        $result = $service->handle(new ActionCommand(13, 3, 'submit_bid', ['bid' => 'pass']));

        $this->assertTrue($result->accepted);
        $this->assertSame('trick_play', $result->statePatch['phase']);
        $this->assertSame(2, $result->statePatch['current_turn_seat']);
    }

    public function testPlayCardAdvancesTurnInTrickPlay(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 14,
            'current_turn_seat' => 1,
            'current_phase' => 'trick_play',
        ]);

        $repo->expects($this->once())
            ->method('appendEvent')
            ->with(14, 'card_played', 1, $this->arrayHasKey('card'));

        $repo->expects($this->once())
            ->method('countEventsByType')
            ->with(14, 'card_played')
            ->willReturn(1);

        $repo->expects($this->once())
            ->method('setTurn')
            ->with(14, 2);

        $repo->method('withTransaction')->willReturnCallback(static fn (callable $cb) => $cb());

        $service = new GameRuntimeService($repo);
        $result = $service->handle(new ActionCommand(14, 1, 'play_card', ['card' => 'AS']));

        $this->assertTrue($result->accepted);
        $this->assertSame('trick_play', $result->statePatch['phase']);
        $this->assertSame(2, $result->statePatch['current_turn_seat']);
    }

    public function testPlayCardEmitsTrickWonOnFourthCardAndSetsWinnerTurn(): void
    {
        $repo = $this->createMock(GameRepository::class);
        $repo->method('findGameState')->willReturn([
            'id' => 15,
            'current_turn_seat' => 3,
            'current_phase' => 'trick_play',
        ]);

        $repo->expects($this->exactly(2))
            ->method('appendEvent')
            ->withConsecutive(
                [15, 'card_played', 3, $this->arrayHasKey('card')],
                [15, 'trick_won', 0, $this->arrayHasKey('winner_seat')]
            );

        $repo->expects($this->once())
            ->method('countEventsByType')
            ->with(15, 'card_played')
            ->willReturn(4);

        $repo->expects($this->once())
            ->method('listRecentEventsByType')
            ->with(15, 'card_played', 4)
            ->willReturn([
                ['seq_no' => 11, 'event_type' => 'card_played', 'actor_seat' => 0, 'payload' => ['card' => 'AS'], 'created_at' => '2026-01-01 00:00:00'],
                ['seq_no' => 12, 'event_type' => 'card_played', 'actor_seat' => 1, 'payload' => ['card' => 'KH'], 'created_at' => '2026-01-01 00:00:01'],
                ['seq_no' => 13, 'event_type' => 'card_played', 'actor_seat' => 2, 'payload' => ['card' => '2H'], 'created_at' => '2026-01-01 00:00:02'],
                ['seq_no' => 14, 'event_type' => 'card_played', 'actor_seat' => 3, 'payload' => ['card' => 'QH'], 'created_at' => '2026-01-01 00:00:03'],
            ]);

        $repo->expects($this->once())
            ->method('latestEventByType')
            ->with(15, 'bidding_closed')
            ->willReturn(null);

        $repo->expects($this->once())
            ->method('setTurn')
            ->with(15, 0);

        $repo->method('withTransaction')->willReturnCallback(static fn (callable $cb) => $cb());

        $service = new GameRuntimeService($repo);
        $result = $service->handle(new ActionCommand(15, 3, 'play_card', ['card' => 'QH']));

        $this->assertTrue($result->accepted);
        $this->assertSame('trick_play', $result->statePatch['phase']);
        $this->assertSame(0, $result->statePatch['current_turn_seat']);
    }
}
