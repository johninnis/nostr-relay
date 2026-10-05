<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SubscriptionAnswersTest extends TestCase
{
    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private SubscriptionAnswers $answers;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($this->createStub(MetricsCollectorInterface::class), new NullLogger());
        $this->answers = new SubscriptionAnswers(
            $this->subscriptionRegistry,
            new AuthChallengeIssuer(new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator())),
        );
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testSettleRefusalWithdrawsTheRegistration(): void
    {
        $subscription = Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]));
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription);

        $this->answers->settleRefusal($this->client, SubscriptionIdMother::from('sub-1'), PolicyRejection::blocked('not allowed'));

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testSettleRefusalOnAnAbsentIdStillAnswersClosed(): void
    {
        $replies = $this->answers->settleRefusal($this->client, SubscriptionIdMother::from('missing'), PolicyRejection::blocked('not allowed'));

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(ClosedMessage::class, $replies[0]);
    }

    public function testAnAuthRequiredRefusalIsPrecededByAChallenge(): void
    {
        $replies = $this->answers->settleRefusal($this->client, SubscriptionIdMother::from('sub-1'), PolicyRejection::authRequired('members only'));

        $this->assertCount(2, $replies);
        $this->assertInstanceOf(AuthMessage::class, $replies[0]);
        $this->assertInstanceOf(ClosedMessage::class, $replies[1]);
        $this->assertStringStartsWith('auth-required:', $replies[1]->getMessage());
    }

    public function testForScopeOffersANoticeAndChallengeWhenBeyondScope(): void
    {
        $filters = new FilterCollection([Filter::from()]);

        $replies = $this->answers->forScope($this->client, ScopedFilters::scoped($filters, $filters, true));

        $this->assertCount(2, $replies);
        $this->assertInstanceOf(NoticeMessage::class, $replies[0]);
        $this->assertInstanceOf(AuthMessage::class, $replies[1]);
    }

    public function testForScopeIsSilentWithinScope(): void
    {
        $replies = $this->answers->forScope($this->client, ScopedFilters::unchanged(new FilterCollection([Filter::from()])));

        $this->assertSame([], $replies);
    }
}
