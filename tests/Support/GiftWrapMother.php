<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use RuntimeException;

final class GiftWrapMother
{
    public const string WRAP_SIGNER_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000003';
    public const string NOTE_AUTHOR_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000004';

    private static ?SignatureServiceInterface $signer = null;

    /** @var array<string, Event> */
    private static array $events = [];

    /** @var array<string, PublicKey> */
    private static array $publicKeys = [];

    public static function storedToAlice(): Event
    {
        return self::wrap(EventKind::GIFT_WRAP, 'stored', KeyMother::alicePublicKey());
    }

    public static function storedToBob(): Event
    {
        return self::wrap(EventKind::GIFT_WRAP, 'stored', KeyMother::bobPublicKey());
    }

    public static function storedToBobAndAlice(): Event
    {
        return self::wrap(EventKind::GIFT_WRAP, 'stored', KeyMother::bobPublicKey(), KeyMother::alicePublicKey());
    }

    public static function liveToAlice(int $kind): Event
    {
        return self::wrap($kind, 'live', KeyMother::alicePublicKey());
    }

    public static function liveToBob(int $kind): Event
    {
        return self::wrap($kind, 'live', KeyMother::bobPublicKey());
    }

    public static function liveToBobAndAlice(int $kind): Event
    {
        return self::wrap($kind, 'live', KeyMother::bobPublicKey(), KeyMother::alicePublicKey());
    }

    public static function noteTaggingBob(): Event
    {
        return self::$events['note'] ??= Rumour::draft(
            self::publicKeyOf(self::NOTE_AUTHOR_PRIVATE_KEY_HEX),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('a note naming Bob'),
            new TagCollection([Tag::pubkey(KeyMother::bobPublicKey())]),
        )->sign(self::keyPair(self::NOTE_AUTHOR_PRIVATE_KEY_HEX), self::signer());
    }

    public static function wrapSigner(): PublicKey
    {
        return self::publicKeyOf(self::WRAP_SIGNER_PRIVATE_KEY_HEX);
    }

    /**
     * @return list<Event>
     */
    public static function everyWrap(): array
    {
        return [
            self::storedToAlice(),
            self::storedToBob(),
            self::storedToBobAndAlice(),
            ...array_merge(...array_map(
                static fn (int $kind): array => [self::liveToAlice($kind), self::liveToBob($kind), self::liveToBobAndAlice($kind)],
                [EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP],
            )),
        ];
    }

    private static function wrap(int $kind, string $label, PublicKey ...$recipients): Event
    {
        $key = implode(':', [$kind, $label, ...array_map(static fn (PublicKey $recipient): string => $recipient->toHex(), $recipients)]);

        return self::$events[$key] ??= Rumour::draft(
            self::wrapSigner(),
            EventKind::fromInt($kind),
            EventContent::fromString('sealed '.$key),
            new TagCollection(array_map(Tag::pubkey(...), array_values($recipients))),
        )->sign(self::keyPair(self::WRAP_SIGNER_PRIVATE_KEY_HEX), self::signer());
    }

    private static function publicKeyOf(string $privateKeyHex): PublicKey
    {
        return self::$publicKeys[$privateKeyHex] ??= self::keyPair($privateKeyHex)->getPublicKey();
    }

    private static function keyPair(string $privateKeyHex): KeyPair
    {
        return KeyPair::fromPrivateKey(PrivateKey::tryFromHex($privateKeyHex) ?? throw new RuntimeException('Invalid test private key'), self::signer());
    }

    private static function signer(): SignatureServiceInterface
    {
        return self::$signer ??= Secp256k1Signer::create();
    }
}
