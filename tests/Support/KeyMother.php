<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use RuntimeException;

final class KeyMother
{
    public const string ALICE_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000001';
    public const string ALICE_PUBLIC_KEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    public const string BOB_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000002';
    public const string BOB_PUBLIC_KEY_HEX = 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5';

    public static function alice(): KeyPair
    {
        return self::keyPair(self::ALICE_PRIVATE_KEY_HEX, self::ALICE_PUBLIC_KEY_HEX);
    }

    public static function bob(): KeyPair
    {
        return self::keyPair(self::BOB_PRIVATE_KEY_HEX, self::BOB_PUBLIC_KEY_HEX);
    }

    public static function alicePublicKey(): PublicKey
    {
        return self::alice()->getPublicKey();
    }

    public static function bobPublicKey(): PublicKey
    {
        return self::bob()->getPublicKey();
    }

    private static function keyPair(string $privateKeyHex, string $publicKeyHex): KeyPair
    {
        return new KeyPair(
            PrivateKey::tryFromHex($privateKeyHex) ?? throw new RuntimeException('Invalid test private key'),
            PublicKey::tryFromHex($publicKeyHex) ?? throw new RuntimeException('Invalid test public key'),
        );
    }
}
