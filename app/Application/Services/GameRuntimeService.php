<?php

declare(strict_types=1);

namespace FortyFives\Application\Services;

use FortyFives\Application\Contracts\ActionCommand;
use FortyFives\Application\Contracts\ActionResult;
use FortyFives\Infrastructure\Persistence\GameRepository;

final class GameRuntimeService
{
    public function __construct(private readonly GameRepository $games)
    {
    }

    public function handle(ActionCommand $command): ActionResult
    {
        $state = $this->games->findGameState($command->gameId);
        if ($state === null) {
            return ActionResult::rejected('game_not_found', 'Game does not exist.');
        }

        if ((int) ($state['current_turn_seat'] ?? -1) !== $command->seat) {
            return ActionResult::rejected('not_your_turn', 'It is not this seat\'s turn.');
        }

        return match ($command->actionType) {
            'submit_bid' => $this->handleSubmitBid($state, $command),
            'play_card' => $this->handlePlayCard($state, $command),
            default => ActionResult::rejected('unsupported_action', 'Action type is not supported.'),
        };
    }

    private function handleSubmitBid(array $state, ActionCommand $command): ActionResult
    {
        if (($state['current_phase'] ?? null) !== 'bidding') {
            return ActionResult::rejected('wrong_phase', 'Bids are only allowed in bidding phase.');
        }

        $bid = $command->payload['bid'] ?? null;
        $isPass = $bid === 'pass' || $bid === null;
        $allowed = [15, 20, 25, 30, 60];
        if (!$isPass && (!is_int($bid) || !in_array($bid, $allowed, true))) {
            return ActionResult::rejected('invalid_bid', 'Bid must be pass or one of 15,20,25,30,60.');
        }

        $gameId = (int) $state['id'];
        return $this->games->withTransaction(function () use ($gameId, $command, $bid, $isPass): ActionResult {
            $this->games->appendEvent($gameId, 'bid_action', $command->seat, [
                'bid' => $isPass ? 'pass' : $bid,
                'at' => gmdate('c'),
            ]);

            $summary = $this->games->bidSummary($gameId);
            $nextSeat = ($command->seat + 1) % 4;

            if ((int) $summary['count'] >= 4) {
                $winnerSeat = $summary['highest_seat'];
                if ($winnerSeat === null) {
                    $winnerSeat = 0;
                    $this->games->appendEvent($gameId, 'forced_dealer_bid', 0, [
                        'bid' => 15,
                        'reason' => 'all_passed',
                        'at' => gmdate('c'),
                    ]);
                }

                $this->games->setPhaseAndTurn($gameId, 'trick_play', (int) $winnerSeat);
                $this->games->appendEvent($gameId, 'bidding_closed', (int) $winnerSeat, [
                    'winning_bid' => (int) ($summary['highest_bid'] ?: 15),
                    'winning_seat' => (int) $winnerSeat,
                    'at' => gmdate('c'),
                ]);

                return ActionResult::accepted([
                    'phase' => 'trick_play',
                    'current_turn_seat' => (int) $winnerSeat,
                ]);
            }

            $this->games->setTurn($gameId, $nextSeat);
            return ActionResult::accepted([
                'phase' => 'bidding',
                'current_turn_seat' => $nextSeat,
            ]);
        });
    }

    private function handlePlayCard(array $state, ActionCommand $command): ActionResult
    {
        if (($state['current_phase'] ?? null) !== 'trick_play') {
            return ActionResult::rejected('wrong_phase', 'Cards can only be played in trick_play phase.');
        }

        $card = $command->payload['card'] ?? null;
        if (!is_string($card) || trim($card) === '') {
            return ActionResult::rejected('invalid_card', 'Payload must include card code.');
        }

        $gameId = (int) $state['id'];
        return $this->games->withTransaction(function () use ($gameId, $command, $card): ActionResult {
            $this->games->appendEvent($gameId, 'card_played', $command->seat, [
                'card' => $card,
                'at' => gmdate('c'),
            ]);

            $nextSeat = ($command->seat + 1) % 4;
            $this->games->setTurn($gameId, $nextSeat);

            return ActionResult::accepted([
                'phase' => 'trick_play',
                'current_turn_seat' => $nextSeat,
            ]);
        });
    }
}
