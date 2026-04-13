<?php

declare(strict_types=1);

namespace FortyFives\Tests\Infrastructure\Security;

use FortyFives\Infrastructure\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '45s_rate_limiter_test_' . uniqid('', true) . '.json';
        if (file_exists($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    public function testBlocksAfterLimitWithinWindow(): void
    {
        $limiter = new RateLimiter($this->stateFile);

        $this->assertTrue($limiter->allow('login:user1', 2, 60, 1000));
        $this->assertTrue($limiter->allow('login:user1', 2, 60, 1001));
        $this->assertFalse($limiter->allow('login:user1', 2, 60, 1002));
    }

    public function testWindowResetsAfterDuration(): void
    {
        $limiter = new RateLimiter($this->stateFile);

        $this->assertTrue($limiter->allow('login:user2', 1, 30, 2000));
        $this->assertFalse($limiter->allow('login:user2', 1, 30, 2001));

        $this->assertTrue($limiter->allow('login:user2', 1, 30, 2031));
    }

    public function testRetryAfterReturnsRemainingSeconds(): void
    {
        $limiter = new RateLimiter($this->stateFile);

        $this->assertTrue($limiter->allow('login:user3', 1, 20, 3000));
        $this->assertFalse($limiter->allow('login:user3', 1, 20, 3001));

        $this->assertSame(18, $limiter->retryAfterSeconds('login:user3', 20, 3002));
        $this->assertSame(0, $limiter->retryAfterSeconds('unknown', 20, 3002));
    }
}
