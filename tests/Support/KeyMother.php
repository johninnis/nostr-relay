<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use RuntimeException;

final class KeyMother
{
    public const ALICE_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000001';
    public const ALICE_PUBLIC_KEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    public const BOB_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000002';
    public const BOB_PUBLIC_KEY_HEX = 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5';

    public const CAROL_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000003';
    public const CAROL_PUBLIC_KEY_HEX = 'f9308a019258c31049344f85f89d5229b531c845836f99b08601f113bce036f9';

    public static function alice(): KeyPair
    {
        return self::keyPair(self::ALICE_PRIVATE_KEY_HEX, self::ALICE_PUBLIC_KEY_HEX);
    }

    public static function bob(): KeyPair
    {
        return self::keyPair(self::BOB_PRIVATE_KEY_HEX, self::BOB_PUBLIC_KEY_HEX);
    }

    public static function carol(): KeyPair
    {
        return self::keyPair(self::CAROL_PRIVATE_KEY_HEX, self::CAROL_PUBLIC_KEY_HEX);
    }

    public static function alicePublicKey(): PublicKey
    {
        return self::alice()->getPublicKey();
    }

    public static function bobPublicKey(): PublicKey
    {
        return self::bob()->getPublicKey();
    }

    public static function carolPublicKey(): PublicKey
    {
        return self::carol()->getPublicKey();
    }

    private static function keyPair(string $privateKeyHex, string $publicKeyHex): KeyPair
    {
        return new KeyPair(
            PrivateKey::fromHex($privateKeyHex) ?? throw new RuntimeException('Invalid test private key'),
            PublicKey::fromHex($publicKeyHex) ?? throw new RuntimeException('Invalid test public key'),
        );
    }
}
