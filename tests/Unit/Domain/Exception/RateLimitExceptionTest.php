<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Exception;

use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\Exception\RelayException;
use PHPUnit\Framework\TestCase;

final class RateLimitExceptionTest extends TestCase
{
    public function testExtendsRelayException(): void
    {
        $exception = new RateLimitException('test');

        $this->assertInstanceOf(RelayException::class, $exception);
    }

    public function testForKeyIncludesKeyInMessage(): void
    {
        $exception = RateLimitException::forKey('192.168.1.1');

        $this->assertStringContainsString('192.168.1.1', $exception->getMessage());
    }
}
