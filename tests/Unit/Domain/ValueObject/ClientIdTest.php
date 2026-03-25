<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use PHPUnit\Framework\TestCase;

final class ClientIdTest extends TestCase
{
    public function testGenerateCreatesUniqueIds(): void
    {
        $id1 = ClientId::generate();
        $id2 = ClientId::generate();

        $this->assertFalse($id1->equals($id2));
    }

    public function testGenerateCreatesHexString(): void
    {
        $id = ClientId::generate();

        $this->assertSame(32, strlen($id->toString()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id->toString());
    }

    public function testFromStringPreservesValue(): void
    {
        $value = 'abc123';
        $id = ClientId::fromString($value);

        $this->assertSame($value, $id->toString());
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
