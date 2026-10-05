<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
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
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
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
        $admission = new SubscriptionAdmission(
            $this->policy,
            new RateLimitGate($this->rateLimiter, $this->policy),
            $subscriptionRegistry,
        );

        $this->useCase = new CountSubscriptionUseCase(
            $this->eventStore,
            $admission,
            new SubscriptionAnswers($subscriptionRegistry, new AuthChallengeIssuer(new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()))),
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
        $filters = new FilterCollection([Filter::from()]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturn(EventCount::exact(42));

        $replies = $this->useCase->execute($this->makeClient(), $subId, $filters);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(CountMessage::class, $replies[0]);
        $this->assertSame('count-1', (string) $replies[0]->getSubscriptionId());
        $this->assertSame(42, $replies[0]->getCount()->toInt());
        $this->assertFalse($replies[0]->getCount()->isApproximate());
    }

    public function testAnApproximateCountIsMarkedOnTheWire(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([Filter::from()]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturn(EventCount::approximate(1000));

        $replies = $this->useCase->execute($this->makeClient(), $subId, $filters);

        $this->assertInstanceOf(CountMessage::class, $replies[0]);
        $this->assertSame(1000, $replies[0]->getCount()->toInt());
        $this->assertTrue($replies[0]->getCount()->isApproximate());
    }

    public function testBeyondScopeSendsNoticeAndChallengeThenReturnsCount(): void
    {
        $subId = SubscriptionIdMother::from('count-1');

        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), true),
        );
        $this->eventStore->method('countByFilters')->willReturn(EventCount::exact(7));

        $replies = $this->useCase->execute($this->makeClient(), $subId, new FilterCollection([Filter::from()]));

        $this->assertInstanceOf(NoticeMessage::class, $replies[0]);
        $this->assertInstanceOf(AuthMessage::class, $replies[1]);
        $this->assertInstanceOf(CountMessage::class, $replies[2]);
        $this->assertSame(7, $replies[2]->getCount()->toInt());
    }

    public function testPolicyViolationReturnsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([Filter::from()]);

        $this->policy->method('allowSubscription')
            ->willReturn(PolicyRejection::blocked('not allowed'));

        $replies = $this->useCase->execute($this->makeClient(), $subId, $filters);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(ClosedMessage::class, $replies[0]);
        $this->assertStringContainsString('blocked', $replies[0]->getMessage());
    }

    public function testAnAuthRequiredRefusalIsPrecededByAnAuthChallenge(): void
    {
        $this->policy->method('allowSubscription')
            ->willReturn(PolicyRejection::authRequired('counts are for members'));

        $replies = $this->useCase->execute($this->makeClient(), SubscriptionIdMother::from('count-1'), new FilterCollection([Filter::from()]));

        $this->assertCount(2, $replies);
        $this->assertInstanceOf(AuthMessage::class, $replies[0]);
        $this->assertInstanceOf(ClosedMessage::class, $replies[1]);
        $this->assertStringStartsWith('auth-required:', $replies[1]->getMessage());
    }

    public function testRateLimitReturnsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([Filter::from()]);

        $this->rateLimited = true;

        $replies = $this->useCase->execute($this->makeClient(), $subId, $filters);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(ClosedMessage::class, $replies[0]);
        $this->assertStringContainsString('rate-limited', $replies[0]->getMessage());
    }
}
