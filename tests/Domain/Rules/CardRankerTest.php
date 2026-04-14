<?php

declare(strict_types=1);

namespace FortyFives\Tests\Domain\Rules;

use FortyFives\Domain\Game\Card;
use FortyFives\Domain\Rules\CardRanker;
use PHPUnit\Framework\TestCase;

final class CardRankerTest extends TestCase
{
    private CardRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new CardRanker();
    }

    // -------------------------------------------------------------------------
    // isTrump
    // -------------------------------------------------------------------------

    public function testAceOfHeartsIsAlwaysTrump(): void
    {
        foreach (['C', 'D', 'H', 'S'] as $trump) {
            $this->assertTrue(
                $this->ranker->isTrump(new Card('H', 'A'), $trump),
                "AH should be trump when trump is {$trump}"
            );
        }
    }

    public function testTrumpSuitCardsAreTrump(): void
    {
        $this->assertTrue($this->ranker->isTrump(new Card('S', '5'), 'S'));
        $this->assertTrue($this->ranker->isTrump(new Card('S', 'J'), 'S'));
        $this->assertTrue($this->ranker->isTrump(new Card('D', 'K'), 'D'));
    }

    public function testNonTrumpSuitCardsAreNotTrump(): void
    {
        $this->assertFalse($this->ranker->isTrump(new Card('C', '5'), 'S'));
        $this->assertFalse($this->ranker->isTrump(new Card('D', 'J'), 'S'));
        $this->assertFalse($this->ranker->isTrump(new Card('H', 'K'), 'S')); // KH not trump (AH is)
    }

    // -------------------------------------------------------------------------
    // isTopTrump
    // -------------------------------------------------------------------------

    public function testTopTrumps(): void
    {
        $this->assertTrue($this->ranker->isTopTrump(new Card('H', '5'), 'H'));
        $this->assertTrue($this->ranker->isTopTrump(new Card('H', 'J'), 'H'));
        $this->assertTrue($this->ranker->isTopTrump(new Card('H', 'A'), 'H')); // AH always top trump
        $this->assertTrue($this->ranker->isTopTrump(new Card('H', 'A'), 'S')); // AH is top trump even when S is trump
        $this->assertTrue($this->ranker->isTopTrump(new Card('S', '5'), 'S'));
        $this->assertTrue($this->ranker->isTopTrump(new Card('S', 'J'), 'S'));
    }

    public function testNonTopTrumps(): void
    {
        $this->assertFalse($this->ranker->isTopTrump(new Card('H', 'K'), 'H'));
        $this->assertFalse($this->ranker->isTopTrump(new Card('S', 'K'), 'S'));
        $this->assertFalse($this->ranker->isTopTrump(new Card('S', '2'), 'S'));
    }

    // -------------------------------------------------------------------------
    // Trump ordering: 5 > J > AH > A-trump > K > Q > numbers
    // -------------------------------------------------------------------------

    public function testTrumpOrderingHearts(): void
    {
        $trump = 'H';
        $five  = $this->ranker->strength(new Card('H', '5'), $trump);
        $jack  = $this->ranker->strength(new Card('H', 'J'), $trump);
        $ah    = $this->ranker->strength(new Card('H', 'A'), $trump);
        $king  = $this->ranker->strength(new Card('H', 'K'), $trump);
        $queen = $this->ranker->strength(new Card('H', 'Q'), $trump);
        $ten   = $this->ranker->strength(new Card('H', '10'), $trump);

        $this->assertGreaterThan($jack, $five, '5H > JH');
        $this->assertGreaterThan($ah, $jack, 'JH > AH');
        $this->assertGreaterThan($king, $ah, 'AH > KH when H is trump');
        $this->assertGreaterThan($queen, $king, 'KH > QH');
        $this->assertGreaterThan($ten, $queen, 'QH > 10H');
    }

    public function testTrumpOrderingSpades(): void
    {
        $trump = 'S';
        $five  = $this->ranker->strength(new Card('S', '5'), $trump);
        $jack  = $this->ranker->strength(new Card('S', 'J'), $trump);
        $ah    = $this->ranker->strength(new Card('H', 'A'), $trump);   // AH always 3rd
        $as    = $this->ranker->strength(new Card('S', 'A'), $trump);   // A of trump suit 4th
        $king  = $this->ranker->strength(new Card('S', 'K'), $trump);

        $this->assertGreaterThan($jack, $five, '5S > JS');
        $this->assertGreaterThan($ah, $jack, 'JS > AH');
        $this->assertGreaterThan($as, $ah, 'AH > AS');
        $this->assertGreaterThan($king, $as, 'AS > KS');
    }

    public function testTrumpOrderingDiamonds(): void
    {
        $trump = 'D';
        $five  = $this->ranker->strength(new Card('D', '5'), $trump);
        $jack  = $this->ranker->strength(new Card('D', 'J'), $trump);
        $ah    = $this->ranker->strength(new Card('H', 'A'), $trump);
        $ad    = $this->ranker->strength(new Card('D', 'A'), $trump);

        $this->assertGreaterThan($jack, $five);
        $this->assertGreaterThan($ah, $jack);
        $this->assertGreaterThan($ad, $ah);
    }

    public function testBlackTrumpNumbersRankLowToHigh(): void
    {
        // When spades are trump, numbers rank 2 (low) → 10 (high)
        // Per rules: 5,J,AH,AS,K,Q then 2,3,4,6,7,8,9,10 where 2 is strongest of the numbers
        $trump = 'S';
        $two   = $this->ranker->strength(new Card('S', '2'), $trump);
        $ten   = $this->ranker->strength(new Card('S', '10'), $trump);
        $this->assertGreaterThan($ten, $two, 'For black trump, 2 ranks higher than 10 among number cards');
    }

    public function testRedTrumpNumbersRankHighToLow(): void
    {
        // When hearts are trump, 10 ranks above 2 among number cards
        $trump = 'H';
        $two   = $this->ranker->strength(new Card('H', '2'), $trump);
        $ten   = $this->ranker->strength(new Card('H', '10'), $trump);
        $this->assertGreaterThan($two, $ten, 'For red trump, 10 ranks higher than 2 among number cards');
    }

    // -------------------------------------------------------------------------
    // Non-trump suit rankings
    // -------------------------------------------------------------------------

    public function testNonTrumpRedSuitHighToLow(): void
    {
        $trump = 'S'; // so Hearts is a non-trump suit
        $king  = $this->ranker->strength(new Card('H', 'K'), $trump);
        $queen = $this->ranker->strength(new Card('H', 'Q'), $trump);
        $jack  = $this->ranker->strength(new Card('H', 'J'), $trump);
        $ten   = $this->ranker->strength(new Card('H', '10'), $trump);
        $two   = $this->ranker->strength(new Card('H', '2'), $trump);

        $this->assertGreaterThan($queen, $king);
        $this->assertGreaterThan($jack, $queen);
        $this->assertGreaterThan($ten, $jack);
        $this->assertGreaterThan($two, $ten);
    }

    public function testNonTrumpDiamondAceRanksLowest(): void
    {
        $trump = 'S';
        $two   = $this->ranker->strength(new Card('D', '2'), $trump);
        $ace   = $this->ranker->strength(new Card('D', 'A'), $trump);
        $this->assertGreaterThan($ace, $two, 'AD ranks lower than 2D when diamonds is not trump');
    }

    public function testNonTrumpBlackSuitNumbersRankLowToHigh(): void
    {
        $trump = 'H'; // so Spades is non-trump
        $two   = $this->ranker->strength(new Card('S', '2'), $trump);
        $ten   = $this->ranker->strength(new Card('S', '10'), $trump);
        // Black non-trump: K Q J A then 2(low)...10(high)
        // So 10 > 2 among number ranks
        $this->assertGreaterThan($two, $ten, 'For black non-trump, 10 ranks above 2');
    }

    public function testNonTrumpBlackSuitFaceOrder(): void
    {
        $trump = 'H';
        $king  = $this->ranker->strength(new Card('S', 'K'), $trump);
        $queen = $this->ranker->strength(new Card('S', 'Q'), $trump);
        $jack  = $this->ranker->strength(new Card('S', 'J'), $trump);
        $ace   = $this->ranker->strength(new Card('S', 'A'), $trump);
        $two   = $this->ranker->strength(new Card('S', '2'), $trump);

        $this->assertGreaterThan($queen, $king);
        $this->assertGreaterThan($jack, $queen);
        $this->assertGreaterThan($ace, $jack);
        // Numbers rank low to high, so 2 is lowest of numbers — below Ace
        $this->assertGreaterThan($two, $ace);
    }

    public function testTrumpAlwaysBeatsNonTrump(): void
    {
        // Lowest trump card should beat highest non-trump
        $trump      = 'H';
        $lowestTrump = $this->ranker->strength(new Card('H', '2'), $trump);
        $highestNonTrump = $this->ranker->strength(new Card('S', 'K'), $trump);
        $this->assertGreaterThan($highestNonTrump, $lowestTrump);
    }
}
