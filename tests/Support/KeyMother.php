<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use RuntimeException;

final class KeyMother
{
    public const string ALICE_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000001';
    public const string ALICE_PUBLIC_KEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    public const string BOB_PRIVATE_KEY_HEX = '0000000000000000000000000000000000000000000000000000000000000002';
    public const string BOB_PUBLIC_KEY_HEX = 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5';

    private static ?SignatureServiceInterface $signer = null;

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

    // Deliberate: derives rather than asserting the pair, so the fixture's public-key constant is checked against its private key instead of being taken on trust — KeyPair's constructor is private so a pair cannot disagree with itself
    private static function keyPair(string $privateKeyHex, string $publicKeyHex): KeyPair
    {
        $keyPair = KeyPair::fromPrivateKey(
            PrivateKey::tryFromHex($privateKeyHex) ?? throw new RuntimeException('Invalid test private key'),
            self::signer(),
        );

        $expected = PublicKey::tryFromHex($publicKeyHex) ?? throw new RuntimeException('Invalid test public key');

        if (!$keyPair->getPublicKey()->equals($expected)) {
            throw new RuntimeException('Test fixture public key does not derive from its private key');
        }

        return $keyPair;
    }

    // Deliberate: stateless and memoised so each fixture call does not re-probe for libsecp256k1; the KeyPair itself is never cached because zero() mutates its private key
    private static function signer(): SignatureServiceInterface
    {
        return self::$signer ??= Secp256k1Signer::create();
    }
}
