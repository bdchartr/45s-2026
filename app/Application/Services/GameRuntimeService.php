<?php

declare(strict_types=1);

namespace FortyFives\Application\Services;

use FortyFives\Application\Contracts\ActionCommand;
use FortyFives\Application\Contracts\ActionResult;
use FortyFives\Domain\Game\Card;
use FortyFives\Domain\Rules\CardRanker;
use FortyFives\Domain\Rules\TrickResolver;
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
                $this->games->appendEvent($gameId, 'kitty_picked_up', (int) $winnerSeat, [
                    'winner_seat' => (int) $winnerSeat,
                    'at' => gmdate('c'),
                ]);
                $this->games->appendEvent($gameId, 'discard_completed', (int) $winnerSeat, [
                    'seat' => (int) $winnerSeat,
                    'at' => gmdate('c'),
                ]);
                $this->games->appendEvent($gameId, 'restock_completed', (int) $winnerSeat, [
                    'seat' => (int) $winnerSeat,
                    'target_hand_size' => 5,
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
            $currentHand = $this->reconstructSeatHand($gameId, $command->seat);
            $cardUpper = strtoupper($card);
            $cardIndex = array_search($cardUpper, $currentHand, true);
            if ($cardIndex === false) {
                return ActionResult::rejected('card_not_in_hand', 'Card is not available in this seat hand.');
            }

            unset($currentHand[$cardIndex]);
            $currentHand = array_values($currentHand);

            $this->games->appendEvent($gameId, 'card_played', $command->seat, [
                'card' => $cardUpper,
                'at' => gmdate('c'),
            ]);
            $this->games->appendEvent($gameId, 'hand_updated', $command->seat, [
                'seat' => $command->seat,
                'cards' => $currentHand,
                'reason' => 'play_card',
                'at' => gmdate('c'),
            ]);

            $cardPlayCount = $this->games->countEventsByType($gameId, 'card_played');
            $trickComplete = $cardPlayCount > 0 && ($cardPlayCount % 4) === 0;

            if ($trickComplete) {
                $recentPlays = $this->games->listRecentEventsByType($gameId, 'card_played', 4);
                $plays = [];
                $leadSuit = null;
                $winningCardCode = null;

                foreach ($recentPlays as $idx => $playEvent) {
                    $seat = (int) ($playEvent['actor_seat'] ?? -1);
                    $code = strtoupper(trim((string) (($playEvent['payload']['card'] ?? ''))));
                    $parsed = $this->parseCardCode($code);
                    if ($seat < 0 || $parsed === null) {
                        continue;
                    }
                    if ($idx === 0) {
                        $leadSuit = $parsed->suit;
                    }
                    $plays[$seat] = $parsed;
                }

                if (count($plays) === 4 && $leadSuit !== null) {
                    $trumpSuit = $this->resolveTrumpSuit($gameId, $leadSuit);
                    $resolver = new TrickResolver(new CardRanker());
                    $winnerSeat = $resolver->winningSeat($plays, $leadSuit, $trumpSuit);

                    foreach ($plays as $seat => $playedCard) {
                        if ((int) $seat === (int) $winnerSeat) {
                            $winningCardCode = $playedCard->code();
                            break;
                        }
                    }

                    $this->games->appendEvent($gameId, 'trick_won', $winnerSeat, [
                        'winner_seat' => $winnerSeat,
                        'trick_number' => (int) ($cardPlayCount / 4),
                        'lead_suit' => $leadSuit,
                        'trump_suit' => $trumpSuit,
                        'winning_card' => $winningCardCode,
                        'at' => gmdate('c'),
                    ]);

                    $this->games->setTurn($gameId, $winnerSeat);

                    return ActionResult::accepted([
                        'phase' => 'trick_play',
                        'current_turn_seat' => $winnerSeat,
                    ]);
                }
            }

            $nextSeat = ($command->seat + 1) % 4;
            $this->games->setTurn($gameId, $nextSeat);

            return ActionResult::accepted([
                'phase' => 'trick_play',
                'current_turn_seat' => $nextSeat,
            ]);
        });
    }

    private function resolveTrumpSuit(int $gameId, string $fallbackSuit): string
    {
        $event = $this->games->latestEventByType($gameId, 'bidding_closed');
        if ($event !== null) {
            $payload = (array) ($event['payload'] ?? []);
            $candidate = strtoupper(trim((string) ($payload['trump_suit'] ?? '')));
            if (in_array($candidate, ['C', 'D', 'H', 'S'], true)) {
                return $candidate;
            }
        }

        return in_array($fallbackSuit, ['C', 'D', 'H', 'S'], true) ? $fallbackSuit : 'H';
    }

    private function parseCardCode(string $code): ?Card
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) < 2) {
            return null;
        }

        $suit = substr($code, -1);
        if (!in_array($suit, ['C', 'D', 'H', 'S'], true)) {
            return null;
        }

        $rank = substr($code, 0, -1);
        $allowedRanks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        if (!in_array($rank, $allowedRanks, true)) {
            return null;
        }

        return new Card($suit, $rank);
    }

    private function reconstructSeatHand(int $gameId, int $seat): array
    {
        $deck = $this->buildSeededDeck($gameId);
        $hands = [0 => [], 1 => [], 2 => [], 3 => []];
        for ($round = 0; $round < 5; $round++) {
            for ($s = 0; $s < 4; $s++) {
                $card = array_shift($deck);
                if ($card !== null) {
                    $hands[$s][] = $card;
                }
            }
        }

        $hand = $hands[$seat] ?? [];
        $plays = $this->games->listEventsByType($gameId, 'card_played', 4000);
        foreach ($plays as $play) {
            if ((int) ($play['actor_seat'] ?? -1) !== $seat) {
                continue;
            }
            $playedCard = strtoupper(trim((string) (($play['payload']['card'] ?? ''))));
            $idx = array_search($playedCard, $hand, true);
            if ($idx !== false) {
                unset($hand[$idx]);
                $hand = array_values($hand);
            }
        }

        return $hand;
    }

    private function buildSeededDeck(int $seed): array
    {
        $ranks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        $suits = ['C', 'D', 'H', 'S'];
        $deck = [];
        foreach ($suits as $suit) {
            foreach ($ranks as $rank) {
                $deck[] = $rank . $suit;
            }
        }

        mt_srand($seed);
        shuffle($deck);

        return $deck;
    }
}
