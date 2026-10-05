<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage as ClientAuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage as RelayAuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlerInterface;
use Innis\Nostr\Relay\Application\Service\EventValidityGate;
use Innis\Nostr\Relay\Application\Service\Nip42Handshake;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use InvalidArgumentException;
use Override;

final readonly class ProcessAuthUseCase implements ClientVerbHandlerInterface
{
    // Deliberate: the signature is verified after the handshake's cheap checks and before the identity question — see ADR-0018
    public function __construct(
        private Nip42Handshake $handshake,
        private EventValidityGate $validityGate,
        private SubscriptionReevaluator $subscriptionReevaluator,
    ) {
    }

    /**
     * @return class-string<ClientMessage>
     */
    #[Override]
    public function handledMessageType(): string
    {
        return ClientAuthMessage::class;
    }

    /**
     * @return list<RelayMessage>
     */
    #[Override]
    public function handle(RelayClient $client, ClientMessage $message): array
    {
        return match (true) {
            $message instanceof ClientAuthMessage => $this->execute($client, $message->getEvent()),
            default => throw new InvalidArgumentException('ProcessAuthUseCase cannot handle '.$message::class),
        };
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, Event $event): array
    {
        $claim = $this->handshake->verifyClaim($client, $event);

        if ($claim instanceof RelayAuthMessage) {
            return [
                $claim,
                OkMessage::refused($event->getId(), ReasonPrefix::AuthRequired, 'challenge issued, please retry'),
            ];
        }

        if ($claim instanceof PolicyRejection) {
            return [$claim->toOkMessage($event->getId())];
        }

        try {
            $this->validityGate->admit($event);
        } catch (InvalidEventException $e) {
            return [OkMessage::refused($event->getId(), ReasonPrefix::Invalid, $e->getMessage())];
        }

        $authentication = $this->handshake->authenticate($client, $event);

        if ($authentication instanceof PolicyRejection) {
            return [$authentication->toOkMessage($event->getId())];
        }

        return [...$this->subscriptionReevaluator->reevaluate($client), OkMessage::accepted($event->getId())];
    }
}
