<?php

declare(strict_types=1);

namespace FortyFives\Infrastructure\AI;

use FortyFives\Domain\AI\AIRequest;
use FortyFives\Domain\AI\AIResponse;
use FortyFives\Domain\AI\MoveProviderInterface;
use FortyFives\Domain\Game\Card;
use FortyFives\Domain\Rules\CardRanker;
use FortyFives\Domain\Rules\LegalMoveValidator;

/**
 * Simple heuristic AI for 45s.
 *
 * Bidding:   Counts cards per suit; bids (max suit count × 5) if that is a valid
 *            bid value AND beats the current highest bid. Otherwise passes.
 *
 * Trump:     Declares the suit with the most cards in hand.
 *
 * Discard:   Keeps the 5 highest-strength cards; discards the rest.
 *            Respects the "no trump discard when non-trump available" rule for
 *            bid winners.
 *
 * Trick play: Plays the legal card with the highest CardRanker strength.
 *             Breaks ties randomly.
 */
final class AlgorithmicMoveProvider implements MoveProviderInterface
{
    private readonly CardRanker $ranker;
    private readonly LegalMoveValidator $validator;

    public function __construct()
    {
        $this->ranker    = new CardRanker();
        $this->validator = new LegalMoveValidator($this->ranker);
    }

    public function choose(AIRequest $request): AIResponse
    {
        return match ($request->phase) {
            'bidding'       => $this->chooseBid($request),
            'declare_trump' => $this->chooseTrump($request),
            'discard_phase' => $this->chooseDiscard($request),
            'trick_play'    => $this->chooseCard($request),
            default         => new AIResponse('pass', [], 'Unknown phase — passing'),
        };
    }

    // =========================================================================
    // Phase handlers
    // =========================================================================

    /**
     * Bid (max suit count × 5) if it is a valid bid value and is high enough.
     * Non-dealer must strictly exceed the current highest; dealer may match to steal.
     *
     * In the reject loop:
     *   - Dealer AI: concedes (passes) — simple heuristic, always let bidder name trump.
     *   - Bidder AI: raises by one step if it can, otherwise concedes.
     *
     * Valid bid values: 15, 20, 25, 30, 60.
     */
    private function chooseBid(AIRequest $request): AIResponse
    {
        $hand         = $request->state['hand_cards'] ?? [];
        $highestBid   = (int) ($request->state['highest_bid'] ?? 0);
        $highestSeat  = $request->state['highest_bid_seat'] ?? null;
        $isDealer     = $request->seat === (int) ($request->state['dealer_seat'] ?? -1);
        $inRejectLoop = (bool) ($request->state['in_reject_loop'] ?? false);

        if ($inRejectLoop) {
            if ($isDealer) {
                // Dealer AI always concedes in reject loop
                return new AIResponse('submit_bid', ['bid' => 'pass'], 'Conceding reject loop to bidder');
            }

            // Bidder AI: raise by one step or concede if at ceiling
            $ladder = [15, 20, 25, 30, 60];
            $pos    = array_search($highestBid, $ladder, true);
            if ($pos !== false && $pos < count($ladder) - 1) {
                $nextBid = $ladder[$pos + 1];
                return new AIResponse('submit_bid', ['bid' => $nextBid], "Raising to {$nextBid} in reject loop");
            }
            return new AIResponse('submit_bid', ['bid' => 'pass'], 'At bid ceiling — conceding reject loop to dealer');
        }

        $suitCounts = $this->countSuits($hand);
        $maxCount   = max($suitCounts);

        // Dealer's initial turn: reject (strong hand) or concede — no numeric bids allowed
        if ($isDealer && $highestBid > 0) {
            if ($highestBid < 60 && $maxCount >= 4) {
                return new AIResponse('submit_bid', ['bid' => 'reject'], "Dealer rejects bid of {$highestBid}");
            }
            return new AIResponse('submit_bid', ['bid' => 'pass'], "Dealer concedes at {$highestBid}");
        }

        // Non-dealer (or dealer with no standing bid — forced 15 via pass)
        $bidAmount = $maxCount * 5;
        $legalBids = [15, 20, 25, 30, 60];
        $isLegal   = in_array($bidAmount, $legalBids, true) && $bidAmount > $highestBid;

        if (!$isLegal) {
            return new AIResponse(
                'submit_bid',
                ['bid' => 'pass'],
                "Passing: computed bid {$bidAmount} not legal (current high {$highestBid})"
            );
        }

        return new AIResponse(
            'submit_bid',
            ['bid' => $bidAmount],
            "Bidding {$bidAmount} ({$maxCount} cards in best suit)"
        );
    }

    /**
     * Declare the suit in which the AI holds the most cards.
     * Ties broken by suit order C < D < H < S.
     */
    private function chooseTrump(AIRequest $request): AIResponse
    {
        $hand       = $request->state['hand_cards'] ?? [];
        $suitCounts = $this->countSuits($hand);
        arsort($suitCounts);

        $trump = (string) array_key_first($suitCounts);

        return new AIResponse(
            'declare_trump',
            ['trump' => $trump],
            "Declaring {$trump} ({$suitCounts[$trump]} cards in hand)"
        );
    }

    /**
     * Rank all cards by CardRanker strength and discard the weakest ones to
     * bring the hand down to 5. For bid winners, trump cards are not discarded
     * while sufficient non-trump cards remain to cover the required discard count.
     */
    private function chooseDiscard(AIRequest $request): AIResponse
    {
        $hand        = $request->state['hand_cards'] ?? [];
        $trump       = (string) ($request->state['trump_suit'] ?? '');
        $isBidWinner = (bool) ($request->state['is_bid_winner'] ?? false);

        if (count($hand) <= 5) {
            return new AIResponse('discard_cards', ['cards' => []], 'Hand already ≤ 5 — no discard needed');
        }

        // Score every card
        $scored = [];
        foreach ($hand as $code) {
            $card = $this->parseCode((string) $code);
            if ($card === null) {
                continue;
            }
            $scored[] = [
                'code'     => $code,
                'strength' => $trump !== '' ? $this->ranker->strength($card, $trump) : 0,
                'is_trump' => $trump !== '' && $this->ranker->isTrump($card, $trump),
            ];
        }

        // Sort descending by strength — keep the strongest 5
        usort($scored, static fn(array $a, array $b) => $b['strength'] <=> $a['strength']);

        $toDiscard  = array_column(array_slice($scored, 5), 'code');

        // Bid-winner rule: cannot discard trump while non-trump cards can cover the discards
        if ($isBidWinner && $trump !== '') {
            $requiredDiscards = count($hand) - 5;
            $nonTrumpInHand   = array_filter($scored, static fn(array $c) => !$c['is_trump']);

            if (count($nonTrumpInHand) >= $requiredDiscards) {
                // Strip any trump cards from the discard list
                $toDiscard = array_values(array_filter(
                    $toDiscard,
                    function (string $code) use ($trump): bool {
                        $card = $this->parseCode($code);
                        return $card === null || !$this->ranker->isTrump($card, $trump);
                    }
                ));
            }
        }

        return new AIResponse(
            'discard_cards',
            ['cards' => $toDiscard],
            'Discarding ' . count($toDiscard) . ' weakest card(s)'
        );
    }

    /**
     * Play the legal card with the highest CardRanker strength.
     * When multiple legal cards share the top strength, one is chosen at random.
     */
    private function chooseCard(AIRequest $request): AIResponse
    {
        $hand        = $request->state['hand_cards'] ?? [];
        $trump       = (string) ($request->state['trump_suit'] ?? '');
        $leadSuit    = $request->state['lead_suit'] ?? null;
        $leadCard    = $request->state['lead_card'] ?? null;
        $trumpBroken = (bool) ($request->state['trump_broken'] ?? false);

        if (empty($hand)) {
            return new AIResponse('play_card', ['card' => ''], 'No cards in hand');
        }

        // Build Card objects for the full hand (needed by LegalMoveValidator)
        $handObjs = [];
        foreach ($hand as $code) {
            $c = $this->parseCode((string) $code);
            if ($c !== null) {
                $handObjs[] = $c;
            }
        }

        // Collect legal plays with their strength
        $legal = [];
        foreach ($hand as $code) {
            $card = $this->parseCode((string) $code);
            if ($card === null) {
                continue;
            }
            if ($this->validator->canPlayCard($handObjs, $card, $leadSuit, $trump, $leadCard, $trumpBroken)) {
                $legal[] = [
                    'code'     => $code,
                    'strength' => $trump !== '' ? $this->ranker->strength($card, $trump) : 0,
                ];
            }
        }

        if (empty($legal)) {
            // Fallback: play the first card (should never happen in a valid game state)
            return new AIResponse('play_card', ['card' => (string) $hand[0]], 'Fallback: no legal cards found');
        }

        $maxStrength = max(array_column($legal, 'strength'));
        $best        = array_values(array_filter($legal, static fn(array $c) => $c['strength'] === $maxStrength));
        $chosen      = $best[array_rand($best)];

        return new AIResponse(
            'play_card',
            ['card' => (string) $chosen['code']],
            "Playing {$chosen['code']} (strength {$maxStrength})"
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Count cards per suit (by literal suit character; AH counts as H).
     * @param string[] $hand
     * @return array<string,int>  keys: C D H S
     */
    private function countSuits(array $hand): array
    {
        $counts = ['C' => 0, 'D' => 0, 'H' => 0, 'S' => 0];
        foreach ($hand as $code) {
            $suit = strtoupper(substr((string) $code, -1));
            if (isset($counts[$suit])) {
                $counts[$suit]++;
            }
        }
        return $counts;
    }

    private function parseCode(string $code): ?Card
    {
        $code = strtoupper(trim($code));
        if (strlen($code) < 2) {
            return null;
        }
        $suit = substr($code, -1);
        $rank = substr($code, 0, -1);
        if (!in_array($suit, ['C', 'D', 'H', 'S'], true)) {
            return null;
        }
        $allowedRanks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        if (!in_array($rank, $allowedRanks, true)) {
            return null;
        }
        return new Card($suit, $rank);
    }
}
