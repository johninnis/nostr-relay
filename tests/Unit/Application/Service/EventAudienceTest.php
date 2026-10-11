<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\EventAudience;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\EventRecipient;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class EventAudienceTest extends TestCase
{
    private RelayPolicyInterface&Stub $policy;
    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private InMemoryClientRegistry $clientRegistry;
    private EventAudience $audience;

    protected function setUp(): void
    {
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->clientRegistry = new InMemoryClientRegistry($metrics, new NativeRandomBytesGenerator(), $logger);

        $this->audience = new EventAudience(new DeliveryGate($this->policy, new GiftWrapFilterRules()), $this->subscriptionRegistry, $this->clientRegistry);
    }

    public function testAMatchingSubscriptionYieldsARecipientWithItsClientAndSubscriptionId(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $client = $this->registerClientWithSubscription('sub-1', [EventKind::TEXT_NOTE]);

        $recipients = $this->audience->audienceFor($this->textNote())->toArray();

        $this->assertEquals([new EventRecipient($client, SubscriptionIdMother::from('sub-1'))], $recipients);
    }

    public function testANonMatchingSubscriptionIsSkipped(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $this->registerClientWithSubscription('sub-1', [EventKind::METADATA]);

        $this->assertSame([], $this->audience->audienceFor($this->textNote())->toArray());
    }

    public function testASubscriptionWhoseClientIsGoneIsSkipped(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $ghost = ClientId::fromString('ghost');
        $subscription = Subscription::create(
            SubscriptionIdMother::from('sub-1'),
            new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([EventKind::TEXT_NOTE]))]),
            SubscriptionState::Active,
        );
        $this->subscriptionRegistry->addSubscription($ghost, $subscription);

        $this->assertSame([], $this->audience->audienceFor($this->textNote())->toArray());
    }

    public function testAClientThePolicyRefusesIsSkipped(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(false);
        $this->registerClientWithSubscription('sub-1', [EventKind::TEXT_NOTE]);

        $this->assertSame([], $this->audience->audienceFor($this->textNote())->toArray());
    }

    public function testALiveGiftWrapIsWithheldFromASubscriptionNamingNoKind(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $this->registerClientWithFilters('sub-1', []);

        $this->assertSame([], $this->audience->audienceFor(EventMother::giftWrapFor(KeyMother::bobPublicKey()))->toArray());
    }

    public function testALiveGiftWrapIsWithheldFromASubscriptionNamingItsRecipientWithoutAKind(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $this->registerClientWithFilters('sub-1', ['#p' => [KeyMother::bobPublicKey()->toHex()]]);

        $this->assertSame([], $this->audience->audienceFor(EventMother::giftWrapFor(KeyMother::bobPublicKey()))->toArray());
    }

    public function testALiveGiftWrapReachesASubscriptionConfinedToItsRecipient(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $client = $this->registerClientWithFilters('sub-1', ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]);

        $recipients = $this->audience->audienceFor(EventMother::giftWrapFor(KeyMother::bobPublicKey()))->toArray();

        $this->assertEquals([new EventRecipient($client, SubscriptionIdMother::from('sub-1'))], $recipients);
    }

    public function testALiveGiftWrapReachesASubscriptionThroughAFilterNamingItsKindBesideOneNamingNone(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $client = $this->registerClientWithFilters('sub-1', [], ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]);

        $recipients = $this->audience->audienceFor(EventMother::giftWrapFor(KeyMother::bobPublicKey()))->toArray();

        $this->assertEquals([new EventRecipient($client, SubscriptionIdMother::from('sub-1'))], $recipients);
    }

    /**
     * @param list<int> $kinds
     */
    private function registerClientWithSubscription(string $subIdStr, array $kinds): RelayClient
    {
        return $this->registerClientWithFilters($subIdStr, ['kinds' => $kinds]);
    }

    /**
     * @param array<string, mixed> ...$filters
     */
    private function registerClientWithFilters(string $subIdStr, array ...$filters): RelayClient
    {
        $client = $this->clientRegistry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );

        $subscription = Subscription::create(
            SubscriptionIdMother::from($subIdStr),
            new FilterCollection(array_map(
                static fn (array $filter): Filter => Filter::tryFromArray($filter) ?? throw new RuntimeException('filter did not parse'),
                array_values($filters),
            )),
            SubscriptionState::Active,
        );
        $this->subscriptionRegistry->addSubscription($client->getId(), $subscription);

        return $client;
    }

    private function textNote(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection(),
        ));
    }
}
