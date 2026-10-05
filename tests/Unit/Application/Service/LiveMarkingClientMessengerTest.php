<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\LiveMarkingClientMessenger;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientMessenger;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LiveMarkingClientMessengerTest extends TestCase
{
    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private RecordingClientMessenger $inner;
    private LiveMarkingClientMessenger $messenger;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($this->createStub(MetricsCollectorInterface::class), new NullLogger());
        $this->inner = new RecordingClientMessenger();
        $this->messenger = new LiveMarkingClientMessenger($this->inner, $this->subscriptionRegistry);
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );

        $subscription = Subscription::create(
            SubscriptionIdMother::from('sub-1'),
            new FilterCollection([Filter::from()]),
            SubscriptionState::Active,
        );
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription);
    }

    public function testAnEoseMarksTheSubscriptionLiveAfterSending(): void
    {
        $this->messenger->send($this->client, new EoseMessage(SubscriptionIdMother::from('sub-1')));

        $this->assertSame(SubscriptionState::Live, $this->stateOf('sub-1'));
    }

    public function testAnEoseIsSentThroughToTheClient(): void
    {
        $this->messenger->send($this->client, new EoseMessage(SubscriptionIdMother::from('sub-1')));

        $this->assertSame(['["EOSE","sub-1"]'], $this->inner->frames());
    }

    public function testANonEoseMessageLeavesTheSubscriptionStateUntouched(): void
    {
        $this->messenger->send($this->client, NoticeMessage::fromString('hello'));

        $this->assertSame(SubscriptionState::Active, $this->stateOf('sub-1'));
    }

    public function testSendEventPassesThroughToTheInnerMessenger(): void
    {
        $encoded = EncodedEvent::of(EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection(),
        )));

        $this->messenger->sendEvent($this->client, SubscriptionIdMother::from('sub-1'), $encoded);

        $this->assertSame([$encoded->framedFor(SubscriptionIdMother::from('sub-1'))], $this->inner->frames());
    }

    private function stateOf(string $subscriptionId): SubscriptionState
    {
        $subscriptions = $this->subscriptionRegistry->getSubscriptionsForClient($this->client->getId());

        return $subscriptions->toArray()[0]->getState();
    }
}
