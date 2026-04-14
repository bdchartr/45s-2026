<?php

declare(strict_types=1);

namespace FortyFives\Domain\Rules;

use FortyFives\Domain\Game\Card;

final class TrickResolver
{
    public function __construct(private readonly CardRanker $ranker)
    {
    }

    /**
     * @param array<int, Card> $plays seat => card
     */
    public function winningSeat(array $plays, string $leadSuit, string $trumpSuit): int
    {
        $bestSeat = array_key_first($plays);
        $bestCard = $plays[$bestSeat];

        foreach ($plays as $seat => $card) {
            if ($this->beats($card, $bestCard, $leadSuit, $trumpSuit)) {
                $bestSeat = $seat;
                $bestCard = $card;
            }
        }

        return $bestSeat;
    }

    private function beats(Card $candidate, Card $currentBest, string $leadSuit, string $trumpSuit): bool
    {
        $candidateTrump = $this->ranker->isTrump($candidate, $trumpSuit);
        $bestTrump = $this->ranker->isTrump($currentBest, $trumpSuit);

        if ($candidateTrump && !$bestTrump) {
            return true;
        }
        if (!$candidateTrump && $bestTrump) {
            return false;
        }

        if (!$candidateTrump && !$bestTrump) {
            $candidateLead = $candidate->suit === $leadSuit;
            $bestLead = $currentBest->suit === $leadSuit;
            if ($candidateLead && !$bestLead) {
                return true;
            }
            if (!$candidateLead && $bestLead) {
                return false;
            }
        }

        return $this->ranker->strength($candidate, $trumpSuit) > $this->ranker->strength($currentBest, $trumpSuit);
    }
}
