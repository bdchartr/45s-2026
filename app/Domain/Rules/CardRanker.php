<?php

declare(strict_types=1);

namespace FortyFives\Domain\Rules;

use FortyFives\Domain\Game\Card;

/**
 * Full 45s card ranking per the Chartrand/Newfoundland ruleset.
 *
 * Trump ranking (high → low):
 *   5-trump, J-trump, A-Hearts, A-trump(*), K, Q, [suit-specific number order]
 *   (*) A-trump only applies when trump is not Hearts (AH is already slot 3)
 *
 * Non-trump red suits (Hearts/Diamonds) high → low:
 *   K, Q, J, 10, 9, 8, 7, 6, 5, 4, 3, 2  (A is always trump)
 *
 * Non-trump black suits (Spades/Clubs) high → low:
 *   K, Q, J, A, 2, 3, 4, 5, 6, 7, 8, 9, 10  (numbers rank low→high)
 *
 * Ace of Hearts is ALWAYS a trump card regardless of trump suit.
 */
final class CardRanker
{
    /**
     * Returns a sortable strength score where higher = stronger.
     * Scores are only meaningful when comparing cards in the same trick context.
     *
     * A card that cannot win (wrong suit, not trump) returns 0.
     * Trump cards return values 100–199.
     * Lead-suit non-trump returns 1–49.
     */
    public function strength(Card $card, string $trumpSuit): int
    {
        if ($this->isTrump($card, $trumpSuit)) {
            return $this->trumpStrength($card, $trumpSuit);
        }

        return $this->nonTrumpStrength($card, $trumpSuit);
    }

    public function isTrump(Card $card, string $trumpSuit): bool
    {
        // Ace of Hearts is always trump
        if ($card->rank === 'A' && $card->suit === 'H') {
            return true;
        }
        return $card->suit === $trumpSuit;
    }

    /**
     * Is this one of the three "top trumps" that cannot be forced out by a lower trump lead?
     * Top trumps: 5 of trump, Jack of trump, Ace of Hearts.
     */
    public function isTopTrump(Card $card, string $trumpSuit): bool
    {
        if ($card->rank === '5' && $card->suit === $trumpSuit) {
            return true;
        }
        if ($card->rank === 'J' && $card->suit === $trumpSuit) {
            return true;
        }
        if ($card->rank === 'A' && $card->suit === 'H') {
            return true;
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Trump strength values (100–199):
     *   5-trump  = 199 (highest)
     *   J-trump  = 198
     *   A-Hearts = 197
     *   A-trump  = 196 (only when trump != Hearts; otherwise A-Hearts is already 197)
     *   K-trump  = 113
     *   Q-trump  = 112
     *   then number cards by suit-specific rank order
     */
    private function trumpStrength(Card $card, string $trumpSuit): int
    {
        // Top 3 trumps
        if ($card->rank === '5' && $card->suit === $trumpSuit) {
            return 199;
        }
        if ($card->rank === 'J' && $card->suit === $trumpSuit) {
            return 198;
        }
        if ($card->rank === 'A' && $card->suit === 'H') {
            return 197;
        }
        // A of trump suit (only relevant when trump is not Hearts, since AH is already handled)
        if ($card->rank === 'A' && $card->suit === $trumpSuit) {
            return 196;
        }
        // K, Q get high fixed values
        if ($card->rank === 'K') {
            return 113;
        }
        if ($card->rank === 'Q') {
            return 112;
        }
        // Remaining trump number cards ranked by suit-specific order within 101–111
        $order = $this->numberRankOrder($card->rank, $trumpSuit);
        return 100 + $order;
    }

    /**
     * Non-trump strength (1–49). Only the lead suit can win a non-trump trick.
     * The caller (TrickResolver) is responsible for discarding off-suit non-trump cards.
     */
    private function nonTrumpStrength(Card $card, string $trumpSuit): int
    {
        // Red non-trump suits: K=13, Q=12, J=11, 10=10, 9=9, 8=8, 7=7, 6=6, 5=5, 4=4, 3=3, 2=2
        // Note: A of Hearts is always trump so never reaches here.
        // A of Diamonds (non-trump) ranks lowest in diamonds.
        if ($card->suit === 'H' || $card->suit === 'D') {
            return $this->redNonTrumpStrength($card);
        }
        // Black non-trump suits: K=13, Q=12, J=11, A=10, then 2=1, 3=2, 4=3, 5=4, 6=5, 7=6, 8=7, 9=8, 10=9
        return $this->blackNonTrumpStrength($card);
    }

    /**
     * Red non-trump strength.
     * Hearts: K Q J 10 9 8 7 6 5 4 3 2  (Ace is always trump, never here)
     * Diamonds: K Q J 10 9 8 7 6 5 4 3 2 A  (Ace lowest when not trump)
     */
    private function redNonTrumpStrength(Card $card): int
    {
        $map = [
            'K' => 13, 'Q' => 12, 'J' => 11,
            '10' => 10, '9' => 9, '8' => 8, '7' => 7,
            '6' => 6, '5' => 5, '4' => 4, '3' => 3, '2' => 2,
            'A' => 1,  // Ace of Diamonds ranks lowest when not trump
        ];
        return $map[$card->rank] ?? 0;
    }

    /**
     * Black non-trump strength.
     * K Q J A  then 2 3 4 5 6 7 8 9 10  (numbers rank low→high, opposite of red)
     */
    private function blackNonTrumpStrength(Card $card): int
    {
        $map = [
            'K' => 13, 'Q' => 12, 'J' => 11, 'A' => 10,
            '2' => 1, '3' => 2, '4' => 3, '5' => 4, '6' => 5,
            '7' => 6, '8' => 7, '9' => 8, '10' => 9,
        ];
        return $map[$card->rank] ?? 0;
    }

    /**
     * Rank-within-trump for number cards (not 5, J, A).
     * Red trump suits: 10 9 8 7 6 4 3 2  (5 already handled; no Ace here for Hearts)
     * Black trump suits: 2 3 4 6 7 8 9 10 (low→high for numbers, 5 already handled)
     * Returns 1–11.
     */
    private function numberRankOrder(string $rank, string $trumpSuit): int
    {
        if ($trumpSuit === 'H' || $trumpSuit === 'D') {
            // Red trump number order high→low (descending): 10 9 8 7 6 4 3 2
            // (5 is top trump, A is handled elsewhere, no A for hearts in this path)
            $redOrder = ['10' => 8, '9' => 7, '8' => 6, '7' => 5, '6' => 4, '4' => 3, '3' => 2, '2' => 1];
            return $redOrder[$rank] ?? 0;
        }
        // Black trump number order low→high: 2 3 4 6 7 8 9 10
        $blackOrder = ['2' => 8, '3' => 7, '4' => 6, '6' => 5, '7' => 4, '8' => 3, '9' => 2, '10' => 1];
        return $blackOrder[$rank] ?? 0;
    }
}
