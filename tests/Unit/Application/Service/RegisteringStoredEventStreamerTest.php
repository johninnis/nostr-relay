<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Closure;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\HeldSubscriptionSender;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\RegisteringStoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;
use Innis\Nostr\Relay\Tests\Support\ActingKeysMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientMessenger;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use Innis\Nostr\Relay\Tests\Support\TestDoubleException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RegisteringStoredEventStreamerTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private RecordingClientMessenger $messenger;
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($this->createStub(MetricsCollectorInterface::class), new NullLogger());
        $this->messenger = new RecordingClientMessenger();
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->eventStore->method('findByFilters')->willReturn(new StoredEventCollection([]));
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testTheSubscriptionIsRegisteredWithTheGrantedFilters(): void
    {
        $granted = new FilterCollection([Filter::tryFromArray(['kinds' => [4]])]);

        $this->streamer($this->synchronousExecutor())->stream(
            $this->client,
            Subscription::create(SubscriptionIdMother::from('sub-1'), $granted),
            new AdmittedRead(ScopedFilters::scoped($granted, false), ActingKeysMother::anonymous()),
        );

        $this->assertSame($granted, $this->subscriptionRegistry->getSubscriptionsForClient($this->client->getId())->get(SubscriptionIdMother::from('sub-1'))?->getFilters());
    }

    public function testTheSubscriptionIsRegisteredWithTheReaderItWasAdmittedFor(): void
    {
        $reader = ActingKeysMother::of(KeyMother::alicePublicKey());

        $this->streamer($this->synchronousExecutor())->stream(
            $this->client,
            Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()])),
            new AdmittedRead(ScopedFilters::unchanged(new FilterCollection([Filter::from()])), $reader),
        );

        $this->assertSame([$reader], array_map(
            static fn (SubscriptionMatch $match): ActingKeys => $match->getReader(),
            $this->subscriptionRegistry->getSubscriptionsForEvent(EventKind::fromInt(EventKind::TEXT_NOTE))->toArray(),
        ));
    }

    public function testTheGrantedFiltersAreWhatGetStreamed(): void
    {
        $granted = new FilterCollection([Filter::tryFromArray(['kinds' => [4]])]);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->once())->method('findByFilters')->with($granted)->willReturn(new StoredEventCollection([]));
        $this->eventStore = $eventStore;

        $this->streamer($this->synchronousExecutor())->stream(
            $this->client,
            Subscription::create(SubscriptionIdMother::from('sub-1'), $granted),
            new AdmittedRead(ScopedFilters::scoped($granted, false), ActingKeysMother::anonymous()),
        );
    }

    public function testTheStoredStreamRunsDeferredAndReachesEndOfStoredEvents(): void
    {
        $this->streamer($this->synchronousExecutor())->stream(
            $this->client,
            Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()])),
            new AdmittedRead(ScopedFilters::unchanged(new FilterCollection([Filter::from()])), ActingKeysMother::anonymous()),
        );

        $this->assertSame(['["EOSE","sub-1"]'], $this->messenger->frames());
    }

    public function testADeferralFailureIsRethrown(): void
    {
        $executor = $this->createStub(DeferredExecutorInterface::class);
        $executor->method('defer')->willThrowException(new TestDoubleException('deferral unavailable'));

        $this->expectException(TestDoubleException::class);

        $this->streamer($executor)->stream(
            $this->client,
            Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()])),
            new AdmittedRead(ScopedFilters::unchanged(new FilterCollection([Filter::from()])), ActingKeysMother::anonymous()),
        );
    }

    public function testADeferralFailureWithdrawsTheRegistration(): void
    {
        $executor = $this->createStub(DeferredExecutorInterface::class);
        $executor->method('defer')->willThrowException(new TestDoubleException('deferral unavailable'));

        try {
            $this->streamer($executor)->stream(
                $this->client,
                Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()])),
                new AdmittedRead(ScopedFilters::unchanged(new FilterCollection([Filter::from()])), ActingKeysMother::anonymous()),
            );
        } catch (TestDoubleException) {
        }

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    private function streamer(DeferredExecutorInterface $executor): RegisteringStoredEventStreamer
    {
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn(true);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(self::NOW));

        $answers = new SubscriptionAnswers(
            $this->subscriptionRegistry,
            new AuthChallengeIssuer(new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator())),
            new NullLogger(),
        );

        return new RegisteringStoredEventStreamer(
            $executor,
            new StoredEventStreamer(
                new StoredEventReadGate($this->eventStore, new DeliveryGate($policy), $clock),
                new HeldSubscriptionSender($this->messenger, $this->subscriptionRegistry, $answers),
            ),
            $this->subscriptionRegistry,
        );
    }

    private function synchronousExecutor(): DeferredExecutorInterface
    {
        $executor = $this->createStub(DeferredExecutorInterface::class);
        $executor->method('defer')->willReturnCallback(static function (Closure $task): void {
            $task();
        });

        return $executor;
    }
}
