<?php

declare(strict_types=1);

namespace FortyFives\Tests\Infrastructure\AI;

use FortyFives\Domain\AI\AIRequest;
use FortyFives\Infrastructure\AI\AlgorithmicMoveProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AlgorithmicMoveProvider.
 *
 * Covers the four action phases: bidding, trump declaration, discard, and
 * trick play.  No database involved — the provider is purely functional.
 */
final class AlgorithmicMoveProviderTest extends TestCase
{
    private AlgorithmicMoveProvider $ai;

    protected function setUp(): void
    {
        $this->ai = new AlgorithmicMoveProvider();
    }

    private function req(string $phase, array $state): AIRequest
    {
        return new AIRequest(1, 0, $phase, $state, []);
    }

    // =========================================================================
    // Bidding
    // =========================================================================

    public function testBidsFiveTimesLargestSuitCount(): void
    {
        // 3 Hearts → 3 × 5 = 15
        $resp = $this->ai->choose($this->req('bidding', [
            'hand_cards'  => ['5H', '10H', 'JH', '3C', '2C'],
            'highest_bid' => 0,
        ]));

        $this->assertSame('submit_bid', $resp->actionType);
        $this->assertSame(15, $resp->payload['bid']);
    }

    public function testBidsTwentForFourCardSuit(): void
    {
        // 4 Spades → 4 × 5 = 20
        $resp = $this->ai->choose($this->req('bidding', [
            'hand_cards'  => ['2S', '3S', '4S', '6S', '7H'],
            'highest_bid' => 0,
        ]));

        $this->assertSame(20, $resp->payload['bid']);
    }

    public function testNonDealerPassesWhenBidEqualsCurrentHigh(): void
    {
        // Non-dealer: 3 Spades → 15, but current highest is already 15 — must exceed, so pass.
        $resp = $this->ai->choose($this->req('bidding', [
            'hand_cards'  => ['2S', '3S', '4S', '2H', '3H'],
            'highest_bid' => 15,
            'dealer_seat' => 3,  // seat 0 (default in req()) is NOT the dealer
        ]));

        $this->assertSame('pass', $resp->payload['bid']);
    }

    public function testDealerStealsWhenComputedBidMatchesCurrentHigh(): void
    {
        // Dealer: 3 Spades → 15, current highest is 15 — dealer may match to steal.
        $resp = $this->ai->choose(new AIRequest(1, 3, 'bidding', [
            'hand_cards'  => ['2S', '3S', '4S', '2H', '3H'],
            'highest_bid' => 15,
            'dealer_seat' => 3,
        ], []));

        $this->assertSame(15, $resp->payload['bid']);
    }

    public function testDealerPassesWhenComputedBidBelowCurrentHigh(): void
    {
        // Dealer: 3 Spades → 15, but current highest is 20 — even the dealer cannot go below.
        $resp = $this->ai->choose(new AIRequest(1, 3, 'bidding', [
            'hand_cards'  => ['2S', '3S', '4S', '2H', '3H'],
            'highest_bid' => 20,
            'dealer_seat' => 3,
        ], []));

        $this->assertSame('pass', $resp->payload['bid']);
    }

    public function testPassesWhenComputedBidNotALegalValue(): void
    {
        // 2 cards in every suit → max count = 2 → 2 × 5 = 10 (not a legal bid)
        $resp = $this->ai->choose($this->req('bidding', [
            'hand_cards'  => ['2S', '3S', '2H', '3H', '2C'],
            'highest_bid' => 0,
        ]));

        $this->assertSame('pass', $resp->payload['bid']);
    }

    // =========================================================================
    // Trump declaration
    // =========================================================================

    public function testDeclaresLongestSuit(): void
    {
        // 3 Hearts, 2 Spades → declare H
        $resp = $this->ai->choose($this->req('declare_trump', [
            'hand_cards' => ['5H', '10H', 'JH', '3S', '2S'],
        ]));

        $this->assertSame('declare_trump', $resp->actionType);
        $this->assertSame('H', $resp->payload['trump']);
    }

    // =========================================================================
    // Discard phase
    // =========================================================================

    public function testDiscardsWeakestCardsToReachFive(): void
    {
        // Bid winner with 8 cards (after receiving 3-card kitty); trump = S
        // Should discard exactly 3 weakest non-trump cards
        $resp = $this->ai->choose($this->req('discard_phase', [
            'hand_cards'   => ['5S', 'JS', 'AS', 'KS', 'QS', '2C', '3D', '4H'],
            'trump_suit'   => 'S',
            'is_bid_winner' => true,
        ]));

        $this->assertSame('discard_cards', $resp->actionType);
        $discards = $resp->payload['cards'];
        $this->assertCount(3, $discards);
        // The 3 non-trump cards (2C, 3D, 4H) should be discarded
        $this->assertContains('2C', $discards);
        $this->assertContains('3D', $discards);
        $this->assertContains('4H', $discards);
    }

    public function testNonBidWinnerDiscards(): void
    {
        // Regular player with 5 cards — should discard 0
        $resp = $this->ai->choose($this->req('discard_phase', [
            'hand_cards'   => ['5H', '10H', 'JH', '3C', '2C'],
            'trump_suit'   => 'H',
            'is_bid_winner' => false,
        ]));

        $this->assertSame([], $resp->payload['cards']);
    }

    // =========================================================================
    // Trick play
    // =========================================================================

    public function testPlaysHighestLegalCardWhenLeadingAfterTrumpBroken(): void
    {
        // Leading (no lead suit) once trump has been broken — should play
        // highest card: 5H is top trump when trump=H.
        $resp = $this->ai->choose($this->req('trick_play', [
            'hand_cards'   => ['5H', '2C', '3D'],
            'trump_suit'   => 'H',
            'lead_suit'    => null,
            'lead_card'    => null,
            'trump_broken' => true,
        ]));

        $this->assertSame('play_card', $resp->actionType);
        $this->assertSame('5H', $resp->payload['card']);
    }

    public function testDoesNotLeadTrumpBeforeItIsBroken(): void
    {
        // Leading with trump unbroken and a non-trump available — must pick
        // a non-trump even though the trump (5H) would otherwise be the
        // strongest card. Highest non-trump in red suits is the AD (aces
        // rank highest).
        $resp = $this->ai->choose($this->req('trick_play', [
            'hand_cards'   => ['5H', '2C', 'AD'],
            'trump_suit'   => 'H',
            'lead_suit'    => null,
            'lead_card'    => null,
            'trump_broken' => false,
        ]));

        $this->assertSame('play_card', $resp->actionType);
        $this->assertNotSame('5H', $resp->payload['card']);
    }

    public function testLeadsTrumpWhenHandIsAllTrumpEvenIfNotBroken(): void
    {
        // Only trump in hand — must be allowed to lead trump.
        $resp = $this->ai->choose($this->req('trick_play', [
            'hand_cards'   => ['5H', 'AH', 'JH'],
            'trump_suit'   => 'H',
            'lead_suit'    => null,
            'lead_card'    => null,
            'trump_broken' => false,
        ]));

        $this->assertSame('play_card', $resp->actionType);
        $this->assertContains($resp->payload['card'], ['5H', 'AH', 'JH']);
    }

    public function testPlaysHighestLegalCardFollowingSuit(): void
    {
        // Clubs led, hand has only non-trump clubs — must follow suit.
        // Black suit ranking (non-trump): 3C > 2C (numbers rank low-to-high for black).
        $resp = $this->ai->choose($this->req('trick_play', [
            'hand_cards' => ['2C', '3C', '4D'],
            'trump_suit' => 'H',
            'lead_suit'  => 'C',
            'lead_card'  => 'KC',
        ]));

        $this->assertSame('play_card', $resp->actionType);
        // 4D is off-suit non-trump (illegal while clubs are held); 3C > 2C in black rank
        $this->assertSame('3C', $resp->payload['card']);
    }
}
