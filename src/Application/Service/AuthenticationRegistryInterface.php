<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

interface AuthenticationRegistryInterface extends AuthChallengeInterface, AuthenticatedSessionsInterface
{
}
