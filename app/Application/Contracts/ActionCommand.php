<?php

declare(strict_types=1);

namespace FortyFives\Application\Contracts;

final class ActionCommand
{
    public function __construct(
        public readonly int $gameId,
        public readonly int $seat,
        public readonly string $actionType,
        public readonly array $payload = []
    ) {
    }
}
