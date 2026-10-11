<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;
use PHPUnit\Framework\TestCase;

final class SessionCountersTest extends TestCase
{
    public function testEmptyStartsAtZero(): void
    {
        $this->assertEquals(new SessionCounters(0, 0, 0), SessionCounters::empty());
    }

    public function testWithEventReceivedReturnsANewInstance(): void
    {
        $initial = SessionCounters::empty();

        $this->assertNotSame($initial, $initial->withEventReceived());
    }

    public function testWithEventReceivedLeavesTheOriginalUnchanged(): void
    {
        $initial = SessionCounters::empty();
        $initial->withEventReceived();

        $this->assertSame(0, $initial->getEventsReceived());
    }

    public function testWithEventReceivedOnlyIncrementsReceived(): void
    {
        $this->assertEquals(new SessionCounters(1, 0, 0), SessionCounters::empty()->withEventReceived());
    }

    public function testWithEventAcceptedOnlyIncrementsAccepted(): void
    {
        $counters = SessionCounters::empty()
            ->withEventAccepted()
            ->withEventAccepted();

        $this->assertEquals(new SessionCounters(0, 2, 0), $counters);
    }

    public function testWithEventSentOnlyIncrementsSent(): void
    {
        $counters = SessionCounters::empty()
            ->withEventSent()
            ->withEventSent()
            ->withEventSent();

        $this->assertEquals(new SessionCounters(0, 0, 3), $counters);
    }

    public function testCountersComposeIndependently(): void
    {
        $counters = SessionCounters::empty()
            ->withEventReceived()
            ->withEventReceived()
            ->withEventAccepted()
            ->withEventSent()
            ->withEventSent()
            ->withEventSent()
            ->withEventSent();

        $this->assertEquals(new SessionCounters(2, 1, 4), $counters);
    }

    public function testConstructorPreservesEventsReceived(): void
    {
        $this->assertSame(5, new SessionCounters(5, 3, 7)->getEventsReceived());
    }

    public function testConstructorPreservesEventsAccepted(): void
    {
        $this->assertSame(3, new SessionCounters(5, 3, 7)->getEventsAccepted());
    }

    public function testConstructorPreservesEventsSent(): void
    {
        $this->assertSame(7, new SessionCounters(5, 3, 7)->getEventsSent());
    }
}
