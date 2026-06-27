<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class RelayPolicyTest extends TestCase
{
    private const string TENANT_HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string STRANGER_HEX = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private AuthenticationManager $authManager;

    protected function setUp(): void
    {
        $this->authManager = new AuthenticationManager(new NativeRandomBytesGenerator());
    }

    public function testTenantIsExemptFromSubscriptionCap(): void
    {
        $policy = $this->policyWithGuestRules(['max_subscriptions' => 2]);

        $policy->allowSubscription($this->tenantClient(), new FilterCollection(), 99);

        $this->expectNotToPerformAssertions();
    }

    public function testOpenRelayIsExemptFromSubscriptionCap(): void
    {
        $policy = new RelayPolicy($this->authManager, new NullLogger(), ['max_subscriptions' => 2]);

        $policy->allowSubscription($this->guestClient(), new FilterCollection(), 99);

        $this->expectNotToPerformAssertions();
    }

    public function testGuestIsSubjectToSubscriptionCap(): void
    {
        $policy = $this->policyWithGuestRules(['max_subscriptions' => 2]);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('too many subscriptions (max 2)');

        $policy->allowSubscription($this->guestClient(), new FilterCollection(), 2);
    }

    public function testRejectsOversizedEvent(): void
    {
        $policy = $this->policyWithGuestRules(['max_event_size' => 4]);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('event too large');

        $policy->allowEventSubmission($this->guestClient(), $this->event(EventKind::TEXT_NOTE, 'too long content'));
    }

    public function testGuestMayPublishConfiguredWriteKind(): void
    {
        $policy = $this->policyWithGuestRules();

        $policy->allowEventSubmission($this->guestClient(), $this->event(EventKind::REACTION, 'x'));

        $this->expectNotToPerformAssertions();
    }

    public function testGuestPublishingUnconfiguredKindRequiresAuth(): void
    {
        $policy = $this->policyWithGuestRules();

        $this->expectException(AuthRequiredException::class);

        $policy->allowEventSubmission($this->guestClient(), $this->event(EventKind::TEXT_NOTE, 'x'));
    }

    public function testGuestWriteRequiringTenantTagIsRejectedWhenUntagged(): void
    {
        $policy = $this->policyWithGuestRules();

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('event must be tagged to a relay tenant');

        $policy->allowEventSubmission($this->guestClient(), $this->event(EventKind::ZAP_RECEIPT, 'x'));
    }

    public function testGuestWriteRequiringTenantTagIsAcceptedWhenTagged(): void
    {
        $policy = $this->policyWithGuestRules();
        $tagged = $this->event(EventKind::ZAP_RECEIPT, 'x', new TagCollection([Tag::pubkey(self::TENANT_HEX)]));

        $policy->allowEventSubmission($this->guestClient(), $tagged);

        $this->expectNotToPerformAssertions();
    }

    public function testTenantBypassesWriteRules(): void
    {
        $policy = $this->policyWithGuestRules();

        $policy->allowEventSubmission($this->tenantClient(), $this->event(EventKind::TEXT_NOTE, 'x'));

        $this->expectNotToPerformAssertions();
    }

    public function testFilterForClientLeavesTenantFiltersUnchanged(): void
    {
        $policy = $this->policyWithGuestRules();
        $filters = new FilterCollection([Filter::fromArray(['kinds' => [1, 2, 3]]) ?? throw new RuntimeException('bad filter')]);

        $scoped = $policy->filterForClient($this->tenantClient(), $filters);

        $this->assertFalse($scoped->isBeyondScope());
    }

    public function testFilterForClientFlagsGuestRequestBeyondReadableScope(): void
    {
        $policy = $this->policyWithGuestRules();
        $filters = new FilterCollection([Filter::fromArray(['kinds' => [1, 2]]) ?? throw new RuntimeException('bad filter')]);

        $scoped = $policy->filterForClient($this->guestClient(), $filters);

        $this->assertTrue($scoped->isBeyondScope());
    }

    public function testGuestMayReceiveReadableEventAuthoredByTenant(): void
    {
        $policy = $this->policyWithGuestRules();
        $event = $this->event(EventKind::TEXT_NOTE, 'x', author: self::TENANT_HEX);

        $this->assertTrue($policy->canClientReceiveEvent($this->guestClient(), $event));
    }

    public function testGuestMayNotReceiveReadableEventAuthoredByStranger(): void
    {
        $policy = $this->policyWithGuestRules();
        $event = $this->event(EventKind::TEXT_NOTE, 'x', author: self::STRANGER_HEX);

        $this->assertFalse($policy->canClientReceiveEvent($this->guestClient(), $event));
    }

    public function testAllowsAuthenticationOnlyForTenantPubkeys(): void
    {
        $policy = $this->policyWithGuestRules();

        $this->assertTrue($policy->allowsAuthentication($this->publicKey(self::TENANT_HEX)));
        $this->assertFalse($policy->allowsAuthentication($this->publicKey(self::STRANGER_HEX)));
    }

    public function testRateLimitExemptionTracksTenancy(): void
    {
        $policy = $this->policyWithGuestRules();

        $this->assertTrue($policy->isRateLimitExempt($this->tenantClient()));
        $this->assertFalse($policy->isRateLimitExempt($this->guestClient()));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function policyWithGuestRules(array $overrides = []): RelayPolicy
    {
        return new RelayPolicy($this->authManager, new NullLogger(), [
            'tenants' => [self::TENANT_HEX],
            'guest' => [
                'read' => [['kinds' => [EventKind::TEXT_NOTE], 'from' => 'tenants']],
                'write' => [
                    ['kinds' => [EventKind::REACTION]],
                    ['kinds' => [EventKind::ZAP_RECEIPT], 'tagged_to_tenant' => true],
                ],
            ],
            ...$overrides,
        ]);
    }

    private function guestClient(): RelayClient
    {
        return new RelayClient(ClientId::fromString('guest'), $this->connectionInfo());
    }

    private function tenantClient(): RelayClient
    {
        $client = new RelayClient(ClientId::fromString('tenant'), $this->connectionInfo());
        $this->authManager->authenticate($client->getId(), $this->publicKey(self::TENANT_HEX));

        return $client;
    }

    private function connectionInfo(): ConnectionInfo
    {
        return new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now());
    }

    private function event(int $kind, string $content, ?TagCollection $tags = null, string $author = self::STRANGER_HEX): Event
    {
        return new Event(
            $this->publicKey($author),
            Timestamp::now(),
            EventKind::fromInt($kind),
            $tags ?? new TagCollection(),
            EventContent::fromString($content),
        );
    }

    private function publicKey(string $hex): PublicKey
    {
        return PublicKey::fromHex($hex) ?? throw new RuntimeException('Invalid pubkey');
    }
}
