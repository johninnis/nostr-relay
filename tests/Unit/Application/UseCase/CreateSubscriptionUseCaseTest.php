<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\HeldSubscriptionSender;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\Service\ReadScopeGate;
use Innis\Nostr\Relay\Application\Service\RegisteringStoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionActivator;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CreateSubscriptionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private RateLimiterInterface&Stub $rateLimiter;
    private bool $rateLimited = false;
    private InMemoryClientRegistry $clientRegistry;
    private InMemoryAuthenticationRegistry $authenticationRegistry;
    private CreateSubscriptionUseCase $useCase;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->rateLimiter->method('tryConsume')->willReturnCallback(fn (): bool => !$this->rateLimited);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->clientRegistry = new InMemoryClientRegistry($metrics, new NativeRandomBytesGenerator(), $logger);
        $messenger = new ClientMessenger($this->clientRegistry);
        $this->authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $giftWrapRules = new GiftWrapFilterRules();
        $admission = new SubscriptionAdmission(
            new RateLimitGate($this->rateLimiter, $this->policy),
            new ReadScopeGate($this->policy, $this->authenticationRegistry, $giftWrapRules),
            $this->subscriptionRegistry,
        );
        $answers = new SubscriptionAnswers($this->subscriptionRegistry, new AuthChallengeIssuer($this->authenticationRegistry), $logger);
        $storedEventStreamer = new StoredEventStreamer(
            new StoredEventReadGate($this->eventStore, new DeliveryGate($this->policy, $giftWrapRules), new SystemClock()),
            new HeldSubscriptionSender($messenger, $this->subscriptionRegistry, $answers),
        );
        $activator = new SubscriptionActivator(
            $admission,
            new RegisteringStoredEventStreamer(new AmphpDeferredExecutor(), $storedEventStreamer, $this->subscriptionRegistry),
            $answers,
        );

        $this->useCase = new CreateSubscriptionUseCase($activator);

        $this->client = $this->makeClient();
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientRegistry->registerClient(
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testASuccessfulSubscriptionSendsNoImmediateReply(): void
    {
        $this->assertSame([], $this->subscribeWithinScope());
    }

    public function testASuccessfulSubscriptionIsRegistered(): void
    {
        $this->subscribeWithinScope();

        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testABeyondScopeSubscriptionSendsNoImmediateReply(): void
    {
        $this->assertSame([], $this->subscribeBeyondScope());
    }

    public function testABeyondScopeSubscriptionIsRegistered(): void
    {
        $this->subscribeBeyondScope();

        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testASubscriptionNarrowedToNothingIsRefusedAfterAChallenge(): void
    {
        $this->assertSame([AuthMessage::class, ClosedMessage::class], self::messageTypes($this->subscribeFullyOutOfScope()));
    }

    public function testASubscriptionNarrowedToNothingIsClosedAsAuthRequired(): void
    {
        $this->assertStringStartsWith('auth-required:', self::replyOfType($this->subscribeFullyOutOfScope(), ClosedMessage::class)?->getMessage() ?? '');
    }

    public function testASubscriptionNarrowedToNothingRegistersNoSubscription(): void
    {
        $this->subscribeFullyOutOfScope();

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testPolicyViolationReturnsClosedMessage(): void
    {
        $this->assertEquals(
            [ClosedMessage::closed(SubscriptionIdMother::from('sub-1'), ReasonPrefix::Blocked, 'subscription not allowed')],
            $this->subscribeAgainstPolicy(),
        );
    }

    public function testAPolicyViolationRegistersNoSubscription(): void
    {
        $this->subscribeAgainstPolicy();

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testAnAuthRequiredRefusalIsPrecededByAnAuthChallenge(): void
    {
        $this->assertSame([AuthMessage::class, ClosedMessage::class], self::messageTypes($this->subscribeRefusedAsAuthRequired()));
    }

    public function testAnAuthRequiredRefusalIsClosedAsAuthRequired(): void
    {
        $this->assertEquals(
            ClosedMessage::closed(SubscriptionIdMother::from('sub-1'), ReasonPrefix::AuthRequired, 'we only serve DMs to their parties'),
            $this->subscribeRefusedAsAuthRequired()[1],
        );
    }

    public function testAnAuthRequiredRefusalRegistersNoSubscription(): void
    {
        $this->subscribeRefusedAsAuthRequired();

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testAnAuthRequiredRefusalDoesNotReissueAChallengeTheClientAlreadyHolds(): void
    {
        $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        $this->assertEquals(
            [ClosedMessage::closed(SubscriptionIdMother::from('sub-1'), ReasonPrefix::AuthRequired, 'we only serve DMs to their parties')],
            $this->subscribeRefusedAsAuthRequired(),
        );
    }

    public function testRateLimitReturnsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([Filter::from()]);

        $this->rateLimited = true;

        $replies = $this->useCase->execute($this->client, $subId, $filters);

        $this->assertEquals([ClosedMessage::closed(SubscriptionIdMother::from('sub-1'), ReasonPrefix::RateLimited, 'slow down')], $replies);
    }

    public function testSubscriptionLimitReturnsClosedMessage(): void
    {
        $this->assertEquals(
            [ClosedMessage::closed(SubscriptionIdMother::from('sub-2'), ReasonPrefix::Blocked, 'too many subscriptions (max 1)')],
            $this->subscribeTwiceUnderALimitOfOne($this->makeClient()),
        );
    }

    public function testSubscriptionLimitKeepsOnlyTheSubscriptionWithinIt(): void
    {
        $client = $this->makeClient();

        $this->subscribeTwiceUnderALimitOfOne($client);

        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($client->getId()));
    }

    public function testASubscriptionReplacingOneTheClientHoldsAtTheCapIsAdmitted(): void
    {
        $client = $this->makeClient();

        $this->assertSame([], $this->subscribeTwiceUnderALimitOfOne($client, 'sub-1'));
    }

    public function testASubscriptionReplacingOneTheClientHoldsAtTheCapKeepsOneSubscription(): void
    {
        $client = $this->makeClient();

        $this->subscribeTwiceUnderALimitOfOne($client, 'sub-1');

        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($client->getId()));
    }

    /**
     * @template T of RelayMessage
     *
     * @param list<RelayMessage> $replies
     * @param class-string<T>    $type
     *
     * @return T|null
     */
    private static function replyOfType(array $replies, string $type): ?RelayMessage
    {
        $reply = array_find($replies, static fn (RelayMessage $reply): bool => $reply instanceof $type);

        return $reply instanceof $type ? $reply : null;
    }

    /**
     * @return list<RelayMessage>
     */
    private function subscribeWithinScope(): array
    {
        $filters = new FilterCollection([Filter::from()]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new StoredEventCollection([]));

        return $this->useCase->execute($this->client, SubscriptionIdMother::from('sub-1'), $filters);
    }

    /**
     * @return list<RelayMessage>
     */
    private function subscribeBeyondScope(): array
    {
        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), true),
        );
        $this->eventStore->method('findByFilters')->willReturn(new StoredEventCollection([]));

        return $this->useCase->execute($this->client, SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]));
    }

    /**
     * @return list<RelayMessage>
     */
    private function subscribeFullyOutOfScope(): array
    {
        $this->policy->method('filterForClient')->willReturn(ScopedFilters::scoped(new FilterCollection(), true));
        $this->eventStore->method('findByFilters')->willReturn(new StoredEventCollection([]));

        return $this->useCase->execute($this->client, SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from(authors: PublicKeyCollection::fromHexValues(['ff']))]));
    }

    /**
     * @return list<RelayMessage>
     */
    private function subscribeAgainstPolicy(): array
    {
        $this->policy->method('allowSubscription')
            ->willReturn(PolicyRejection::blocked('subscription not allowed'));

        return $this->useCase->execute($this->client, SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]));
    }

    /**
     * @return list<RelayMessage>
     */
    private function subscribeRefusedAsAuthRequired(): array
    {
        $this->policy->method('allowSubscription')
            ->willReturn(PolicyRejection::authRequired('we only serve DMs to their parties'));

        return $this->useCase->execute($this->client, SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]));
    }

    /**
     * @return list<RelayMessage>
     */
    private function subscribeTwiceUnderALimitOfOne(RelayClient $client, string $secondId = 'sub-2'): array
    {
        $this->policy->method('allowSubscription')
            ->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters, int $currentSubscriptionCount): ?PolicyRejection => $currentSubscriptionCount >= 1
                ? PolicyRejection::blocked('too many subscriptions (max 1)')
                : null);
        $this->policy->method('filterForClient')
            ->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new StoredEventCollection([]));

        return [
            ...$this->useCase->execute($client, SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()])),
            ...$this->useCase->execute($client, SubscriptionIdMother::from($secondId), new FilterCollection([Filter::from()])),
        ];
    }

    /**
     * @param list<RelayMessage> $replies
     *
     * @return list<class-string<RelayMessage>>
     */
    private static function messageTypes(array $replies): array
    {
        return array_map(static fn (RelayMessage $reply): string => $reply::class, $replies);
    }
}
