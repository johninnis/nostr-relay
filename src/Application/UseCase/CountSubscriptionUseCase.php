<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage as RelayCountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlerInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use InvalidArgumentException;
use Override;

final readonly class CountSubscriptionUseCase implements ClientVerbHandlerInterface
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private SubscriptionAdmission $admission,
        private SubscriptionAnswers $subscriptionAnswers,
    ) {
    }

    /**
     * @return class-string<ClientMessage>
     */
    #[Override]
    public function handledMessageType(): string
    {
        return CountMessage::class;
    }

    /**
     * @return list<RelayMessage>
     */
    #[Override]
    public function handle(RelayClient $client, ClientMessage $message): array
    {
        return match (true) {
            $message instanceof CountMessage => $this->execute($client, $message->getSubscriptionId(), $message->getFilters()),
            default => throw new InvalidArgumentException('CountSubscriptionUseCase cannot handle '.$message::class),
        };
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): array
    {
        $admission = $this->admission->admit($client, $filters);

        if ($admission instanceof PolicyRejection) {
            return $this->subscriptionAnswers->settleRefusal($client, $subscriptionId, $admission);
        }

        return [
            ...$this->subscriptionAnswers->forScope($client, $admission),
            new RelayCountMessage($subscriptionId, $this->eventStore->countByFilters($admission->getFilters())),
        ];
    }
}
