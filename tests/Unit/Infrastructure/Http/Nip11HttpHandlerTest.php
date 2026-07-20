<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\Http;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\DTO\HttpRequestContext;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Innis\Nostr\Relay\Infrastructure\Http\Nip11HttpHandler;
use PHPUnit\Framework\TestCase;

final class Nip11HttpHandlerTest extends TestCase
{
    public function testServesTheRelayDocumentWhenNegotiated(): void
    {
        $response = $this->handler()->handleHttpRequest($this->request());

        $this->assertSame('application/nostr+json', $response?->getHeaders()['content-type'] ?? null);
    }

    public function testTheDocumentCarriesTheProvidedRelayInformation(): void
    {
        $response = $this->handler()->handleHttpRequest($this->request());

        $this->assertStringContainsString('Test Relay', $response?->getBody() ?? '');
    }

    public function testIgnoresARequestWithoutTheNostrAcceptHeader(): void
    {
        $request = $this->request(headers: ['accept' => 'text/html']);

        $this->assertNull($this->handler()->handleHttpRequest($request));
    }

    public function testIgnoresARequestForAnotherPath(): void
    {
        $request = $this->request(path: '/favicon.ico');

        $this->assertNull($this->handler()->handleHttpRequest($request));
    }

    public function testIgnoresANonGetRequest(): void
    {
        $request = $this->request(method: 'POST');

        $this->assertNull($this->handler()->handleHttpRequest($request));
    }

    public function testIgnoresARequestWithNoAcceptHeaderAtAll(): void
    {
        $request = $this->request(headers: []);

        $this->assertNull($this->handler()->handleHttpRequest($request));
    }

    public function testNegotiatesWhenTheAcceptHeaderListsSeveralTypes(): void
    {
        $request = $this->request(headers: ['accept' => 'text/html, application/nostr+json;q=0.9']);

        $this->assertNotNull($this->handler()->handleHttpRequest($request));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(
        string $method = 'GET',
        string $path = '/',
        array $headers = ['accept' => 'application/nostr+json'],
    ): HttpRequestContext {
        return new HttpRequestContext($method, $path, $headers, '');
    }

    private function handler(): Nip11HttpHandler
    {
        $relayUrl = RelayUrl::tryFromString('ws://127.0.0.1:8080') ?? self::fail('relay url did not parse');

        $provider = $this->createStub(Nip11InfoProviderInterface::class);
        $provider->method('getNip11Info')->willReturn(Nip11Info::fromArray($relayUrl, [
            'name' => 'Test Relay',
            'supported_nips' => [1, 11],
        ]));

        return new Nip11HttpHandler($provider);
    }
}
