<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SubscriptionAdmissionTest extends TestCase
{
    public function testAClientAtTheSubscriptionCapIsRefusedAFreshSubscription(): void
    {
        $admission = $this->admissionFor(heldSubscriptions: 1);

        $this->assertInstanceOf(PolicyRejection::class, $admission->admit($this->client(), $this->filters()));
    }

    public function testAClientAtTheSubscriptionCapIsStillReadmittedForOneItAlreadyHolds(): void
    {
        $admission = $this->admissionFor(heldSubscriptions: 1);

        $this->assertInstanceOf(ScopedFilters::class, $admission->readmit($this->client(), $this->filters()));
    }

    public function testReadmissionSpendsNoRateLimitToken(): void
    {
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->never())->method('tryConsume');

        $this->admissionFor(heldSubscriptions: 0, rateLimiter: $rateLimiter)->readmit($this->client(), $this->filters());
    }

    public function testAClientHoldingNothingIsNotDiscountedBelowZero(): void
    {
        $admission = $this->admissionFor(heldSubscriptions: 0);

        $this->assertInstanceOf(ScopedFilters::class, $admission->readmit($this->client(), $this->filters()));
    }

    private function admissionFor(int $heldSubscriptions, ?RateLimiterInterface $rateLimiter = null): SubscriptionAdmission
    {
        $policy = new RelayPolicy(
            new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()),
            new NullLogger(),
            RelayPolicyConfig::tryFromArray(['max_subscriptions' => 1]) ?? self::fail('config did not parse'),
        );

        $lookup = $this->createStub(SubscriptionLookupInterface::class);
        $lookup->method('getSubscriptionCountForClient')->willReturn($heldSubscriptions);

        $limiter = $rateLimiter ?? $this->alwaysAdmittingRateLimiter();

        return new SubscriptionAdmission($policy, new RateLimitGate($limiter, $policy), $lookup);
    }

    private function alwaysAdmittingRateLimiter(): RateLimiterInterface
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('tryConsume')->willReturn(true);

        return $rateLimiter;
    }

    private function client(): RelayClient
    {
        return new RelayClient(
            ClientId::fromString('guest'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    private function filters(): FilterCollection
    {
        return new FilterCollection([Filter::tryFromArray(['kinds' => [1]]) ?? self::fail('filter did not parse')]);
    }
}
