<?php

declare(strict_types=1);

namespace FortyFives\Domain\Game;

final class Card
{
    public function __construct(
        public readonly string $suit,
        public readonly string $rank
    ) {
    }

    public function code(): string
    {
        return $this->rank . $this->suit;
    }
}
