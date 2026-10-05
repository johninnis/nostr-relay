<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PolicyRejectionTest extends TestCase
{
    public function testToOkMessageRefusesTheEventWithThePrefixedReason(): void
    {
        $message = PolicyRejection::blocked('spam')->toOkMessage(self::eventId());

        $this->assertSame(['OK', str_repeat('ab', 32), false, 'blocked: spam'], $message->toArray());
    }

    public function testToClosedMessageClosesTheSubscriptionWithThePrefixedReason(): void
    {
        $message = PolicyRejection::rateLimited('slow down')->toClosedMessage(SubscriptionIdMother::from('sub-1'));

        $this->assertSame(['CLOSED', 'sub-1', 'rate-limited: slow down'], $message->toArray());
    }

    private static function eventId(): EventId
    {
        return EventId::tryFromHex(str_repeat('ab', 32)) ?? throw new RuntimeException('Invalid test event id');
    }
}
