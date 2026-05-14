<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\InvalidTransitionException;
use PHPUnit\Framework\TestCase;

class InvalidTransitionExceptionTest extends TestCase
{
    public function test_extends_domain_exception(): void
    {
        $this->assertInstanceOf(
            \DomainException::class,
            new InvalidTransitionException('test'),
        );
    }

    public function test_carries_message_through_constructor(): void
    {
        $e = new InvalidTransitionException('cannot transition ready -> processing');

        $this->assertSame('cannot transition ready -> processing', $e->getMessage());
    }
}
