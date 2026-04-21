<?php

declare(strict_types=1);

namespace FortyFives\Application\Services;

use FortyFives\Application\Contracts\ActionCommand;
use FortyFives\Application\Contracts\ActionResult;
use FortyFives\Domain\AI\AIRequest;
use FortyFives\Domain\AI\MoveProviderInterface;
use FortyFives\Domain\Game\Card;
use FortyFives\Domain\Rules\CardRanker;
use FortyFives\Domain\Rules\LegalMoveValidator;
use FortyFives\Domain\Rules\TrickResolver;
use FortyFives\Infrastructure\Persistence\GameRepository;

/**
 * Orchestrates the full 45s game lifecycle:
 *
 *   bidding → declare_trump → discard_phase → trick_play → score_hand → (next hand | game_over)
 *
 * All authoritative card state is persisted to the hands/hand_cards/tricks/scores tables.
 * The event log (game_events) remains the canonical audit trail consumed by the frontend.
 */
final class GameRuntimeService
{
    private readonly CardRanker $ranker;
    private readonly TrickResolver $resolver;
    private readonly LegalMoveValidator $validator;

    public function __construct(private readonly GameRepository $games)
    {
        $this->ranker    = new CardRanker();
        $this->resolver  = new TrickResolver($this->ranker);
        $this->validator = new LegalMoveValidator($this->ranker);
    }

    public function handle(ActionCommand $command): ActionResult
    {
        $state = $this->games->findGameState($command->gameId);
        if ($state === null) {
            return ActionResult::rejected('game_not_found', 'Game does not exist.');
        }

        // Discard phase allows any seated player to submit without strict turn order.
        // The discard handler itself enforces phase, duplicate-discard prevention, and
        // turn gating for the 6-player dealer extra-draw step.
        $bypassTurnCheck =
            $command->actionType === 'discard_cards'
            && ($state['current_phase'] ?? null) === 'discard_phase';

        if (!$bypassTurnCheck && (int) ($state['current_turn_seat'] ?? -1) !== $command->seat) {
            return ActionResult::rejected('not_your_turn', 'It is not this seat\'s turn.');
        }

        return match ($command->actionType) {
            'submit_bid'    => $this->handleSubmitBid($state, $command),
            'declare_trump' => $this->handleDeclareTrump($state, $command),
            'discard_cards' => $this->handleDiscardCards($state, $command),
            'play_card'     => $this->handlePlayCard($state, $command),
            default         => ActionResult::rejected('unsupported_action', 'Action type not supported.'),
        };
    }

    // =========================================================================
    // Phase: bidding
    // =========================================================================

    private function handleSubmitBid(array $state, ActionCommand $command): ActionResult
    {
        if (($state['current_phase'] ?? null) !== 'bidding') {
            return ActionResult::rejected('wrong_phase', 'Bids are only allowed in bidding phase.');
        }

        $bid    = $command->payload['bid'] ?? null;
        $isPass = $bid === 'pass' || $bid === null;
        // 60 represents "30-for-60" bid
        $allowed = [15, 20, 25, 30, 60];
        if (!$isPass && (!is_int($bid) || !in_array($bid, $allowed, true))) {
            return ActionResult::rejected('invalid_bid', 'Bid must be pass or one of 15,20,25,30,60.');
        }

        $gameId      = (int) $state['id'];
        $dealerSeat  = (int) ($state['dealer_seat'] ?? 0);
        $isDealer    = $command->seat === $dealerSeat;
        $playerCount = $this->games->getPlayerCount($gameId);

        // Validate bid amount against the current standing high bid.
        // Non-dealer must strictly exceed it; dealer may match to steal.
        if (!$isPass) {
            $preSummary  = $this->games->bidSummary($gameId);
            $currentHigh = (int) ($preSummary['highest_bid'] ?? 0);
            if ($currentHigh > 0) {
                if ($isDealer && $bid < $currentHigh) {
                    return ActionResult::rejected(
                        'bid_too_low',
                        'Dealer must match or exceed the current bid of ' . $currentHigh . ' to steal it.'
                    );
                }
                if (!$isDealer && $bid <= $currentHigh) {
                    return ActionResult::rejected(
                        'bid_too_low',
                        'Bid must exceed the current highest bid of ' . $currentHigh . '.'
                    );
                }
            }
        }

        return $this->games->withTransaction(function () use ($gameId, $command, $bid, $isPass, $state, $dealerSeat, $playerCount): ActionResult {
            $this->games->appendEvent($gameId, 'bid_action', $command->seat, [
                'bid' => $isPass ? 'pass' : $bid,
                'at'  => gmdate('c'),
            ]);

            $summary  = $this->games->bidSummary($gameId);
            $nextSeat = ($command->seat + 1) % $playerCount;

            if ((int) $summary['count'] >= $playerCount) {
                return $this->closeBidding($gameId, $state, $summary, $dealerSeat);
            }

            $this->games->setTurn($gameId, $nextSeat);
            return ActionResult::accepted(['phase' => 'bidding', 'current_turn_seat' => $nextSeat]);
        });
    }

    private function closeBidding(int $gameId, array $state, array $summary, int $dealerSeat): ActionResult
    {
        $winnerSeat = $summary['highest_seat'];
        $winningBid = (int) ($summary['highest_bid'] ?: 0);
        $is30For60  = $winningBid === 60;

        if ($winnerSeat === null) {
            // All passed — dealer forced to bid 15
            $winnerSeat = $dealerSeat;
            $winningBid = 15;
            $this->games->appendEvent($gameId, 'forced_dealer_bid', $dealerSeat, [
                'bid'    => 15,
                'reason' => 'all_passed',
                'at'     => gmdate('c'),
            ]);
        }

        // Persist bid to the hands record
        $hand = $this->games->findCurrentHand($gameId);
        if ($hand !== null) {
            $this->games->setHandBid((int) $hand['id'], $winnerSeat, $winningBid, $is30For60);
        }

        $this->games->appendEvent($gameId, 'bidding_closed', (int) $winnerSeat, [
            'winning_bid'  => $winningBid,
            'winning_seat' => (int) $winnerSeat,
            'is_30_for_60' => $is30For60,
            'at'           => gmdate('c'),
        ]);

        // Kitty is NOT given to the winner yet — they declare trump first, then receive
        // the kitty face-up (only visible to them).  See handleDeclareTrump().

        // Transition to declare_trump — only the bid winner acts
        $this->games->setPhaseAndTurn($gameId, 'declare_trump', (int) $winnerSeat);

        return ActionResult::accepted([
            'phase'             => 'declare_trump',
            'current_turn_seat' => (int) $winnerSeat,
        ]);
    }

    // =========================================================================
    // Phase: declare_trump
    // =========================================================================

    private function handleDeclareTrump(array $state, ActionCommand $command): ActionResult
    {
        if (($state['current_phase'] ?? null) !== 'declare_trump') {
            return ActionResult::rejected('wrong_phase', 'Trump can only be declared in declare_trump phase.');
        }

        $trump = strtoupper(trim((string) ($command->payload['trump'] ?? '')));
        if (!in_array($trump, ['C', 'D', 'H', 'S'], true)) {
            return ActionResult::rejected('invalid_trump', 'Trump must be C, D, H, or S.');
        }

        $gameId = (int) $state['id'];
        return $this->games->withTransaction(function () use ($gameId, $command, $trump): ActionResult {
            $hand = $this->games->findCurrentHand($gameId);
            if ($hand === null) {
                return ActionResult::rejected('no_hand', 'No active hand found.');
            }

            $this->games->setHandTrump((int) $hand['id'], $trump);

            $this->games->appendEvent($gameId, 'trump_declared', $command->seat, [
                'trump_suit' => $trump,
                'at'         => gmdate('c'),
            ]);

            // Now give the kitty to the bid winner (face-up, only visible to them).
            $bidWinnerSeat = isset($hand['bid_winner_seat']) ? (int) $hand['bid_winner_seat'] : null;
            if ($bidWinnerSeat !== null && !empty($hand['kitty'])) {
                $kitty = is_string($hand['kitty']) ? json_decode($hand['kitty'], true) : $hand['kitty'];
                if (!empty($kitty)) {
                    $this->games->appendEvent($gameId, 'kitty_picked_up', $bidWinnerSeat, [
                        'winner_seat' => $bidWinnerSeat,
                        'kitty'       => $kitty,
                        'at'          => gmdate('c'),
                    ]);
                    $currentCards = $this->games->getSeatCards((int) $hand['id'], $bidWinnerSeat) ?? [];
                    $merged = array_values(array_unique(array_merge($currentCards, $kitty)));
                    $this->games->updateSeatCards((int) $hand['id'], $bidWinnerSeat, $merged);
                }
            }

            // Transition to discard_phase — all seats discard simultaneously (no strict turn order).
            // We set turn to the bid winner as a reference; discard_cards checks phase, not turn.
            $this->games->setPhaseAndTurn($gameId, 'discard_phase', $command->seat);

            return ActionResult::accepted([
                'phase'             => 'discard_phase',
                'current_turn_seat' => $command->seat,
            ]);
        });
    }

    // =========================================================================
    // Phase: discard_phase
    // =========================================================================

    private function handleDiscardCards(array $state, ActionCommand $command): ActionResult
    {
        if (($state['current_phase'] ?? null) !== 'discard_phase') {
            return ActionResult::rejected('wrong_phase', 'Discards are only allowed in discard_phase.');
        }

        $discards = $command->payload['cards'] ?? [];
        if (!is_array($discards)) {
            return ActionResult::rejected('invalid_payload', 'cards must be an array.');
        }
        $discards = array_map(static fn($c) => strtoupper(trim((string) $c)), $discards);

        $gameId = (int) $state['id'];
        return $this->games->withTransaction(function () use ($gameId, $command, $discards, $state): ActionResult {
            $hand = $this->games->findCurrentHand($gameId);
            if ($hand === null) {
                return ActionResult::rejected('no_hand', 'No active hand found.');
            }

            $handId              = (int) $hand['id'];
            $trump               = (string) ($hand['trump_suit'] ?? '');
            $bidWinner           = (int) ($hand['bid_winner_seat'] ?? -1);
            $dealerSeat          = (int) ($hand['dealer_seat'] ?? 0);
            $isBidWinner         = $command->seat === $bidWinner;
            $isDealerExtraDraw   = (bool) ($hand['dealer_extra_draw_pending'] ?? false);
            $playerCount         = $this->games->getPlayerCount($gameId);
            $currentCards        = $this->games->getSeatCards($handId, $command->seat) ?? [];

            // ---------------------------------------------------------------
            // 6-player dealer extra-draw step
            // ---------------------------------------------------------------
            if ($isDealerExtraDraw) {
                if ($command->seat !== $dealerSeat) {
                    return ActionResult::rejected('not_your_turn', 'Only the dealer may discard during the extra-draw step.');
                }

                $remaining = $currentCards;
                foreach ($discards as $code) {
                    $idx = array_search($code, $remaining, true);
                    if ($idx === false) {
                        return ActionResult::rejected('card_not_in_hand', "Card {$code} not in hand.");
                    }
                    unset($remaining[$idx]);
                    $remaining = array_values($remaining);
                }
                if (count($remaining) !== 5) {
                    return ActionResult::rejected(
                        'wrong_discard_count',
                        'After dealer extra-draw discard, hand must have exactly 5 cards; would have ' . count($remaining) . '.'
                    );
                }

                $this->games->updateSeatCards($handId, $command->seat, $remaining);
                $this->games->setDealerExtraDrawPending($handId, false);
                $this->games->appendEvent($gameId, 'dealer_draw_discard', $command->seat, [
                    'seat'      => $command->seat,
                    'discarded' => $discards,
                    'kept'      => $remaining,
                    'at'        => gmdate('c'),
                ]);

                return $this->startTrickPlay($gameId, $state, $handId, $bidWinner);
            }

            // ---------------------------------------------------------------
            // Normal discard — prevent duplicate submissions
            // ---------------------------------------------------------------
            $trumpEvent = $this->games->latestEventByType($gameId, 'trump_declared');
            if ($trumpEvent !== null) {
                $afterSeq    = (int) $trumpEvent['seq_no'];
                $priorEvents = $this->games->listEventsAfter($gameId, $afterSeq, 20);
                foreach ($priorEvents as $e) {
                    if ($e['event_type'] === 'discard_action' && (int) ($e['actor_seat'] ?? -1) === $command->seat) {
                        return ActionResult::rejected('already_discarded', 'This seat has already discarded this hand.');
                    }
                }
            }

            // ---------------------------------------------------------------
            // Validate cards to discard
            // ---------------------------------------------------------------
            $remaining = $currentCards;
            foreach ($discards as $code) {
                $idx = array_search($code, $remaining, true);
                if ($idx === false) {
                    return ActionResult::rejected('card_not_in_hand', "Card {$code} not in hand.");
                }
                unset($remaining[$idx]);
                $remaining = array_values($remaining);
            }

            // 6-player: max 3 replacements per player (bid winner included — they start with 8, discard 3)
            if ($playerCount === 6 && count($discards) > 3) {
                return ActionResult::rejected(
                    'too_many_discards',
                    'In a 6-player game each player may replace at most 3 cards.'
                );
            }

            // Trump discard restriction for bid winner: cannot discard trump while
            // sufficient non-trump cards exist to cover all required discards.
            if ($isBidWinner && $trump !== '') {
                $discardingTrump = array_filter(
                    $discards,
                    fn(string $c) => ($card = $this->parseCardCode($c)) !== null && $this->ranker->isTrump($card, $trump)
                );
                if (!empty($discardingTrump)) {
                    $nonTrumpCount   = count(array_filter(
                        $currentCards,
                        fn(string $c) => ($card = $this->parseCardCode($c)) !== null && !$this->ranker->isTrump($card, $trump)
                    ));
                    $requiredDiscards = count($currentCards) - 5;
                    if ($nonTrumpCount >= $requiredDiscards) {
                        $firstTrump = (string) reset($discardingTrump);
                        return ActionResult::rejected(
                            'cannot_discard_trump',
                            "Cannot discard trump ({$firstTrump}) while sufficient non-trump cards remain."
                        );
                    }
                }
            }

            // ---------------------------------------------------------------
            // Draw replacement cards from deck to refill to 5
            // ---------------------------------------------------------------
            $drawCount = 5 - count($remaining);
            if ($drawCount > 0) {
                $deckRemaining = $this->games->getDeckRemaining($handId);
                if (count($deckRemaining) < $drawCount) {
                    return ActionResult::rejected('deck_empty', 'Not enough cards remaining in deck to refill hand.');
                }
                $drawn     = array_splice($deckRemaining, 0, $drawCount);
                $remaining = array_merge($remaining, $drawn);
                $this->games->setDeckRemaining($handId, $deckRemaining);
            }

            if (count($remaining) !== 5) {
                return ActionResult::rejected(
                    'wrong_discard_count',
                    'After discard hand must have 5 cards; would have ' . count($remaining) . '.'
                );
            }

            $this->games->updateSeatCards($handId, $command->seat, $remaining);
            $this->games->appendEvent($gameId, 'discard_action', $command->seat, [
                'seat'      => $command->seat,
                'discarded' => $discards,
                'kept'      => $remaining,
                'at'        => gmdate('c'),
            ]);

            // ---------------------------------------------------------------
            // Check if all players have now discarded
            // ---------------------------------------------------------------
            $discardCountThisHand = $this->countDiscardActionsThisHand($gameId);

            if ($discardCountThisHand >= $playerCount) {
                if ($playerCount === 6) {
                    // Give dealer all remaining undealt cards; they must discard back to 5.
                    $deckRemaining = $this->games->getDeckRemaining($handId);
                    if (!empty($deckRemaining)) {
                        $dealerCards = array_merge(
                            $this->games->getSeatCards($handId, $dealerSeat) ?? [],
                            $deckRemaining
                        );
                        $this->games->updateSeatCards($handId, $dealerSeat, $dealerCards);
                        $this->games->setDeckRemaining($handId, []);
                        $this->games->setDealerExtraDrawPending($handId, true);
                        $this->games->setPhaseAndTurn($gameId, 'discard_phase', $dealerSeat);
                        $this->games->appendEvent($gameId, 'dealer_draw_extra', $dealerSeat, [
                            'extra_cards' => $deckRemaining,
                            'at'          => gmdate('c'),
                        ]);
                        return ActionResult::accepted([
                            'phase'             => 'discard_phase',
                            'current_turn_seat' => $dealerSeat,
                        ]);
                    }
                    // No extra cards (edge case) — fall through to trick play
                }
                return $this->startTrickPlay($gameId, $state, $handId, $bidWinner);
            }

            return ActionResult::accepted([
                'phase'             => 'discard_phase',
                'current_turn_seat' => (int) $state['current_turn_seat'],
            ]);
        });
    }

    private function countDiscardActionsThisHand(int $gameId): int
    {
        $trumpEvent = $this->games->latestEventByType($gameId, 'trump_declared');
        if ($trumpEvent === null) {
            return 0;
        }
        $afterSeq = (int) $trumpEvent['seq_no'];
        $events   = $this->games->listEventsAfter($gameId, $afterSeq, 20);
        $count    = 0;
        foreach ($events as $e) {
            if ($e['event_type'] === 'discard_action') {
                $count++;
            }
        }
        return $count;
    }

    private function startTrickPlay(int $gameId, array $state, int $handId, int $bidWinnerSeat): ActionResult
    {
        $this->games->setHandPhase($handId, 'trick_play');
        $this->games->setPhaseAndTurn($gameId, 'trick_play', $bidWinnerSeat);

        // Create trick 1
        $this->games->createTrick($handId, 1, $bidWinnerSeat);

        $this->games->appendEvent($gameId, 'trick_play_started', null, [
            'lead_seat' => $bidWinnerSeat,
            'at'        => gmdate('c'),
        ]);

        return ActionResult::accepted([
            'phase'             => 'trick_play',
            'current_turn_seat' => $bidWinnerSeat,
        ]);
    }

    // =========================================================================
    // Phase: trick_play
    // =========================================================================

    private function handlePlayCard(array $state, ActionCommand $command): ActionResult
    {
        if (($state['current_phase'] ?? null) !== 'trick_play') {
            return ActionResult::rejected('wrong_phase', 'Cards can only be played in trick_play phase.');
        }

        $cardCode = strtoupper(trim((string) ($command->payload['card'] ?? '')));
        $card     = $this->parseCardCode($cardCode);
        if ($card === null) {
            return ActionResult::rejected('invalid_card', 'Invalid card code.');
        }

        $gameId = (int) $state['id'];
        return $this->games->withTransaction(function () use ($gameId, $command, $card, $cardCode): ActionResult {
            $hand = $this->games->findCurrentHand($gameId);
            if ($hand === null) {
                return ActionResult::rejected('no_hand', 'No active hand found.');
            }

            $handId = (int) $hand['id'];
            $trump  = (string) ($hand['trump_suit'] ?? 'H');

            // Validate card is in authoritative hand
            $seatCards    = $this->games->getSeatCards($handId, $command->seat) ?? [];
            $cardIdx      = array_search($cardCode, $seatCards, true);
            if ($cardIdx === false) {
                return ActionResult::rejected('card_not_in_hand', 'Card is not in this seat\'s hand.');
            }

            // Validate legal move
            $trick = $this->games->findCurrentTrick($handId);
            if ($trick === null) {
                return ActionResult::rejected('no_trick', 'No active trick found.');
            }

            $plays    = (array) ($trick['cards'] ?? []);
            $leadSuit = null;
            $leadCard = null;
            if (count($plays) > 0) {
                $leadCard = (string) ($plays[0]['card'] ?? '');
                $leadObj  = $this->parseCardCode($leadCard);
                $leadSuit = $leadObj !== null ? ($this->ranker->isTrump($leadObj, $trump) ? $trump : $leadObj->suit) : null;
            }

            $handCardObjs = array_map(fn(string $c) => $this->parseCardCode($c), $seatCards);
            $handCardObjs = array_values(array_filter($handCardObjs));

            if (!$this->validator->canPlayCard($handCardObjs, $card, $leadSuit, $trump, $leadCard)) {
                return ActionResult::rejected('illegal_move', 'That card cannot be played; must follow suit rules.');
            }

            // Remove card from hand
            unset($seatCards[$cardIdx]);
            $seatCards = array_values($seatCards);
            $this->games->updateSeatCards($handId, $command->seat, $seatCards);

            // Add card to trick
            $this->games->addCardToTrick((int) $trick['id'], $command->seat, $cardCode);
            $this->games->appendEvent($gameId, 'card_played', $command->seat, [
                'card' => $cardCode,
                'at'   => gmdate('c'),
            ]);

            $newPlays = array_merge($plays, [['seat' => $command->seat, 'card' => $cardCode]]);

            if (count($newPlays) === 4) {
                return $this->completeTrick($gameId, $hand, $trick, $newPlays, $trump);
            }

            $nextSeat = ($command->seat + 1) % 4;
            $this->games->setTurn($gameId, $nextSeat);
            return ActionResult::accepted(['phase' => 'trick_play', 'current_turn_seat' => $nextSeat]);
        });
    }

    private function completeTrick(int $gameId, array $hand, array $trick, array $plays, string $trump): ActionResult
    {
        $handId      = (int) $hand['id'];
        $trickNumber = (int) $trick['trick_number'];

        // Build seat→Card map and find lead suit
        $seatCards   = [];
        $leadSuit    = null;
        $leadCardObj = null;
        foreach ($plays as $idx => $play) {
            $seat     = (int) $play['seat'];
            $cardObj  = $this->parseCardCode((string) $play['card']);
            if ($cardObj === null) {
                continue;
            }
            $seatCards[$seat] = $cardObj;
            if ($idx === 0) {
                $leadCardObj = $cardObj;
                $leadSuit    = $this->ranker->isTrump($cardObj, $trump) ? $trump : $cardObj->suit;
            }
        }

        $winnerSeat = $this->resolver->winningSeat($seatCards, $leadSuit ?? $trump, $trump);

        // Find best trump played (for +5 bonus point)
        $bestTrump     = null;
        $bestTrumpStr  = 0;
        foreach ($seatCards as $c) {
            if ($this->ranker->isTrump($c, $trump)) {
                $str = $this->ranker->strength($c, $trump);
                if ($str > $bestTrumpStr) {
                    $bestTrumpStr = $str;
                    $bestTrump    = $c->code();
                }
            }
        }

        $this->games->closeTrick((int) $trick['id'], $winnerSeat, $bestTrump);
        $this->games->appendEvent($gameId, 'trick_won', $winnerSeat, [
            'winner_seat'       => $winnerSeat,
            'trick_number'      => $trickNumber,
            'lead_suit'         => $leadSuit,
            'trump_suit'        => $trump,
            'winning_card'      => ($seatCards[$winnerSeat] ?? null)?->code(),
            'best_trump_played' => $bestTrump,
            'at'                => gmdate('c'),
        ]);

        $completedTricks = $this->games->countCompletedTricks($handId);

        if ($completedTricks >= 5) {
            return $this->scoreHand($gameId, $hand);
        }

        // Start next trick
        $nextTrickNumber = $trickNumber + 1;
        $this->games->createTrick($handId, $nextTrickNumber, $winnerSeat);
        $this->games->setTurn($gameId, $winnerSeat);

        return ActionResult::accepted(['phase' => 'trick_play', 'current_turn_seat' => $winnerSeat]);
    }

    // =========================================================================
    // Phase: score_hand
    // =========================================================================

    private function scoreHand(int $gameId, array $hand): ActionResult
    {
        $handId     = (int) $hand['id'];
        $tricks     = $this->games->listTricks($handId);
        $trump      = (string) ($hand['trump_suit'] ?? 'H');
        $bidWinner  = (int) ($hand['bid_winner_seat'] ?? 0);
        $bidValue   = (int) ($hand['bid_value'] ?? 15);
        $is30For60  = (bool) ($hand['is_30_for_60'] ?? false);
        $bidTeam    = $bidWinner % 2;

        // Count trick points per team (5 pts/trick) and find best-trump holder
        $teamTricks  = [0 => 0, 1 => 0];
        $bestTrumpStr = 0;
        $bestTrumpTeam = null;

        foreach ($tricks as $trick) {
            $winner = (int) ($trick['winner_seat'] ?? -1);
            if ($winner < 0) {
                continue;
            }
            $team = $winner % 2;
            $teamTricks[$team] += 5;

            // Check if best trump was played in this trick
            $btCode = $trick['best_trump_played'] ?? null;
            if ($btCode !== null) {
                $btCard = $this->parseCardCode((string) $btCode);
                if ($btCard !== null) {
                    $str = $this->ranker->strength($btCard, $trump);
                    if ($str > $bestTrumpStr) {
                        $bestTrumpStr  = $str;
                        $bestTrumpTeam = $team;
                    }
                }
            }
        }

        // Best trump bonus: +5 points to the team whose player holds best trump when it wins a trick
        if ($bestTrumpTeam !== null) {
            $teamTricks[$bestTrumpTeam] += 5;
        }

        // Bid success/failure
        $bidTeamPoints = $teamTricks[$bidTeam];
        $nonBidTeam    = 1 - $bidTeam;

        $prev = $this->games->getRunningScores($gameId);
        $t0   = (int) $prev['team0_total'];
        $t1   = (int) $prev['team1_total'];
        $s0   = (int) $prev['team0_sets'];
        $s1   = (int) $prev['team1_sets'];

        // Non-bidding team always scores what they won
        $team0Delta = 0;
        $team1Delta = 0;

        if ($bidTeam === 0) {
            $team1Delta = $teamTricks[1];
        } else {
            $team0Delta = $teamTricks[0];
        }

        $bidMade = $bidTeamPoints >= $bidValue;
        if ($bidMade) {
            // Score points won (not just bid amount)
            if ($bidTeam === 0) {
                $team0Delta = $is30For60 ? 60 : $bidTeamPoints;
            } else {
                $team1Delta = $is30For60 ? 60 : $bidTeamPoints;
            }
        } else {
            // Set: lose the bid amount (or 60 for 30-for-60)
            $penalty = $is30For60 ? 60 : $bidValue;
            if ($bidTeam === 0) {
                $team0Delta = -$penalty;
                $s0++;
            } else {
                $team1Delta = -$penalty;
                $s1++;
            }
        }

        $t0 += $team0Delta;
        $t1 += $team1Delta;

        // "Dreaded 45" rule is off for Chartrand — no reset to 0

        $this->games->insertScore($gameId, $handId, $team0Delta, $team1Delta, $t0, $t1, $s0, $s1);
        $this->games->setHandPhase($handId, 'scored');

        $this->games->appendEvent($gameId, 'hand_scored', null, [
            'hand_number'   => (int) $hand['hand_number'],
            'bid_team'      => $bidTeam,
            'bid_value'     => $bidValue,
            'is_30_for_60'  => $is30For60,
            'bid_made'      => $bidMade,
            'team0_delta'   => $team0Delta,
            'team1_delta'   => $team1Delta,
            'team0_total'   => $t0,
            'team1_total'   => $t1,
            'team0_sets'    => $s0,
            'team1_sets'    => $s1,
            'at'            => gmdate('c'),
        ]);

        $gameId_int  = $gameId;
        $targetScore = (int) ($this->games->findGameState($gameId_int)['target_score'] ?? 120);

        // Game-over conditions:
        // 1. A team is set 3 times → they lose immediately
        // 2. The bidding team reaches or exceeds target score → they win
        //    (non-bidding team winning via score is only possible as bidding team in a future hand)
        if ($s0 >= 3 || $s1 >= 3) {
            $winTeam = $s0 >= 3 ? 1 : 0;
            return $this->endGame($gameId, $handId, $winTeam, 'three_sets', $t0, $t1);
        }

        if ($bidMade) {
            $bidTeamTotal = $bidTeam === 0 ? $t0 : $t1;
            if ($bidTeamTotal >= $targetScore) {
                return $this->endGame($gameId, $handId, $bidTeam, 'target_reached', $t0, $t1);
            }
        }

        // Start next hand
        return $this->startNextHand($gameId, $hand);
    }

    private function endGame(int $gameId, int $handId, int $winTeam, string $reason, int $t0, int $t1): ActionResult
    {
        $this->games->setGameStatus($gameId, 'finished');
        $this->games->setPhaseAndTurn($gameId, 'game_over', 0);

        $this->games->appendEvent($gameId, 'game_over', null, [
            'winning_team' => $winTeam,
            'reason'       => $reason,
            'team0_total'  => $t0,
            'team1_total'  => $t1,
            'at'           => gmdate('c'),
        ]);

        return ActionResult::accepted(['phase' => 'game_over', 'winning_team' => $winTeam]);
    }

    private function startNextHand(int $gameId, array $prevHand): ActionResult
    {
        $newHandNumber = (int) $prevHand['hand_number'] + 1;
        $newDealer     = ((int) $prevHand['dealer_seat'] + 1) % 4;
        $firstBidder   = ($newDealer + 1) % 4;

        $this->games->advanceHand($gameId, $newHandNumber, $newDealer);
        $this->games->setPhaseAndTurn($gameId, 'bidding', $firstBidder);

        // Deal new hand
        $handId = $this->dealNewHand($gameId, $newHandNumber, $newDealer);

        $this->games->appendEvent($gameId, 'hand_started', null, [
            'hand_number' => $newHandNumber,
            'dealer_seat' => $newDealer,
            'at'          => gmdate('c'),
        ]);

        return ActionResult::accepted(['phase' => 'bidding', 'current_turn_seat' => $firstBidder]);
    }

    // =========================================================================
    // AI turn runner
    // =========================================================================

    /**
     * Advance the game for any AI seats whose turn it currently is.
     * Loops until the current-turn seat belongs to a human, the phase is not
     * an actionable phase, or the safety limit is reached.
     *
     * Discard phase is handled separately: all AI seats that have not yet
     * discarded this hand are prompted to do so (simultaneous phase).
     */
    public function runAiTurns(int $gameId, MoveProviderInterface $ai, int $maxIter = 24): void
    {
        for ($i = 0; $i < $maxIter; $i++) {
            $state = $this->games->findGameState($gameId);
            if ($state === null) {
                return;
            }

            $phase = (string) ($state['current_phase'] ?? '');
            if (!in_array($phase, ['bidding', 'declare_trump', 'discard_phase', 'trick_play'], true)) {
                return;
            }

            $aiSeats = $this->games->getAiSeats($gameId);
            if (empty($aiSeats)) {
                return;
            }

            if ($phase === 'discard_phase') {
                if (!$this->runOneAiDiscard($gameId, $state, $aiSeats, $ai)) {
                    return; // no AI seat needs to discard right now
                }
            } else {
                $turnSeat = (int) ($state['current_turn_seat'] ?? -1);
                if (!in_array($turnSeat, $aiSeats, true)) {
                    return; // human's turn
                }
                $ctx     = $this->buildAiContext($gameId, $state, $turnSeat);
                $request = new AIRequest($gameId, $turnSeat, $phase, $ctx, []);
                $resp    = $ai->choose($request);
                $cmd     = new ActionCommand($gameId, $turnSeat, $resp->actionType, $resp->payload);
                $result  = $this->handle($cmd);
                if (!$result->accepted) {
                    return; // AI produced an illegal move — stop to avoid infinite loop
                }
                // Limit trick_play to one AI card per poll so the UI can animate each card.
                if ($phase === 'trick_play') {
                    return;
                }
            }
        }
    }

    /**
     * Find the first AI seat that has not yet submitted a discard this hand
     * and dispatch its discard action.  Returns true if a discard was sent.
     */
    private function runOneAiDiscard(int $gameId, array $state, array $aiSeats, MoveProviderInterface $ai): bool
    {
        // Determine which seats have already discarded since trump was declared.
        $trumpEvent = $this->games->latestEventByType($gameId, 'trump_declared');
        $afterSeq   = $trumpEvent !== null ? (int) $trumpEvent['seq_no'] : 0;
        $events     = $this->games->listEventsAfter($gameId, $afterSeq, 40);

        $discardedSeats = [];
        foreach ($events as $e) {
            if ($e['event_type'] === 'discard_action') {
                $discardedSeats[(int) $e['actor_seat']] = true;
            }
        }

        // Also handle the dealer-extra-draw step: check dealer_extra_draw_pending.
        $hand             = $this->games->findCurrentHand($gameId);
        $dealerExtraDraw  = $hand !== null && (bool) ($hand['dealer_extra_draw_pending'] ?? false);
        $dealerSeat       = (int) ($state['dealer_seat'] ?? -1);

        foreach ($aiSeats as $seat) {
            if ($dealerExtraDraw) {
                // Only the dealer acts in the extra-draw step.
                if ($seat !== $dealerSeat) {
                    continue;
                }
            } elseif (isset($discardedSeats[$seat])) {
                continue; // already discarded
            }

            $ctx     = $this->buildAiContext($gameId, $state, $seat);
            $request = new AIRequest($gameId, $seat, 'discard_phase', $ctx, []);
            $resp    = $ai->choose($request);
            $cmd     = new ActionCommand($gameId, $seat, $resp->actionType, $resp->payload);
            $this->handle($cmd);
            return true;
        }

        return false;
    }

    /**
     * Assemble the state context that the AI needs to make its decision.
     */
    private function buildAiContext(int $gameId, array $gameState, int $seat): array
    {
        $hand  = $this->games->findCurrentHand($gameId);
        $cards = $hand !== null ? ($this->games->getSeatCards((int) $hand['id'], $seat) ?? []) : [];
        $trump = (string) ($hand['trump_suit'] ?? '');

        $ctx = [
            'hand_cards'   => $cards,
            'trump_suit'   => $trump,
            'dealer_seat'  => (int) ($gameState['dealer_seat'] ?? 0),
            'is_bid_winner' => $hand !== null && (int) ($hand['bid_winner_seat'] ?? -1) === $seat,
        ];

        $phase = (string) ($gameState['current_phase'] ?? '');

        if ($phase === 'bidding') {
            $summary             = $this->games->bidSummary($gameId);
            $ctx['highest_bid']  = (int) ($summary['highest_bid'] ?? 0);
        }

        if ($phase === 'trick_play' && $hand !== null) {
            $trick = $this->games->findCurrentTrick((int) $hand['id']);
            if ($trick !== null) {
                $played           = $trick['cards'] ?? [];
                $leadCardCode     = !empty($played) ? (string) $played[0]['card'] : null;
                $leadSuit         = $leadCardCode !== null
                    ? strtoupper(substr($leadCardCode, -1))
                    : null;
                $ctx['lead_suit'] = $leadSuit;
                $ctx['lead_card'] = $leadCardCode;
            }
        }

        return $ctx;
    }

    // =========================================================================
    // Hand dealing (also called from lobby/create_game when game starts)
    // =========================================================================

    /**
     * Deal a fresh hand: shuffle deck, deal 5 cards per seat, set aside 3-card kitty.
     * Persists to hands + hand_cards tables.
     */
    public function dealNewHand(int $gameId, int $handNumber, int $dealerSeat): int
    {
        $playerCount = $this->games->getPlayerCount($gameId);
        $seed        = random_int(1, PHP_INT_MAX);
        $deck        = $this->buildShuffledDeck($seed);

        // Deal 5 cards to each seat round-robin
        $hands = [];
        for ($s = 0; $s < $playerCount; $s++) {
            $hands[$s] = [];
        }
        for ($round = 0; $round < 5; $round++) {
            for ($s = 0; $s < $playerCount; $s++) {
                $hands[$s][] = array_shift($deck);
            }
        }

        // Next 3 cards form the kitty; whatever remains stays in the deck for draws
        $kitty         = array_splice($deck, 0, 3);
        $deckRemaining = array_values($deck);

        $handId = $this->games->createHand($gameId, $handNumber, $dealerSeat, $seed, $kitty);
        for ($s = 0; $s < $playerCount; $s++) {
            $this->games->dealSeatCards($handId, $s, $hands[$s]);
        }

        // Persist remaining deck so discard-phase draws and the 6-player dealer
        // extra-draw step can pull from it.
        if (!empty($deckRemaining)) {
            $this->games->setDeckRemaining($handId, $deckRemaining);
        }

        // Set bidding turn to the player left of the dealer (dealer bids last).
        $firstBidder = ($dealerSeat + 1) % $playerCount;
        $this->games->setPhaseAndTurn($gameId, 'bidding', $firstBidder);

        $this->games->appendEvent($gameId, 'hand_dealt', null, [
            'hand_number'  => $handNumber,
            'dealer_seat'  => $dealerSeat,
            'first_bidder' => $firstBidder,
            'player_count' => $playerCount,
            'at'           => gmdate('c'),
        ]);

        return $handId;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function buildShuffledDeck(int $seed): array
    {
        $ranks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        $suits = ['C', 'D', 'H', 'S'];
        $deck  = [];
        foreach ($suits as $suit) {
            foreach ($ranks as $rank) {
                $deck[] = $rank . $suit;
            }
        }
        mt_srand($seed);
        shuffle($deck);
        return $deck;
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
        $rank         = substr($code, 0, -1);
        $allowedRanks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        if (!in_array($rank, $allowedRanks, true)) {
            return null;
        }
        return new Card($suit, $rank);
    }
}
