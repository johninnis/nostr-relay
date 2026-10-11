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
    public function testAnAdmittedAnswerIsAdmitted(): void
    {
        $this->assertTrue(PublishingAnswer::admitted()->isAdmitted());
    }

    public function testAnAdmittedAnswerCarriesNoRejection(): void
    {
        $this->assertNull(PublishingAnswer::admitted()->getRejection());
    }

    public function testAnAdmittedAnswerCarriesNoChallenge(): void
    {
        $this->assertNull(PublishingAnswer::admitted(offersChallenge: true)->getChallenge());
    }

    public function testAnAdmittedAnswerOffersNoChallengeUnlessThePolicyDoes(): void
    {
        $this->assertFalse(PublishingAnswer::admitted()->offersChallenge());
    }

    public function testARefusedAnswerIsNotAdmitted(): void
    {
        $this->assertFalse(PublishingAnswer::refused(PolicyRejection::blocked('event too large'))->isAdmitted());
    }

    public function testARefusedAnswerCarriesTheRejection(): void
    {
        $rejection = PolicyRejection::blocked('event too large');

        $this->assertSame($rejection, PublishingAnswer::refused($rejection)->getRejection());
    }

    public function testAnAdmittedAnswerCanCarryThePolicysChallengeOffer(): void
    {
        $this->assertTrue(PublishingAnswer::admitted(offersChallenge: true)->offersChallenge());
    }

    public function testARefusedAnswerOffersNoChallengeForAcceptance(): void
    {
        $this->assertFalse(PublishingAnswer::refused(PolicyRejection::blocked('event too large'))->offersChallenge());
    }

    public function testARefusedAnswerCanCarryAChallenge(): void
    {
        $challenge = new AuthMessage(Challenge::fromString('challenge-1'));

        $answer = PublishingAnswer::refused(PolicyRejection::authRequired('members only'), $challenge);

        $this->assertSame($challenge, $answer->getChallenge());
    }
}
