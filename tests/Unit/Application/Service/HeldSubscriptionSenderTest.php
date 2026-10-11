<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\ClientMessengerInterface;
use Innis\Nostr\Relay\Application\Service\HeldSubscriptionSender;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientMessenger;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use Innis\Nostr\Relay\Tests\Support\TestDoubleException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HeldSubscriptionSenderTest extends TestCase
{
    private const string EOSE = '["EOSE","sub-1"]';

    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private InMemoryAuthenticationRegistry $authenticationRegistry;
    private RecordingClientMessenger $messenger;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($this->createStub(MetricsCollectorInterface::class), new NullLogger());
        $this->authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $this->messenger = new RecordingClientMessenger();
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testTheEventsOfAHeldSubscriptionAreSent(): void
    {
        $note = self::note('held');

        $this->sender($this->messenger)->sendEvents($this->client, $this->registered(), new StoredEventCollection([$note]));

        $this->assertSame([$note->getEncoded()->framedFor(SubscriptionIdMother::from('sub-1'))], $this->messenger->frames());
    }

    public function testTheEventsOfAReplacedSubscriptionAreNotSent(): void
    {
        $replaced = $this->registered();
        $this->registered();

        $this->sender($this->messenger)->sendEvents($this->client, $replaced, new StoredEventCollection([self::note('replaced')]));

        $this->assertSame([], $this->messenger->frames());
    }

    public function testASubscriptionReplacedWhileItsEventsAreSentIsSentNoMoreOfThem(): void
    {
        $first = self::note('first');
        $frames = [];

        $this->sender($this->messengerReplacingTheSubscriptionAfterItsFirstFrame($frames))
            ->sendEvents($this->client, $this->registered(), new StoredEventCollection([$first, self::note('second')]));

        $this->assertSame([$first->getEncoded()->framedFor(SubscriptionIdMother::from('sub-1'))], $frames);
    }

    public function testTheEndOfAHeldSubscriptionsStoredEventsIsSent(): void
    {
        $this->sender($this->messenger)->sendEnd($this->client, $this->registered(), ScopedFilters::unchanged(new FilterCollection([Filter::from()])));

        $this->assertSame([self::EOSE], $this->messenger->frames());
    }

    public function testTheEndOfAReplacedSubscriptionsStoredEventsIsNotSent(): void
    {
        $replaced = $this->registered();
        $this->registered();

        $this->sender($this->messenger)->sendEnd($this->client, $replaced, ScopedFilters::unchanged(new FilterCollection([Filter::from()])));

        $this->assertSame([], $this->messenger->frames());
    }

    public function testTheEndOfAReplacedReadBeyondScopeIssuesNoChallenge(): void
    {
        $replaced = $this->registered();
        $this->registered();

        $this->sender($this->messenger)->sendEnd($this->client, $replaced, ScopedFilters::scoped(new FilterCollection([Filter::from()]), true));

        $this->assertNull($this->authenticationRegistry->getChallenge($this->client->getId()));
    }

    public function testASubscriptionReplacedOnceItsChallengeIsSentIsNotSentItsEose(): void
    {
        $frames = [];

        $this->sender($this->messengerReplacingTheSubscriptionAfterItsFirstFrame($frames))
            ->sendEnd($this->client, $this->registered(), ScopedFilters::scoped(new FilterCollection([Filter::from()]), true));

        $this->assertSame([AuthMessage::class], array_map(static fn (string $frame): string => null === AuthMessage::tryFromJson($frame) ? $frame : AuthMessage::class, $frames));
    }

    public function testAFaultOnAHeldSubscriptionIsAnsweredClosedAsAnError(): void
    {
        $this->sender($this->messenger)->sendFault($this->client, $this->registered(), new TestDoubleException('store unavailable'));

        $this->assertSame(['["CLOSED","sub-1","error: could not read stored events"]'], $this->messenger->frames());
    }

    public function testAFaultOnAReplacedSubscriptionIsAnsweredWithNothing(): void
    {
        $replaced = $this->registered();
        $this->registered();

        $this->sender($this->messenger)->sendFault($this->client, $replaced, new TestDoubleException('store unavailable'));

        $this->assertSame([], $this->messenger->frames());
    }

    private function sender(ClientMessengerInterface $messenger): HeldSubscriptionSender
    {
        return new HeldSubscriptionSender(
            $messenger,
            $this->subscriptionRegistry,
            new SubscriptionAnswers($this->subscriptionRegistry, new AuthChallengeIssuer($this->authenticationRegistry), new NullLogger()),
        );
    }

    private function registered(): Subscription
    {
        $subscription = Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]));
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription);

        return $subscription;
    }

    /**
     * @param list<string> $frames
     */
    private function messengerReplacingTheSubscriptionAfterItsFirstFrame(array &$frames): ClientMessengerInterface
    {
        $record = function (string $frame) use (&$frames): void {
            $frames[] = $frame;

            if (1 === count($frames)) {
                $this->registered();
            }
        };

        $messenger = $this->createStub(ClientMessengerInterface::class);
        $messenger->method('send')->willReturnCallback(static fn (RelayClient $client, RelayMessage $message) => $record($message->toJson()));
        $messenger->method('sendEvent')->willReturnCallback(static fn (RelayClient $client, SubscriptionId $subscriptionId, EncodedEvent $event) => $record($event->framedFor($subscriptionId)));

        return $messenger;
    }

    private static function note(string $content): StoredEvent
    {
        return StoredEvent::of(EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString($content),
            new TagCollection([]),
            Timestamp::fromInt(1_700_000_000),
        )));
    }
}
