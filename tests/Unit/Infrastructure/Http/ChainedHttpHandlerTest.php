<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\Http;

use Innis\Nostr\Relay\Application\DTO\HttpRequestContext;
use Innis\Nostr\Relay\Application\DTO\HttpResponsePayload;
use Innis\Nostr\Relay\Application\Port\HttpRequestHandlerInterface;
use Innis\Nostr\Relay\Infrastructure\Http\ChainedHttpHandler;
use PHPUnit\Framework\TestCase;

final class ChainedHttpHandlerTest extends TestCase
{
    public function testReturnsNullWhenNoHandlerClaimsTheRequest(): void
    {
        $chain = new ChainedHttpHandler($this->decliningHandler(), $this->decliningHandler());

        $this->assertNull($chain->handleHttpRequest($this->request()));
    }

    public function testReturnsNullWhenTheChainIsEmpty(): void
    {
        $this->assertNull(new ChainedHttpHandler()->handleHttpRequest($this->request()));
    }

    public function testReturnsTheResponseOfTheFirstClaimingHandler(): void
    {
        $chain = new ChainedHttpHandler(
            $this->decliningHandler(),
            $this->respondingHandler('first'),
            $this->respondingHandler('second'),
        );

        $this->assertSame('first', $chain->handleHttpRequest($this->request())?->getBody());
    }

    public function testDoesNotConsultHandlersAfterOneClaimsTheRequest(): void
    {
        $unreachable = $this->createMock(HttpRequestHandlerInterface::class);
        $unreachable->expects($this->never())->method('handleHttpRequest');

        $chain = new ChainedHttpHandler($this->respondingHandler('claimed'), $unreachable);

        $chain->handleHttpRequest($this->request());
    }

    private function request(): HttpRequestContext
    {
        return new HttpRequestContext('GET', '/', [], '');
    }

    private function decliningHandler(): HttpRequestHandlerInterface
    {
        $handler = $this->createStub(HttpRequestHandlerInterface::class);
        $handler->method('handleHttpRequest')->willReturn(null);

        return $handler;
    }

    private function respondingHandler(string $body): HttpRequestHandlerInterface
    {
        $handler = $this->createStub(HttpRequestHandlerInterface::class);
        $handler->method('handleHttpRequest')->willReturn(new HttpResponsePayload(200, [], $body));

        return $handler;
    }
}
