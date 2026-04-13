<?php

declare(strict_types=1);

namespace FortyFives\Infrastructure\AI;

use FortyFives\Domain\AI\AIRequest;
use FortyFives\Domain\AI\AIResponse;
use FortyFives\Domain\AI\MoveProviderInterface;

final class AlgorithmicMoveProvider implements MoveProviderInterface
{
    public function choose(AIRequest $request): AIResponse
    {
        // Starter heuristic: pick first legal action.
        $action = $request->legalActions[0] ?? ['actionType' => 'pass', 'payload' => []];

        return new AIResponse(
            (string) ($action['actionType'] ?? 'pass'),
            (array) ($action['payload'] ?? []),
            'Selected first available legal action (starter heuristic).'
        );
    }
}
