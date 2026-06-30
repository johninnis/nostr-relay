<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Exception;

use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RelayException;
use PHPUnit\Framework\TestCase;

final class PolicyViolationExceptionTest extends TestCase
{
    public function testIsCaughtAsRelayExceptionCarryingItsMessage(): void
    {
        $this->expectException(RelayException::class);
        $this->expectExceptionMessage('event not allowed');

        throw new PolicyViolationException('event not allowed');
    }
}
