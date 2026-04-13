<?php

declare(strict_types=1);

namespace FortyFives\Domain\AI;

interface MoveProviderInterface
{
    public function choose(AIRequest $request): AIResponse;
}
