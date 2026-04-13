<?php

declare(strict_types=1);

namespace FortyFives\Infrastructure\Security;

final class RateLimiter
{
    public function __construct(private readonly string $storagePath)
    {
    }

    public static function default(): self
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '45s_rate_limit.json';
        return new self($path);
    }

    public function allow(string $key, int $maxAttempts, int $windowSeconds, ?int $now = null): bool
    {
        $now = $now ?? time();
        $state = $this->loadState();

        $entry = $state[$key] ?? ['count' => 0, 'window_start' => $now];
        $windowStart = (int) ($entry['window_start'] ?? $now);
        $count = (int) ($entry['count'] ?? 0);

        if (($now - $windowStart) >= $windowSeconds) {
            $windowStart = $now;
            $count = 0;
        }

        if ($count >= $maxAttempts) {
            $state[$key] = [
                'count' => $count,
                'window_start' => $windowStart,
            ];
            $this->saveState($state);
            return false;
        }

        $count++;
        $state[$key] = [
            'count' => $count,
            'window_start' => $windowStart,
        ];
        $this->saveState($state);

        return true;
    }

    public function retryAfterSeconds(string $key, int $windowSeconds, ?int $now = null): int
    {
        $now = $now ?? time();
        $state = $this->loadState();
        $entry = $state[$key] ?? null;
        if (!is_array($entry)) {
            return 0;
        }

        $windowStart = (int) ($entry['window_start'] ?? $now);
        $remaining = $windowSeconds - ($now - $windowStart);
        return max(0, $remaining);
    }

    private function loadState(): array
    {
        if (!file_exists($this->storagePath)) {
            return [];
        }

        $json = @file_get_contents($this->storagePath);
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveState(array $state): void
    {
        @file_put_contents($this->storagePath, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
