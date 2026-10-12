<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Tests\Support\ActingKeysMother;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use Innis\Nostr\Relay\Tests\Support\TestDoubleException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;

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
            new NullLogger(),
        );
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testSettleRefusalWithdrawsTheRegistration(): void
    {
        $subscription = Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]));
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription, ActingKeysMother::anonymous());

        $this->answers->settleRefusal($this->client, SubscriptionIdMother::from('sub-1'), PolicyRejection::blocked('not allowed'));

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testSettleRefusalOnAnAbsentIdStillAnswersClosed(): void
    {
        $replies = $this->answers->settleRefusal($this->client, SubscriptionIdMother::from('missing'), PolicyRejection::blocked('not allowed'));

        $this->assertEquals([ClosedMessage::closed(SubscriptionIdMother::from('missing'), ReasonPrefix::Blocked, 'not allowed')], $replies);
    }

    public function testAnAuthRequiredRefusalIsPrecededByAChallenge(): void
    {
        $replies = $this->answers->settleRefusal($this->client, SubscriptionIdMother::from('sub-1'), PolicyRejection::authRequired('members only'));

        $this->assertSame([AuthMessage::class, ClosedMessage::class], self::messageTypes($replies));
    }

    public function testAnAuthRequiredRefusalIsClosedAsAuthRequired(): void
    {
        $replies = $this->answers->settleRefusal($this->client, SubscriptionIdMother::from('sub-1'), PolicyRejection::authRequired('members only'));

        $this->assertEquals(ClosedMessage::closed(SubscriptionIdMother::from('sub-1'), ReasonPrefix::AuthRequired, 'members only'), $replies[1]);
    }

    public function testForScopeOffersAChallengeWhenBeyondScope(): void
    {
        $replies = $this->answers->forScope($this->client, new AdmittedRead(ScopedFilters::scoped(new FilterCollection([Filter::from()]), true), ActingKeysMother::anonymous()));

        $this->assertSame([AuthMessage::class], self::messageTypes($replies));
    }

    public function testForScopeIsSilentWithinScope(): void
    {
        $replies = $this->answers->forScope($this->client, new AdmittedRead(ScopedFilters::unchanged(new FilterCollection([Filter::from()])), ActingKeysMother::anonymous()));

        $this->assertSame([], $replies);
    }

    public function testStoredEventsOfAReadWithinScopeEndWithAPlainEose(): void
    {
        $replies = $this->answers->endOfStoredEvents($this->client, SubscriptionIdMother::from('sub-1'), new AdmittedRead(ScopedFilters::unchanged(new FilterCollection([Filter::from()])), ActingKeysMother::anonymous()));

        $this->assertEquals([new EoseMessage(SubscriptionIdMother::from('sub-1'))], $replies);
    }

    public function testStoredEventsOfAReadBeyondScopeEndWithAChallengeThenAnEose(): void
    {
        $replies = $this->answers->endOfStoredEvents($this->client, SubscriptionIdMother::from('sub-1'), new AdmittedRead(ScopedFilters::scoped(new FilterCollection([Filter::from()]), true), ActingKeysMother::anonymous()));

        $this->assertSame([AuthMessage::class, EoseMessage::class], self::messageTypes($replies));
    }

    public function testTheEoseOfAReadBeyondScopeCarriesTheAuthHint(): void
    {
        $replies = $this->answers->endOfStoredEvents($this->client, SubscriptionIdMother::from('sub-1'), new AdmittedRead(ScopedFilters::scoped(new FilterCollection([Filter::from()]), true), ActingKeysMother::anonymous()));

        $this->assertSame('["EOSE","sub-1",["auth"]]', ($replies[1] ?? null)?->toJson());
    }

    public function testAReadBeyondScopeIsOfferedAChallengeEvenWhenTheClientAlreadyHoldsOne(): void
    {
        $authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $answers = new SubscriptionAnswers($this->subscriptionRegistry, new AuthChallengeIssuer($authenticationRegistry), new NullLogger());

        $replies = $answers->endOfStoredEvents($this->client, SubscriptionIdMother::from('sub-1'), new AdmittedRead(ScopedFilters::scoped(new FilterCollection([Filter::from()]), true), ActingKeysMother::anonymous()));

        $this->assertInstanceOf(AuthMessage::class, $replies[0]);
    }

    public function testAFaultWhileReadingStoredEventsIsAnsweredClosedAsAnError(): void
    {
        $replies = $this->answers->settleFault($this->client, $this->registeredSubscription(), new TestDoubleException('store unavailable'));

        $this->assertEquals([ClosedMessage::closed(SubscriptionIdMother::from('sub-1'), ReasonPrefix::Error, 'could not read stored events')], $replies);
    }

    public function testAFaultWhileReadingStoredEventsWithdrawsTheRegistration(): void
    {
        $this->answers->settleFault($this->client, $this->registeredSubscription(), new TestDoubleException('store unavailable'));

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testAFaultOnAReplacedSubscriptionIsAnsweredWithNothing(): void
    {
        $replaced = $this->registeredSubscription();
        $this->registeredSubscription();

        $this->assertSame([], $this->answers->settleFault($this->client, $replaced, new TestDoubleException('store unavailable')));
    }

    public function testAFaultOnAReplacedSubscriptionLeavesItsReplacementRegistered(): void
    {
        $replaced = $this->registeredSubscription();
        $replacement = $this->registeredSubscription();

        $this->answers->settleFault($this->client, $replaced, new TestDoubleException('store unavailable'));

        $this->assertTrue($this->subscriptionRegistry->holds($this->client->getId(), $replacement));
    }

    public function testAFaultOnASubscriptionTheClientClosedIsAnsweredWithNothing(): void
    {
        $closed = $this->registeredSubscription();
        $this->subscriptionRegistry->removeSubscription($this->client->getId(), $closed->getId());

        $this->assertSame([], $this->answers->settleFault($this->client, $closed, new TestDoubleException('store unavailable')));
    }

    public function testAFaultOnASubscriptionNoLongerHeldIsStillLogged(): void
    {
        $logged = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string|Stringable $message) use (&$logged): void {
            $logged[] = (string) $message;
        });
        $answers = new SubscriptionAnswers($this->subscriptionRegistry, new AuthChallengeIssuer(new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator())), $logger);

        $answers->settleFault($this->client, Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()])), new TestDoubleException('store unavailable'));

        $this->assertSame(['Failed to read stored events'], $logged);
    }

    private function registeredSubscription(): Subscription
    {
        $subscription = Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]));
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription, ActingKeysMother::anonymous());

        return $subscription;
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
