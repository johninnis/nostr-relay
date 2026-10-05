<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;

$storedCount = isset($argv[1]) ? max(1, (int) $argv[1]) : 1000;
$subscriberCount = isset($argv[2]) ? max(1, (int) $argv[2]) : 50;
$rounds = isset($argv[3]) ? max(1, (int) $argv[3]) : 7;

$keyPair = KeyPair::generate(Secp256k1Signer::create());
$signature = Signature::tryFromHex(str_repeat('ab', 64)) ?? throw new RuntimeException('Invalid signature fixture');

$event = static function (int $index) use ($keyPair, $signature): Event {
    $rumour = Rumour::draft(
        $keyPair->getPublicKey(),
        EventKind::fromInt(EventKind::TEXT_NOTE),
        EventContent::fromString(str_repeat("Benchmark note {$index}, with some prose to make it realistic. ", 28)),
        new TagCollection(array_map(
            static fn (int $tag): ?Tag => Tag::tryFromArray(['t', "topic{$tag}"]),
            range(1, 6),
        )),
        Timestamp::fromInt(1_700_000_000 + $index),
    );

    return new Event($rumour, $rumour->getId(), $signature);
};

$storedBytes = array_map(static fn (int $index): string => EncodedEvent::of($event($index))->toJson(), range(1, $storedCount));
$subscription = SubscriptionId::tryFromString('bench-sub') ?? throw new RuntimeException('Invalid subscription id');
$subscriptions = array_map(
    static fn (int $index): SubscriptionId => SubscriptionId::tryFromString("sub-{$index}") ?? throw new RuntimeException('Invalid subscription id'),
    range(1, $subscriberCount),
);
$live = $event(0);

$medianMicroseconds = static function (callable $work) use ($rounds): float {
    $timings = [];

    for ($round = 0; $round < $rounds; ++$round) {
        $start = hrtime(true);
        $work();
        $timings[] = (hrtime(true) - $start) / 1_000;
    }

    sort($timings);

    return $timings[intdiv($rounds, 2)];
};

$streamBefore = $medianMicroseconds(static function () use ($storedBytes, $subscription): void {
    foreach ($storedBytes as $bytes) {
        $parsed = Event::tryFromJson($bytes) ?? throw new RuntimeException('Stored bytes did not parse');
        new EventMessage($subscription, $parsed)->toJson();
    }
});

$streamAfter = $medianMicroseconds(static function () use ($storedBytes, $subscription): void {
    foreach ($storedBytes as $bytes) {
        EncodedEvent::fromOwnStore($bytes)->framedFor($subscription);
    }
});

$distributeBefore = $medianMicroseconds(static function () use ($live, $subscriptions): void {
    foreach ($subscriptions as $subscriptionId) {
        new EventMessage($subscriptionId, $live)->toJson();
    }
});

$distributeAfter = $medianMicroseconds(static function () use ($live, $subscriptions): void {
    $encoded = EncodedEvent::of($live);

    foreach ($subscriptions as $subscriptionId) {
        $encoded->framedFor($subscriptionId);
    }
});

printf("Event size: %d bytes; median of %d rounds\n\n", strlen($storedBytes[0]), $rounds);
printf("Streaming %d stored events, stored bytes to EVENT frame\n", $storedCount);
printf("  parse and re-encode:  %8.1f ms total  %6.2f us per event\n", $streamBefore / 1_000, $streamBefore / $storedCount);
printf("  frame stored bytes:   %8.1f ms total  %6.2f us per event\n\n", $streamAfter / 1_000, $streamAfter / $storedCount);
printf("Distributing one live event to %d subscribers\n", $subscriberCount);
printf("  encode per recipient: %8.1f us total  %6.2f us per recipient\n", $distributeBefore, $distributeBefore / $subscriberCount);
printf("  encode once:          %8.1f us total  %6.2f us per recipient\n", $distributeAfter, $distributeAfter / $subscriberCount);
