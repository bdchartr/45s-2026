<?php

declare(strict_types=1);

namespace FortyFives\Domain\AI;

final class AIResponse
{
    public function __construct(
        public readonly string $actionType,
        public readonly array $payload,
        public readonly ?string $reasoning = null
    ) {
    }
}
