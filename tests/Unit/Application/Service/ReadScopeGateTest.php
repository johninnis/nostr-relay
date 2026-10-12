<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\ReadScopeGate;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadScopeGateTest extends TestCase
{
    public function testTheReadCarriesTheKeysTheConnectionAuthenticatedAs(): void
    {
        $sessions = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $sessions->authenticate(self::client()->getId(), KeyMother::alicePublicKey());

        $read = new ReadScopeGate($this->policyGrantingEverything(), $sessions)
            ->admit(self::client(), self::filters(['kinds' => [EventKind::GIFT_WRAP]]), 0);

        $this->assertSame(
            [KeyMother::alicePublicKey()->toHex()],
            $read instanceof AdmittedRead ? $read->getReader()->getKeys()->toHexes() : null,
        );
    }

    public function testTheReadKeepsTheFiltersThePolicyGranted(): void
    {
        $read = new ReadScopeGate($this->policyGrantingEverything(), new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()))
            ->admit(self::client(), self::filters(['kinds' => [EventKind::GIFT_WRAP]]), 0);

        $this->assertSame([['kinds' => [EventKind::GIFT_WRAP]]], $read instanceof AdmittedRead ? $read->getFilters()->toJsonArray() : null);
    }

    public function testAGuestsGiftWrapRequestIsWhollyBeyondScopeEvenWhereThePolicyGrantsEverything(): void
    {
        $read = new ReadScopeGate($this->policyGrantingEverything(), new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()))
            ->admit(self::client(), self::filters(['kinds' => [EventKind::GIFT_WRAP]]), 0);

        $this->assertTrue($read instanceof AdmittedRead && $read->isWhollyBeyondScope());
    }

    public function testThePolicysRefusalIsTheAnswer(): void
    {
        $refusal = PolicyRejection::blocked('too many subscriptions (max 1)');
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowSubscription')->willReturn($refusal);

        $answer = new ReadScopeGate($policy, new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()))
            ->admit(self::client(), self::filters(['kinds' => [1]]), 1);

        $this->assertSame($refusal, $answer);
    }

    public function testThePolicyIsAskedWithTheSubscriptionsTheClientHolds(): void
    {
        $policy = $this->createMock(RelayPolicyInterface::class);
        $policy->expects($this->once())->method('allowSubscription')->with(self::client(), self::filters(['kinds' => [1]]), 3)->willReturn(PolicyRejection::blocked('no'));

        new ReadScopeGate($policy, new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()))
            ->admit(self::client(), self::filters(['kinds' => [1]]), 3);
    }

    private function policyGrantingEverything(): RelayPolicyInterface
    {
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowSubscription')->willReturn(null);
        $policy->method('filterForClient')->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters));

        return $policy;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function filters(array $filter): FilterCollection
    {
        return new FilterCollection([Filter::tryFromArray($filter) ?? throw new RuntimeException('filter did not parse')]);
    }

    private static function client(): RelayClient
    {
        return new RelayClient(
            ClientId::fromString('reader'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::fromInt(1_700_000_000)),
        );
    }
}
