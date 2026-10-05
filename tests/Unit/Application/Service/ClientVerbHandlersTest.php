<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlerInterface;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlers;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;

final class ClientVerbHandlersTest extends TestCase
{
    public function testResolvesAHandlerByItsHandledMessageType(): void
    {
        $handler = $this->createStub(ClientVerbHandlerInterface::class);
        $handler->method('handledMessageType')->willReturn(ReqMessage::class);

        $handlers = new ClientVerbHandlers($handler);

        $this->assertSame(
            $handler,
            $handlers->handlerFor(ReqMessage::from(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]))),
        );
    }

    public function testAnUnknownMessageTypeResolvesToNull(): void
    {
        $handler = $this->createStub(ClientVerbHandlerInterface::class);
        $handler->method('handledMessageType')->willReturn(ReqMessage::class);

        $handlers = new ClientVerbHandlers($handler);

        $this->assertNull($handlers->handlerFor(new CloseMessage(SubscriptionIdMother::from('sub-1'))));
    }
}
