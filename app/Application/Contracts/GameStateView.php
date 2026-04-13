<?php

declare(strict_types=1);

namespace FortyFives\Application\Contracts;

final class GameStateView
{
    public function __construct(
        public readonly int $gameId,
        public readonly string $phase,
        public readonly int $currentTurnSeat,
        public readonly int $handNumber,
        public readonly array $publicState,
        public readonly ?array $seatPrivateState = null
    ) {
    }
}
