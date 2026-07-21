<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Enum;

use Innis\Nostr\Relay\Domain\Enum\AuthRejection;
use PHPUnit\Framework\TestCase;

final class AuthRejectionTest extends TestCase
{
    public function testAuthFailuresCarryTheAuthRequiredPrefix(): void
    {
        self::assertSame('auth-required: invalid challenge', AuthRejection::InvalidChallenge->toWireReason());
        self::assertSame('auth-required: invalid relay URL', AuthRejection::InvalidRelayUrl->toWireReason());
        self::assertSame('auth-required: timestamp out of range', AuthRejection::TimestampOutOfRange->toWireReason());
    }

    public function testRestrictionCarriesTheRestrictedPrefix(): void
    {
        self::assertSame('restricted: authentication is limited to relay tenants', AuthRejection::Restricted->toWireReason());
    }
}
