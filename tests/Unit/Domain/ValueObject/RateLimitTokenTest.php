<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\RateLimitToken;
use PHPUnit\Framework\TestCase;

final class RateLimitTokenTest extends TestCase
{
    public function testKeepsItsTokens(): void
    {
        $this->assertSame(5.0, new RateLimitToken(5.0, 1000.0)->getTokens());
    }

    public function testKeepsItsLastRefill(): void
    {
        $this->assertSame(1000.0, new RateLimitToken(5.0, 1000.0)->getLastRefill());
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

    public function testWithConsumedTokenLeavesTheOriginalUnchanged(): void
    {
        $token = new RateLimitToken(3.0, 100.0);
        $token->withConsumedToken();

        $this->assertSame(3.0, $token->getTokens());
    }

    public function testWithConsumedTokenSpendsOneToken(): void
    {
        $this->assertSame(2.0, new RateLimitToken(3.0, 100.0)->withConsumedToken()->getTokens());
    }

    public function testWithConsumedTokenKeepsTheLastRefill(): void
    {
        $this->assertSame(100.0, new RateLimitToken(3.0, 100.0)->withConsumedToken()->getLastRefill());
    }

    public function testRefilledLeavesTheOriginalUnchanged(): void
    {
        $token = new RateLimitToken(2.0, 100.0);
        $token->refilled(now: 110.0, capacity: 30.0);

        $this->assertSame(2.0, $token->getTokens());
    }

    public function testRefilledAddsTokensProportionalToElapsedTime(): void
    {
        $this->assertSame(7.0, new RateLimitToken(2.0, 100.0)->refilled(now: 110.0, capacity: 30.0)->getTokens());
    }

    public function testRefilledMovesTheLastRefillToNow(): void
    {
        $this->assertSame(110.0, new RateLimitToken(2.0, 100.0)->refilled(now: 110.0, capacity: 30.0)->getLastRefill());
    }

    public function testRefilledCapsAtCapacity(): void
    {
        $token = new RateLimitToken(8.0, 0.0);

        $refilled = $token->refilled(now: 1000.0, capacity: 10.0);

        $this->assertSame(10.0, $refilled->getTokens());
    }
}
