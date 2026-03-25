<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Exception;

use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RelayException;
use PHPUnit\Framework\TestCase;

final class PolicyViolationExceptionTest extends TestCase
{
    public function testExtendsRelayException(): void
    {
        $exception = new PolicyViolationException('event not allowed');

        $this->assertInstanceOf(RelayException::class, $exception);
        $this->assertSame('event not allowed', $exception->getMessage());
    }
}
