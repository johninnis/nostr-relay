<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\SubscriptionCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\Service\ReadScopeGate;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class SubscriptionAdmissionTest extends TestCase
{
    public function testAClientAtTheSubscriptionCapIsRefusedAFreshSubscription(): void
    {
        $admission = $this->admissionFor(held: ['open']);

        $this->assertInstanceOf(PolicyRejection::class, $admission->admitSubscription($this->client(), SubscriptionIdMother::from('fresh'), $this->filters()));
    }

    public function testAClientAtTheSubscriptionCapMayReplaceASubscriptionItHolds(): void
    {
        $admission = $this->admissionFor(held: ['open']);

        $this->assertInstanceOf(ScopedFilters::class, $admission->admitSubscription($this->client(), SubscriptionIdMother::from('open'), $this->filters()));
    }

    public function testAReplacingRequestIsRateLimitedLikeAnyOther(): void
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('tryConsume')->willReturn(false);

        $answer = $this->admissionFor(held: ['open'], rateLimiter: $rateLimiter)->admitSubscription($this->client(), SubscriptionIdMother::from('open'), $this->filters());

        $this->assertStringStartsWith('rate-limited:', $answer instanceof PolicyRejection ? $answer->toWireReason() : '');
    }

    public function testAClientAtTheSubscriptionCapIsRefusedACount(): void
    {
        $admission = $this->admissionFor(held: ['open']);

        $this->assertInstanceOf(PolicyRejection::class, $admission->admitCount($this->client(), $this->filters()));
    }

    public function testASubscriptionWhoseNarrowedFiltersCanMatchNothingIsRefusedAsAuthRequired(): void
    {
        $answer = $this->admissionFor(held: [])->admitSubscription($this->client(), SubscriptionIdMother::from('wraps'), $this->giftWrapFilters());

        $this->assertStringStartsWith('auth-required:', $answer instanceof PolicyRejection ? $answer->toWireReason() : '');
    }

    public function testACountWhoseNarrowedFiltersCanMatchNothingIsAnsweredForWhatItMayRead(): void
    {
        $this->assertInstanceOf(ScopedFilters::class, $this->admissionFor(held: [])->admitCount($this->client(), $this->giftWrapFilters()));
    }

    /**
     * @param list<string> $held
     */
    private function admissionFor(array $held, ?RateLimiterInterface $rateLimiter = null): SubscriptionAdmission
    {
        $sessions = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $policy = new RelayPolicy(
            $sessions,
            new NullLogger(),
            RelayPolicyConfig::tryFromArray(['max_subscriptions' => 1]) ?? throw new RuntimeException('config did not parse'),
        );

        $subscriptions = new SubscriptionCollection(array_map(
            fn (string $id): Subscription => Subscription::create(SubscriptionIdMother::from($id), $this->filters()),
            $held,
        ));
        $lookup = $this->createStub(SubscriptionLookupInterface::class);
        $lookup->method('getSubscriptionsForClient')->willReturn($subscriptions);
        $lookup->method('getSubscriptionCountForClient')->willReturn(count($subscriptions));

        return new SubscriptionAdmission(
            new RateLimitGate($rateLimiter ?? $this->alwaysAdmittingRateLimiter(), $policy),
            new ReadScopeGate($policy, $sessions, new GiftWrapFilterRules()),
            $lookup,
        );
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
        return new FilterCollection([Filter::tryFromArray(['kinds' => [1]]) ?? throw new RuntimeException('filter did not parse')]);
    }

    private function giftWrapFilters(): FilterCollection
    {
        return new FilterCollection([Filter::tryFromArray(['kinds' => [EventKind::GIFT_WRAP]]) ?? throw new RuntimeException('filter did not parse')]);
    }
}
