<?php

declare(strict_types=1);

namespace FortyFives\Application\Contracts;

final class ActionResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly string $code,
        public readonly string $message,
        public readonly array $statePatch = []
    ) {
    }

    public static function accepted(array $statePatch = []): self
    {
        return new self(true, 'ok', 'Action accepted.', $statePatch);
    }

    public static function rejected(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
