<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\RateLimitToken;
use PHPUnit\Framework\TestCase;

final class RateLimitTokenTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $token = new RateLimitToken(5.0, 1000.0);

        $this->assertSame(5.0, $token->getTokens());
        $this->assertSame(1000.0, $token->getLastRefill());
    }

    public function testHasTokensReturnsTrueWhenSufficient(): void
    {
        $token = new RateLimitToken(1.0, 0.0);

        $this->assertTrue($token->hasTokens());
    }

    public function testHasTokensReturnsTrueWhenMoreThanOne(): void
    {
        $token = new RateLimitToken(5.5, 0.0);

        $this->assertTrue($token->hasTokens());
    }

    public function testHasTokensReturnsFalseWhenInsufficient(): void
    {
        $token = new RateLimitToken(0.5, 0.0);

        $this->assertFalse($token->hasTokens());
    }

    public function testHasTokensReturnsFalseWhenZero(): void
    {
        $token = new RateLimitToken(0.0, 0.0);

        $this->assertFalse($token->hasTokens());
    }

    public function testWithConsumedTokenReturnsNewInstance(): void
    {
        $token = new RateLimitToken(3.0, 100.0);
        $consumed = $token->withConsumedToken();

        $this->assertSame(3.0, $token->getTokens());
        $this->assertSame(2.0, $consumed->getTokens());
        $this->assertSame(100.0, $consumed->getLastRefill());
    }

    public function testWithAddedTokensReturnsNewInstance(): void
    {
        $token = new RateLimitToken(2.0, 100.0);
        $refilled = $token->withAddedTokens(3.0, 200.0);

        $this->assertSame(2.0, $token->getTokens());
        $this->assertSame(5.0, $refilled->getTokens());
        $this->assertSame(200.0, $refilled->getLastRefill());
    }
}
