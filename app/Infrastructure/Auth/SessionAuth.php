<?php

declare(strict_types=1);

namespace FortyFives\Infrastructure\Auth;

final class SessionAuth
{
    private const CSRF_SESSION_KEY = 'csrf_token';

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
    }

    public function userId(): ?int
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return $_SESSION['user_id'];
    }

    public function signIn(int $userId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        unset($_SESSION[self::CSRF_SESSION_KEY]);
    }

    public function signOut(): void
    {
        unset($_SESSION['user_id']);
        unset($_SESSION[self::CSRF_SESSION_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_SESSION_KEY] = $token;
        }

        return $token;
    }

    public function isValidCsrfToken(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $expected = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }
}
