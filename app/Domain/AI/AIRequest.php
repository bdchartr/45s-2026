<?php

declare(strict_types=1);

namespace FortyFives\Domain\AI;

final class AIRequest
{
    public function __construct(
        public readonly int $gameId,
        public readonly int $seat,
        public readonly string $phase,
        public readonly array $state,
        public readonly array $legalActions
    ) {
    }
}
