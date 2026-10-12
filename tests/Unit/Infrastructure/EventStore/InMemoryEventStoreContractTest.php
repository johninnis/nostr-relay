<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\EventStore;

use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Tests\Contract\RelayEventStoreContract;
use Innis\Nostr\Relay\Tests\Contract\RelayEventStoreContractCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InMemoryEventStoreContractTest extends TestCase
{
    /**
     * @return array<string, array{RelayEventStoreContractCase}>
     */
    public static function contract(): array
    {
        return RelayEventStoreContract::cases();
    }

    #[DataProvider('contract')]
    public function testTheStoreKeepsTheRelayEventStoreContract(RelayEventStoreContractCase $case): void
    {
        $this->assertSame($case->getExpected(), $case->observe(new InMemoryEventStore()));
    }
}
