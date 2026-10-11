<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use PHPUnit\Framework\TestCase;

final class ClientIdTest extends TestCase
{
    public function testFromBytesCreatesDistinctIdsForDistinctBytes(): void
    {
        $id1 = ClientId::fromBytes(str_repeat("\x01", 16));
        $id2 = ClientId::fromBytes(str_repeat("\x02", 16));

        $this->assertFalse($id1->equals($id2));
    }

    public function testFromBytesHexEncodesTheBytes(): void
    {
        $id = ClientId::fromBytes(str_repeat("\xab", 16));

        $this->assertSame(str_repeat('ab', 16), (string) $id);
    }

    public function testFromBytesWritesLowercaseHexTwiceTheLengthOfTheBytes(): void
    {
        $id = ClientId::fromBytes("\x01\x23\x45\x67\x89\xab\xcd\xef\xfe\xdc\xba\x98\x76\x54\x32\x10");

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $id);
    }

    public function testFromStringPreservesValue(): void
    {
        $value = 'abc123';
        $id = ClientId::fromString($value);

        $this->assertSame($value, (string) $id);
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $id1 = ClientId::fromString('test-id');
        $id2 = ClientId::fromString('test-id');

        $this->assertTrue($id1->equals($id2));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $id1 = ClientId::fromString('id-one');
        $id2 = ClientId::fromString('id-two');

        $this->assertFalse($id1->equals($id2));
    }
}
