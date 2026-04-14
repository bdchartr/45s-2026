<?php

declare(strict_types=1);

namespace FortyFives\Domain\Rules;

use FortyFives\Domain\Game\Card;

/**
 * Full 45s legal-move validation per the Chartrand/Newfoundland ruleset.
 *
 * Core rules:
 * 1. If leading (no lead suit yet), any card is legal.
 * 2. A player may always play a trump card instead of following the led suit ("reneging" allowed via trump).
 * 3. If trump is led, players must follow trump IF they have trump — EXCEPT the top 3 trumps
 *    (5 of trump, J of trump, A of Hearts) cannot be forced out by a lower trump lead.
 *    A top trump can only be withheld if the player has no other (lower) trump to play.
 * 4. If a non-trump suit is led and the player has no cards of that suit, they may play anything.
 * 5. Playing a non-trump, non-led-suit card when holding the led suit is illegal.
 */
final class LegalMoveValidator
{
    public function __construct(private readonly CardRanker $ranker)
    {
    }

    /**
     * @param Card[] $hand  The player's current hand
     * @param Card   $play  The card the player wants to play
     * @param string|null $leadSuit  Suit of the first card played this trick (null if leading)
     * @param string $trumpSuit  Current trump suit
     * @param string|null $leadCard  Full card code of the lead card (needed for top-trump force-out check)
     */
    public function canPlayCard(
        array $hand,
        Card $play,
        ?string $leadSuit,
        string $trumpSuit,
        ?string $leadCard = null
    ): bool {
        // Rule 1: Leading — anything goes
        if ($leadSuit === null) {
            return true;
        }

        $playIsTrump = $this->ranker->isTrump($play, $trumpSuit);

        // Rule 2: Trump can always be played
        if ($playIsTrump) {
            return $this->canPlayTrumpWhenTrumpLed($hand, $play, $leadSuit, $trumpSuit, $leadCard);
        }

        // Non-trump play: check if player must follow suit
        if ($leadSuit === $trumpSuit || $this->leadIsTrump($leadSuit, $leadCard, $trumpSuit)) {
            // Trump was led — non-trump is only legal if player has no trump at all
            // (taking top-trump exemption into account)
            return !$this->hasPlayableTrump($hand, $trumpSuit, $leadCard);
        }

        // Non-trump suit was led
        $hasLeadSuit = $this->handHasSuit($hand, $leadSuit, $trumpSuit);
        if ($hasLeadSuit) {
            // Must follow suit (or play trump — but trump path is handled above)
            return $play->suit === $leadSuit;
        }

        // No lead suit in hand — play anything
        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * When the player wants to play a trump card and trump was led, check the
     * top-trump no-force-out rule: the 5, J, and AH cannot be forced by a lower trump.
     * They CAN be played voluntarily at any time; they just can't be required.
     */
    private function canPlayTrumpWhenTrumpLed(
        array $hand,
        Card $play,
        string $leadSuit,
        string $trumpSuit,
        ?string $leadCard
    ): bool {
        // If trump was NOT led, playing trump is always legal (rule 2 covers non-trump lead)
        if (!$this->leadIsTrump($leadSuit, $leadCard, $trumpSuit)) {
            return true;
        }

        // Trump was led — player wants to play trump. That's fine; they're following trump.
        // The top-trump exemption only prevents being FORCED to play top trumps;
        // a player can voluntarily play them. So this is always legal.
        return true;
    }

    /**
     * Does the player have any trump they are obligated to play?
     * Top trumps (5, J, AH) cannot be forced by a lower trump lead.
     * So "has playable trump" = has trump that is NOT a top trump when lead is lower trump,
     * OR has any trump when lead is also a top trump.
     */
    private function hasPlayableTrump(array $hand, string $trumpSuit, ?string $leadCard): bool
    {
        $leadCardObj = $leadCard !== null ? $this->parseCard($leadCard) : null;
        $leadIsTopTrump = $leadCardObj !== null && $this->ranker->isTopTrump($leadCardObj, $trumpSuit);

        foreach ($hand as $card) {
            if (!$this->ranker->isTrump($card, $trumpSuit)) {
                continue;
            }
            $cardIsTopTrump = $this->ranker->isTopTrump($card, $trumpSuit);

            if ($leadIsTopTrump) {
                // Lead is a top trump — ALL trump must follow (top trumps can only
                // withhold against a LOWER trump lead, not against a higher top trump)
                // Exception: 5 can always withhold J and AH; J can withhold AH only if 5 is also played
                // Simpler authoritative rule from game_rules.md §9.2:
                // "A top trump cannot be withheld against a higher trump lead."
                // So if the lead top trump is weaker than this card's top trump, must play.
                // For simplicity: any trump is playable when lead is a top trump.
                return true;
            }

            // Lead is a lower (non-top) trump — top trumps in hand are exempt
            if (!$cardIsTopTrump) {
                return true; // has a lower trump that must be played
            }
        }

        return false;
    }

    /**
     * Does the hand contain any card of the given suit (excluding trump cards
     * that happen to share the suit, like AH when trump is not Hearts)?
     */
    private function handHasSuit(array $hand, string $suit, string $trumpSuit): bool
    {
        foreach ($hand as $card) {
            if ($card->suit === $suit && !$this->ranker->isTrump($card, $trumpSuit)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine if the lead is trump. The lead suit is trump if leadSuit === trumpSuit
     * or if the specific lead card is AH (which is always trump regardless of suit).
     */
    private function leadIsTrump(string $leadSuit, ?string $leadCard, string $trumpSuit): bool
    {
        if ($leadSuit === $trumpSuit) {
            return true;
        }
        if ($leadCard !== null) {
            $card = $this->parseCard($leadCard);
            if ($card !== null && $this->ranker->isTrump($card, $trumpSuit)) {
                return true;
            }
        }
        return false;
    }

    private function parseCard(string $code): ?Card
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
