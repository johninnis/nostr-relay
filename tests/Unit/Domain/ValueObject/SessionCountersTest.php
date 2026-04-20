<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;
use PHPUnit\Framework\TestCase;

final class SessionCountersTest extends TestCase
{
    public function testEmptyStartsAtZero(): void
    {
        $counters = SessionCounters::empty();

        $this->assertSame(0, $counters->getEventsReceived());
        $this->assertSame(0, $counters->getEventsAccepted());
        $this->assertSame(0, $counters->getEventsSent());
    }

    public function testWithEventReceivedReturnsNewInstanceWithIncrementedCounter(): void
    {
        $initial = SessionCounters::empty();

        $next = $initial->withEventReceived();

        $this->assertNotSame($initial, $next);
        $this->assertSame(0, $initial->getEventsReceived());
        $this->assertSame(1, $next->getEventsReceived());
        $this->assertSame(0, $next->getEventsAccepted());
        $this->assertSame(0, $next->getEventsSent());
    }

    public function testWithEventAcceptedOnlyIncrementsAccepted(): void
    {
        $counters = SessionCounters::empty()
            ->withEventAccepted()
            ->withEventAccepted();

        $this->assertSame(0, $counters->getEventsReceived());
        $this->assertSame(2, $counters->getEventsAccepted());
        $this->assertSame(0, $counters->getEventsSent());
    }

    public function testWithEventSentOnlyIncrementsSent(): void
    {
        $counters = SessionCounters::empty()
            ->withEventSent()
            ->withEventSent()
            ->withEventSent();

        $this->assertSame(0, $counters->getEventsReceived());
        $this->assertSame(0, $counters->getEventsAccepted());
        $this->assertSame(3, $counters->getEventsSent());
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

        $this->assertSame(2, $counters->getEventsReceived());
        $this->assertSame(1, $counters->getEventsAccepted());
        $this->assertSame(4, $counters->getEventsSent());
    }

    public function testConstructorPreservesProvidedValues(): void
    {
        $counters = new SessionCounters(5, 3, 7);

        $this->assertSame(5, $counters->getEventsReceived());
        $this->assertSame(3, $counters->getEventsAccepted());
        $this->assertSame(7, $counters->getEventsSent());
    }
}
