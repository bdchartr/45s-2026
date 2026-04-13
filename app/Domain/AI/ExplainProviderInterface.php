<?php

declare(strict_types=1);

namespace FortyFives\Domain\AI;

interface ExplainProviderInterface
{
    public function explain(AIRequest $request, AIResponse $response): string;
}
