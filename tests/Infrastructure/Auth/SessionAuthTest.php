<?php

declare(strict_types=1);

namespace FortyFives\Tests\Infrastructure\Auth;

use FortyFives\Infrastructure\Auth\SessionAuth;
use PHPUnit\Framework\TestCase;

final class SessionAuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testCsrfTokenIsStableWithinSession(): void
    {
        $auth = new SessionAuth();

        $first = $auth->csrfToken();
        $second = $auth->csrfToken();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
        $this->assertTrue($auth->isValidCsrfToken($first));
    }

    public function testCsrfTokenIsClearedOnSignInAndSignOut(): void
    {
        $auth = new SessionAuth();

        $before = $auth->csrfToken();
        $auth->signIn(42);
        $afterSignIn = $auth->csrfToken();

        $this->assertNotSame($before, $afterSignIn);

        $auth->signOut();
        $afterSignOut = $auth->csrfToken();

        $this->assertNotSame($afterSignIn, $afterSignOut);
    }

    public function testRejectsMissingOrInvalidCsrfToken(): void
    {
        $auth = new SessionAuth();
        $valid = $auth->csrfToken();

        $this->assertFalse($auth->isValidCsrfToken(''));
        $this->assertFalse($auth->isValidCsrfToken(null));
        $this->assertFalse($auth->isValidCsrfToken('invalid'));
        $this->assertTrue($auth->isValidCsrfToken($valid));
    }
}
