<?php

namespace EasyAudit\Tests\Exception;

use EasyAudit\Exception\RateLimitedException;
use PHPUnit\Framework\TestCase;

class RateLimitedExceptionTest extends TestCase
{
    public function testDefaultMessageWithRetryAfter(): void
    {
        $exception = new RateLimitedException(60);

        $this->assertEquals('Rate limited. Please try again in 60 seconds.', $exception->getMessage());
    }

    public function testDefaultMessageWithoutRetryAfter(): void
    {
        $exception = new RateLimitedException(null);

        $this->assertEquals('Rate limited. Please try again later.', $exception->getMessage());
    }

    public function testDefaultMessageWithNoArguments(): void
    {
        $exception = new RateLimitedException();

        $this->assertEquals('Rate limited. Please try again later.', $exception->getMessage());
    }

    public function testCustomMessageOverridesDefault(): void
    {
        $customMessage = 'Custom rate limit message';
        $exception = new RateLimitedException(30, $customMessage);

        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testCustomMessageWithNullRetryAfter(): void
    {
        $customMessage = 'API quota exceeded';
        $exception = new RateLimitedException(null, $customMessage);

        $this->assertEquals($customMessage, $exception->getMessage());
    }

    public function testGetRetryAfterReturnsValue(): void
    {
        $exception = new RateLimitedException(120);

        $this->assertEquals(120, $exception->getRetryAfter());
    }

    public function testGetRetryAfterReturnsNullWhenNotSet(): void
    {
        $exception = new RateLimitedException(null);

        $this->assertNull($exception->getRetryAfter());
    }

    public function testGetRetryAfterReturnsNullByDefault(): void
    {
        $exception = new RateLimitedException();

        $this->assertNull($exception->getRetryAfter());
    }

    public function testExtendsRuntimeException(): void
    {
        $exception = new RateLimitedException();

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(\Throwable::class, $exception);
    }

    public function testRetryAfterWithZeroValue(): void
    {
        $exception = new RateLimitedException(0);

        $this->assertEquals(0, $exception->getRetryAfter());
        $this->assertEquals('Rate limited. Please try again in 0 seconds.', $exception->getMessage());
    }

    public function testRetryAfterWithLargeValue(): void
    {
        $exception = new RateLimitedException(3600);

        $this->assertEquals(3600, $exception->getRetryAfter());
        $this->assertEquals('Rate limited. Please try again in 3600 seconds.', $exception->getMessage());
    }

    public function testEmptyMessageUsesDefault(): void
    {
        $exception = new RateLimitedException(45, '');

        $this->assertEquals('Rate limited. Please try again in 45 seconds.', $exception->getMessage());
    }
}
