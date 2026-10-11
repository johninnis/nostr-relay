<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\Service\ReadableEventCounter;
use Innis\Nostr\Relay\Application\Service\ReadScopeGate;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CountSubscriptionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private RateLimiterInterface&Stub $rateLimiter;
    private bool $rateLimited = false;
    private InMemoryClientRegistry $clientRegistry;
    private CountSubscriptionUseCase $useCase;
    private InMemorySubscriptionRegistry $subscriptionRegistry;

    protected function setUp(): void
    {
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->rateLimiter->method('tryConsume')->willReturnCallback(fn (): bool => !$this->rateLimited);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();
        $this->clientRegistry = new InMemoryClientRegistry(
            $metrics,
            new NativeRandomBytesGenerator(),
            $logger,
        );
        $subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->subscriptionRegistry = $subscriptionRegistry;
        $authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $admission = new SubscriptionAdmission(
            new RateLimitGate($this->rateLimiter, $this->policy),
            new ReadScopeGate($this->policy, $authenticationRegistry, new GiftWrapFilterRules()),
            $subscriptionRegistry,
        );

        $this->useCase = new CountSubscriptionUseCase(
            new ReadableEventCounter($this->eventStore, new GiftWrapFilterRules()),
            $admission,
            new SubscriptionAnswers($subscriptionRegistry, new AuthChallengeIssuer($authenticationRegistry), $logger),
        );
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientRegistry->registerClient(
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testSuccessfulCountReturnsCountMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1]))]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturn(EventCount::exact(42));

        $replies = $this->useCase->execute($this->makeClient(), $subId, $filters);

        $this->assertEquals([new CountMessage(SubscriptionIdMother::from('count-1'), EventCount::exact(42))], $replies);
    }

    public function testAnApproximateCountIsMarkedOnTheWire(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1]))]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturn(EventCount::approximate(1000));

        $replies = $this->useCase->execute($this->makeClient(), $subId, $filters);

        $this->assertEquals([new CountMessage(SubscriptionIdMother::from('count-1'), EventCount::approximate(1000))], $replies);
    }

    public function testBeyondScopeSendsAChallengeThenReturnsCount(): void
    {
        $this->assertSame([AuthMessage::class, CountMessage::class], self::messageTypes($this->countBeyondScope()));
    }

    public function testBeyondScopeCountsTheScopedFilters(): void
    {
        $this->assertEquals(new CountMessage(SubscriptionIdMother::from('count-1'), EventCount::exact(7)), $this->countBeyondScope()[1]);
    }

    public function testACountNamingNoKindCountsNoGiftWraps(): void
    {
        $this->policy->method('filterForClient')->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturnCallback(
            static fn (FilterCollection $filters): EventCount => EventCount::exact(null === $filters->toArray()[0]->getKinds() ? 9 : 2),
        );

        $replies = $this->useCase->execute($this->makeClient(), SubscriptionIdMother::from('count-1'), new FilterCollection([Filter::from()]));

        $this->assertEquals([new CountMessage(SubscriptionIdMother::from('count-1'), EventCount::exact(7))], $replies);
    }

    public function testPolicyViolationReturnsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([Filter::from()]);

        $this->policy->method('allowSubscription')
            ->willReturn(PolicyRejection::blocked('not allowed'));

        $replies = $this->useCase->execute($this->makeClient(), $subId, $filters);

        $this->assertEquals([ClosedMessage::closed(SubscriptionIdMother::from('count-1'), ReasonPrefix::Blocked, 'not allowed')], $replies);
    }

    public function testAnAuthRequiredRefusalIsPrecededByAnAuthChallenge(): void
    {
        $this->assertSame([AuthMessage::class, ClosedMessage::class], self::messageTypes($this->countRefusedAsAuthRequired()));
    }

    public function testAnAuthRequiredRefusalIsClosedAsAuthRequired(): void
    {
        $this->assertEquals(
            ClosedMessage::closed(SubscriptionIdMother::from('count-1'), ReasonPrefix::AuthRequired, 'counts are for members'),
            $this->countRefusedAsAuthRequired()[1],
        );
    }

    public function testARefusedCountLeavesASubscriptionOnTheSameIdOpen(): void
    {
        $client = $this->makeClient();
        $this->subscriptionRegistry->addSubscription($client->getId(), Subscription::create(SubscriptionIdMother::from('shared'), new FilterCollection([Filter::from()])));
        $this->policy->method('allowSubscription')->willReturn(PolicyRejection::blocked('not allowed'));

        $this->useCase->execute($client, SubscriptionIdMother::from('shared'), new FilterCollection([Filter::from()]));

        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($client->getId()));
    }

    public function testRateLimitReturnsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([Filter::from()]);

        $this->rateLimited = true;

        $replies = $this->useCase->execute($this->makeClient(), $subId, $filters);

        $this->assertEquals([ClosedMessage::closed(SubscriptionIdMother::from('count-1'), ReasonPrefix::RateLimited, 'slow down')], $replies);
    }

    /**
     * @return list<RelayMessage>
     */
    private function countBeyondScope(): array
    {
        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), true),
        );
        $this->eventStore->method('countByFilters')->willReturn(EventCount::exact(7));

        return $this->useCase->execute($this->makeClient(), SubscriptionIdMother::from('count-1'), new FilterCollection([Filter::from()]));
    }

    /**
     * @return list<RelayMessage>
     */
    private function countRefusedAsAuthRequired(): array
    {
        $this->policy->method('allowSubscription')
            ->willReturn(PolicyRejection::authRequired('counts are for members'));

        return $this->useCase->execute($this->makeClient(), SubscriptionIdMother::from('count-1'), new FilterCollection([Filter::from()]));
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
