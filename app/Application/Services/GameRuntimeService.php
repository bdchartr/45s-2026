<?php

declare(strict_types=1);

namespace FortyFives\Application\Services;

use FortyFives\Application\Contracts\ActionCommand;
use FortyFives\Application\Contracts\ActionResult;
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

        if ((int) ($state['current_turn_seat'] ?? -1) !== $command->seat) {
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

        $gameId = (int) $state['id'];
        return $this->games->withTransaction(function () use ($gameId, $command, $bid, $isPass, $state): ActionResult {
            $this->games->appendEvent($gameId, 'bid_action', $command->seat, [
                'bid' => $isPass ? 'pass' : $bid,
                'at'  => gmdate('c'),
            ]);

            $summary    = $this->games->bidSummary($gameId);
            $dealerSeat = (int) ($state['dealer_seat'] ?? 0);
            $playerCount = 4;
            $nextSeat    = ($command->seat + 1) % $playerCount;

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

        // Bid winner gets the kitty — emit event then transition to trump declaration
        if ($hand !== null) {
            $this->games->appendEvent($gameId, 'kitty_picked_up', (int) $winnerSeat, [
                'winner_seat' => (int) $winnerSeat,
                'kitty'       => $hand['kitty'],
                'at'          => gmdate('c'),
            ]);
            // Add kitty cards to winner's hand
            $currentCards = $this->games->getSeatCards((int) $hand['id'], (int) $winnerSeat) ?? [];
            $merged = array_values(array_unique(array_merge($currentCards, $hand['kitty'])));
            $this->games->updateSeatCards((int) $hand['id'], (int) $winnerSeat, $merged);
        }

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

            $handId       = (int) $hand['id'];
            $trump        = (string) ($hand['trump_suit'] ?? '');
            $bidWinner    = (int) ($hand['bid_winner_seat'] ?? -1);
            $isBidWinner  = $command->seat === $bidWinner;
            $currentCards = $this->games->getSeatCards($handId, $command->seat) ?? [];

            // Validate discards are in hand
            $remaining = $currentCards;
            foreach ($discards as $code) {
                $idx = array_search($code, $remaining, true);
                if ($idx === false) {
                    return ActionResult::rejected('card_not_in_hand', "Card {$code} not in hand.");
                }
                unset($remaining[$idx]);
                $remaining = array_values($remaining);
            }

            // Trump discard restriction: bid winner may not discard trump unless forced (hand > 5 cards)
            if ($isBidWinner && count($remaining) > 5 && $trump !== '') {
                foreach ($discards as $code) {
                    $card = $this->parseCardCode($code);
                    if ($card !== null && $this->ranker->isTrump($card, $trump)) {
                        // Only allowed if they have more than 5 trump cards — simplification:
                        // flag as error unless hand would still have 5 cards
                        if (count($remaining) > 5) {
                            // Still too many — keep discarding, this trump discard not yet forced
                            // We allow it only if it's truly unavoidable (all remaining are trump)
                            $nonTrumpRemaining = array_filter($remaining, function (string $c) use ($trump): bool {
                                $parsed = $this->parseCardCode($c);
                                return $parsed !== null && !$this->ranker->isTrump($parsed, $trump);
                            });
                            if (count($nonTrumpRemaining) > 0) {
                                return ActionResult::rejected(
                                    'cannot_discard_trump',
                                    "Cannot discard trump ({$code}) while non-trump cards remain."
                                );
                            }
                        }
                    }
                }
            }

            // Enforce bid winner must discard down to exactly 5 after kitty (8 cards → discard 3)
            // Other players may discard 0–5 cards
            $targetSize = 5;
            if (count($remaining) !== $targetSize) {
                return ActionResult::rejected(
                    'wrong_discard_count',
                    "After discard hand must have {$targetSize} cards; would have " . count($remaining) . '.'
                );
            }

            $this->games->updateSeatCards($handId, $command->seat, $remaining);
            $this->games->appendEvent($gameId, 'discard_action', $command->seat, [
                'seat'      => $command->seat,
                'discarded' => $discards,
                'kept'      => $remaining,
                'at'        => gmdate('c'),
            ]);

            // Check if all 4 seats have discarded
            $discardEvents = $this->games->countEventsByType($gameId, 'discard_action');
            // Count discards for this hand only (after trump_declared)
            $discardCountThisHand = $this->countDiscardActionsThisHand($gameId);

            if ($discardCountThisHand >= 4) {
                return $this->startTrickPlay($gameId, $state, $handId, (int) ($hand['bid_winner_seat'] ?? 0));
            }

            // Still waiting for other players; keep same phase, no turn change
            return ActionResult::accepted([
                'phase'             => 'discard_phase',
                'current_turn_seat' => (int) $state['current_turn_seat'],
            ]);
        });
    }

    private function countDiscardActionsThisHand(int $gameId): int
    {
        // Count discard_action events after the most recent trump_declared event
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
    // Hand dealing (also called from lobby/create_game when game starts)
    // =========================================================================

    /**
     * Deal a fresh hand: shuffle deck, deal 5 cards per seat, set aside 3-card kitty.
     * Persists to hands + hand_cards tables.
     */
    public function dealNewHand(int $gameId, int $handNumber, int $dealerSeat): int
    {
        $seed = random_int(1, PHP_INT_MAX);
        $deck = $this->buildShuffledDeck($seed);

        // Deal 5 cards to each seat round-robin
        $hands = [0 => [], 1 => [], 2 => [], 3 => []];
        for ($round = 0; $round < 5; $round++) {
            for ($s = 0; $s < 4; $s++) {
                $hands[$s][] = array_shift($deck);
            }
        }

        // Remaining 3 cards are the kitty (top of remaining deck after deal)
        $kitty = array_slice($deck, 0, 3);

        $handId = $this->games->createHand($gameId, $handNumber, $dealerSeat, $seed, $kitty);
        for ($s = 0; $s < 4; $s++) {
            $this->games->dealSeatCards($handId, $s, $hands[$s]);
        }

        $this->games->appendEvent($gameId, 'hand_dealt', null, [
            'hand_number' => $handNumber,
            'dealer_seat' => $dealerSeat,
            'at'          => gmdate('c'),
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
