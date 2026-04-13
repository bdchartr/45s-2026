<?php

declare(strict_types=1);

namespace FortyFives\Domain\Rules;

use FortyFives\Domain\Game\Card;

final class CardRanker
{
    /**
     * Returns a sortable strength score where higher is stronger.
     * This is a starter implementation and should be replaced with full 45s ranking rules.
     */
    public function strength(Card $card, string $trumpSuit): int
    {
        $baseRanks = [
            '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
            '8' => 8, '9' => 9, '10' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14,
        ];

        $score = $baseRanks[$card->rank] ?? 0;

        if ($card->suit === $trumpSuit) {
            $score += 100;
        }

        if ($card->rank === 'A' && $card->suit === 'H') {
            $score += 120;
        }

        if ($card->rank === 'J' && $card->suit === $trumpSuit) {
            $score += 140;
        }

        if ($card->rank === '5' && $card->suit === $trumpSuit) {
            $score += 160;
        }

        return $score;
    }
}
