<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\EventStore;

use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Infrastructure\EventStore\LoggingEventStore;
use Innis\Nostr\Relay\Tests\Contract\RelayEventStoreContract;
use Innis\Nostr\Relay\Tests\Contract\RelayEventStoreContractCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LoggingEventStoreContractTest extends TestCase
{
    /**
     * @return array<string, array{RelayEventStoreContractCase}>
     */
    public static function contract(): array
    {
        return RelayEventStoreContract::cases();
    }

    #[DataProvider('contract')]
    public function testTheDecoratedStoreKeepsTheRelayEventStoreContract(RelayEventStoreContractCase $case): void
    {
        $this->assertSame($case->getExpected(), $case->observe(new LoggingEventStore(new InMemoryEventStore(), new NullLogger())));
    }
}
