<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\PublishingAnswer;
use PHPUnit\Framework\TestCase;

final class PublishingAnswerTest extends TestCase
{
    public function testAnAdmittedAnswerCarriesNoRejection(): void
    {
        $answer = PublishingAnswer::admitted();

        $this->assertTrue($answer->isAdmitted());
        $this->assertNull($answer->getRejection());
        $this->assertNull($answer->getChallenge());
    }

    public function testARefusedAnswerCarriesTheRejection(): void
    {
        $rejection = PolicyRejection::blocked('event too large');

        $answer = PublishingAnswer::refused($rejection);

        $this->assertFalse($answer->isAdmitted());
        $this->assertSame($rejection, $answer->getRejection());
    }

    public function testAnAdmittedAnswerCanCarryAChallenge(): void
    {
        $challenge = new AuthMessage(Challenge::fromString('challenge-1'));

        $this->assertSame($challenge, PublishingAnswer::admitted($challenge)->getChallenge());
    }

    public function testARefusedAnswerCanCarryAChallenge(): void
    {
        $challenge = new AuthMessage(Challenge::fromString('challenge-1'));

        $answer = PublishingAnswer::refused(PolicyRejection::authRequired('members only'), $challenge);

        $this->assertSame($challenge, $answer->getChallenge());
    }
}
