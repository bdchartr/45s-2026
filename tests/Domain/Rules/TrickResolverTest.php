<?php

declare(strict_types=1);

namespace FortyFives\Tests\Domain\Rules;

use FortyFives\Domain\Game\Card;
use FortyFives\Domain\Rules\CardRanker;
use FortyFives\Domain\Rules\TrickResolver;
use PHPUnit\Framework\TestCase;

final class TrickResolverTest extends TestCase
{
    private TrickResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TrickResolver(new CardRanker());
    }

    private function plays(array $seatCards): array
    {
        $result = [];
        foreach ($seatCards as $seat => $code) {
            $result[$seat] = new Card(substr($code, -1), substr($code, 0, -1));
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Trump wins over all non-trump
    // -------------------------------------------------------------------------

    public function testTrumpBeatsNonTrump(): void
    {
        // Seat 0 leads AS (spades, non-trump), seat 1 plays 2H (trump)
        $plays = $this->plays([0 => 'AS', 1 => '2H', 2 => 'KS', 3 => 'QS']);
        $winner = $this->resolver->winningSeat($plays, 'S', 'H');
        $this->assertSame(1, $winner, 'Seat 1 wins with lowest trump vs non-trump leads');
    }

    // -------------------------------------------------------------------------
    // Highest trump wins when multiple trumps played
    // -------------------------------------------------------------------------

    public function testHighestTrumpWins(): void
    {
        $plays = $this->plays([0 => 'KH', 1 => 'AH', 2 => 'JH', 3 => '5H']);
        // Trump = H; 5H > JH > AH > KH
        $winner = $this->resolver->winningSeat($plays, 'H', 'H');
        $this->assertSame(3, $winner, '5H is the highest trump');
    }

    public function testJackOfTrumpBeatsAceOfHearts(): void
    {
        $plays = $this->plays([0 => 'JH', 1 => 'AH', 2 => '2C', 3 => '3D']);
        $winner = $this->resolver->winningSeat($plays, 'H', 'H');
        $this->assertSame(0, $winner, 'JH > AH');
    }

    // -------------------------------------------------------------------------
    // Ace of Hearts beats regular trump
    // -------------------------------------------------------------------------

    public function testAceOfHeartsBeatsRegularTrumpWhenSpadesTrump(): void
    {
        // Trump = S; AH is 3rd-highest trump
        $plays = $this->plays([0 => 'AS', 1 => 'AH', 2 => 'KS', 3 => '2S']);
        $winner = $this->resolver->winningSeat($plays, 'S', 'S');
        // JS > AH > AS > KS, but no JS here; AH beats AS
        $this->assertSame(1, $winner, 'AH beats AS when S is trump');
    }

    // -------------------------------------------------------------------------
    // Lead suit wins when no trump played
    // -------------------------------------------------------------------------

    public function testLeadSuitWinsWhenNoTrumpPlayed(): void
    {
        // Trump = H; Spades led; no hearts played
        $plays = $this->plays([0 => 'AS', 1 => 'KS', 2 => '2C', 3 => '5D']);
        $winner = $this->resolver->winningSeat($plays, 'S', 'H');
        // Black non-trump: K Q J A then 2..10; AS has strength of A=10, KS=13... wait
        // Non-trump black: K=13, Q=12, J=11, A=10, then 2(low)..10(high)
        // So KS (13) > AS (10)
        $this->assertSame(1, $winner, 'KS beats AS in non-trump spades (black suit ranking)');
    }

    public function testOffSuitNonTrumpNeverWins(): void
    {
        // Trump = H; Spades led; seat 2 plays KC (clubs, off-suit non-trump)
        $plays = $this->plays([0 => '2S', 1 => '3S', 2 => 'KC', 3 => 'QS']);
        $winner = $this->resolver->winningSeat($plays, 'S', 'H');
        // KC can't win — off-suit non-trump
        // Black S non-trump: K=13,Q=12,J=11,A=10; 3S=2, 2S=1, QS=12
        // QS=12 beats 3S=2 beats 2S=1
        $this->assertSame(3, $winner, 'QS wins over 3S and 2S; KC is off-suit and cannot win');
    }

    // -------------------------------------------------------------------------
    // 45s black-trump number ranking
    // -------------------------------------------------------------------------

    public function testBlackTrumpTwoBeatsBlackTrumpTen(): void
    {
        // Trump = S; both cards are trump spades
        $plays = $this->plays([0 => '2S', 1 => '10S', 2 => '3C', 3 => '4D']);
        $winner = $this->resolver->winningSeat($plays, 'S', 'S');
        // 2S > 10S for black trump number cards
        $this->assertSame(0, $winner, '2S beats 10S in black trump ranking');
    }

    // -------------------------------------------------------------------------
    // Full trick: mix of trump, lead-suit, off-suit
    // -------------------------------------------------------------------------

    public function testFullTrickWithMixedCards(): void
    {
        // Trump = D; seat 0 leads 5D (highest trump), should win
        $plays = $this->plays([0 => '5D', 1 => 'JD', 2 => 'AH', 3 => 'AD']);
        $winner = $this->resolver->winningSeat($plays, 'D', 'D');
        $this->assertSame(0, $winner, '5D is the highest trump, wins');
    }
}
