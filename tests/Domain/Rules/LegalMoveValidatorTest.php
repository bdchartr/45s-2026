<?php

declare(strict_types=1);

namespace FortyFives\Tests\Domain\Rules;

use FortyFives\Domain\Game\Card;
use FortyFives\Domain\Rules\CardRanker;
use FortyFives\Domain\Rules\LegalMoveValidator;
use PHPUnit\Framework\TestCase;

final class LegalMoveValidatorTest extends TestCase
{
    private LegalMoveValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LegalMoveValidator(new CardRanker());
    }

    private function cards(string ...$codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $suit = substr($code, -1);
            $rank = substr($code, 0, -1);
            $result[] = new Card($suit, $rank);
        }
        return $result;
    }

    private function card(string $code): Card
    {
        return new Card(substr($code, -1), substr($code, 0, -1));
    }

    // -------------------------------------------------------------------------
    // Leading (no lead suit) — non-trump always legal; trump only when broken
    // or when the hand contains nothing but trump.
    // -------------------------------------------------------------------------

    public function testLeadingNonTrumpIsLegal(): void
    {
        $hand = $this->cards('2C', 'KH', '5S', 'AD', '7H');
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('2C'), null, 'H'));
    }

    public function testLeadingTrumpIsIllegalBeforeTrumpIsBroken(): void
    {
        $hand = $this->cards('2C', 'KH', '5S', 'AD', '7H'); // mixed, trump = hearts
        $this->assertFalse(
            $this->validator->canPlayCard($hand, $this->card('KH'), null, 'H', null, false)
        );
    }

    public function testLeadingTrumpIsLegalOnceTrumpIsBroken(): void
    {
        $hand = $this->cards('2C', 'KH', '5S', 'AD', '7H');
        $this->assertTrue(
            $this->validator->canPlayCard($hand, $this->card('KH'), null, 'H', null, true)
        );
    }

    public function testLeadingTrumpIsLegalWhenHandIsAllTrump(): void
    {
        // Hand is K, 7 of hearts (both trump) plus AH. All trump.
        $hand = $this->cards('KH', '7H', 'AH');
        $this->assertTrue(
            $this->validator->canPlayCard($hand, $this->card('KH'), null, 'H', null, false)
        );
    }

    public function testAceOfHeartsCountsAsTrumpInAllTrumpExceptionWhenSpadesAreTrump(): void
    {
        // Spades is trump. Hand is 5S, JS, AH — all trump because A♥ is
        // always trump. Player must be allowed to lead any of them even
        // though trump hasn't been broken.
        $hand = $this->cards('5S', 'JS', 'AH');
        $this->assertTrue(
            $this->validator->canPlayCard($hand, $this->card('AH'), null, 'S', null, false)
        );
        $this->assertTrue(
            $this->validator->canPlayCard($hand, $this->card('5S'), null, 'S', null, false)
        );
    }

    public function testAceOfHeartsLeadIsIllegalBeforeBrokenWhenOtherSuitsHeld(): void
    {
        // Spades is trump. AH is trump (always). Hand also has clubs, so
        // leading AH is leading trump and must be rejected pre-break.
        $hand = $this->cards('AH', '2C', '7D');
        $this->assertFalse(
            $this->validator->canPlayCard($hand, $this->card('AH'), null, 'S', null, false)
        );
    }

    // -------------------------------------------------------------------------
    // Non-trump led, player has led suit → must follow
    // -------------------------------------------------------------------------

    public function testMustFollowNonTrumpLedSuit(): void
    {
        $hand = $this->cards('2C', '5C', 'KH'); // has clubs
        // Must play a club when clubs led (trump = H)
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('2C'), 'C', 'H'));
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('5C'), 'C', 'H'));
        // KH is trump (trump=H), so playing trump is always legal even with clubs in hand
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('KH'), 'C', 'H'));
    }

    // -------------------------------------------------------------------------
    // Trump can always be played instead of following non-trump led suit
    // -------------------------------------------------------------------------

    public function testTrumpCanAlwaysBePlayedInsteadOfFollowingSuit(): void
    {
        // Trump = H; clubs led; player has clubs but also trump
        $hand = $this->cards('2C', '5C', 'KH');
        // Playing KH (trump) is legal even though player has clubs
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('KH'), 'C', 'H'));
    }

    // -------------------------------------------------------------------------
    // No led suit in hand → play anything
    // -------------------------------------------------------------------------

    public function testNoLedSuitInHandCanPlayAnything(): void
    {
        // Trump = H; diamonds led; player has no diamonds
        $hand = $this->cards('2C', '5S', 'KH');
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('2C'), 'D', 'H'));
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('KH'), 'D', 'H'));
    }

    // -------------------------------------------------------------------------
    // Trump led → must follow trump (if any lower trump available)
    // -------------------------------------------------------------------------

    public function testMustFollowTrumpWhenTrumpLed(): void
    {
        // Trump = H; Hearts led; player has lower trump (2H) and non-trump
        $hand = $this->cards('2H', 'KC', '3D');
        // Must play 2H (lower trump); cannot play KC or 3D
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('2H'), 'H', 'H', '9H'));
        $this->assertFalse($this->validator->canPlayCard($hand, $this->card('KC'), 'H', 'H', '9H'));
        $this->assertFalse($this->validator->canPlayCard($hand, $this->card('3D'), 'H', 'H', '9H'));
    }

    // -------------------------------------------------------------------------
    // Top-trump no-force-out rule
    // -------------------------------------------------------------------------

    public function testTopTrumpsCannotBeForcedByLowerTrumpLead(): void
    {
        // Trump = H; 9H led (lower trump); player has only AH (top trump) and no other trump
        $hand = $this->cards('AH', 'KC', '3D');
        // AH cannot be forced out by 9H lead — may withhold
        // Player has no lower trump, so non-trump is legal
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('KC'), 'H', 'H', '9H'));
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('3D'), 'H', 'H', '9H'));
        // Player may also voluntarily play AH
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('AH'), 'H', 'H', '9H'));
    }

    public function testTopTrumpCannotWithholdIfLowerTrumpAvailable(): void
    {
        // Trump = H; 9H led; player has 5H (top trump) AND 2H (lower trump)
        // Must play 2H (or any trump) — has lower trump available
        $hand = $this->cards('5H', '2H', 'KC');
        // Lower trump (2H) must be played — so non-trump is not legal
        $this->assertFalse($this->validator->canPlayCard($hand, $this->card('KC'), 'H', 'H', '9H'));
        // Voluntarily playing 5H is fine (top trump can always be played)
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('5H'), 'H', 'H', '9H'));
        // Playing lower trump is fine
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('2H'), 'H', 'H', '9H'));
    }

    public function testAceOfHeartsTopTrumpExemptionWithNonHeartsTrump(): void
    {
        // Trump = S; spades led with lower spade; player has only AH and non-trump
        // AH is a top trump even when S is trump — cannot be forced
        $hand = $this->cards('AH', '3D', 'KC');
        // No lower spade trump → non-trump is legal (AH withheld)
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('3D'), 'S', 'S', '9S'));
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('AH'), 'S', 'S', '9S'));
    }

    public function testAceOfHeartsAlwaysTrumpWhenHeartsNotTrump(): void
    {
        // Trump = S; clubs led; player has AH (trump) and clubs
        // Playing AH (trump) is legal even though clubs are led
        $hand = $this->cards('AH', '2C', 'KC');
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('AH'), 'C', 'S'));
    }

    // -------------------------------------------------------------------------
    // Edge: Ace of Hearts when Hearts is trump suit
    // -------------------------------------------------------------------------

    public function testAceOfHeartsCountedAsTrumpWhenHeartsTrump(): void
    {
        // Trump = H; non-trump suit led; AH is trump so can renegue
        $hand = $this->cards('AH', '2C', '3D');
        // Clubs led; AH is trump → legal to play AH instead of following clubs
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('AH'), 'C', 'H'));
        // Must play a club (or trump); playing 3D (non-trump, non-led) is not legal while holding clubs
        $this->assertFalse($this->validator->canPlayCard($hand, $this->card('3D'), 'C', 'H'));
        // 2C is a club → legal to follow suit
        $this->assertTrue($this->validator->canPlayCard($hand, $this->card('2C'), 'C', 'H'));
    }
}
