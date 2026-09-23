<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Support;

use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;

final class KeyMotherTest extends TestCase
{
    public function testAlicesPublicKeyConstantIsTheOneHerPrivateKeyDerives(): void
    {
        $this->assertTrue(KeyMother::alice()->getPublicKey()->equals(KeyMother::alicePublicKey()));
    }

    public function testBobsPublicKeyConstantIsTheOneHisPrivateKeyDerives(): void
    {
        $this->assertTrue(KeyMother::bob()->getPublicKey()->equals(KeyMother::bobPublicKey()));
    }
}
