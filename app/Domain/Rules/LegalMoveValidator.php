<?php

declare(strict_types=1);

namespace FortyFives\Domain\Rules;

use FortyFives\Domain\Game\Card;

final class LegalMoveValidator
{
    /**
     * Starter legal-check: if player has lead suit they must follow suit.
     * 45s top-trump exceptions can be layered in here in the next slice.
     *
     * @param Card[] $hand
     */
    public function canPlayCard(array $hand, Card $play, ?string $leadSuit, string $trumpSuit): bool
    {
        if ($leadSuit === null) {
            return true;
        }

        $hasLeadSuit = false;
        foreach ($hand as $card) {
            if ($card->suit === $leadSuit) {
                $hasLeadSuit = true;
                break;
            }
        }

        if (!$hasLeadSuit) {
            return true;
        }

        return $play->suit === $leadSuit;
    }
}
