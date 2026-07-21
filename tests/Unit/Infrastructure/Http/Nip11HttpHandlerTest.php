<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\Http;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Innis\Nostr\Relay\Infrastructure\Http\Nip11HttpHandler;
use League\Uri\Http;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class Nip11HttpHandlerTest extends TestCase
{
    public function testServesTheRelayDocumentWhenNegotiated(): void
    {
        $response = $this->handler()->handleRequest($this->request());

        $this->assertSame('application/nostr+json', $response?->getHeader('content-type'));
    }

    public function testTheDocumentCarriesTheProvidedRelayInformation(): void
    {
        $response = $this->handler()->handleRequest($this->request());

        $this->assertNotNull($response);
        $this->assertStringContainsString('Test Relay', buffer($response->getBody()));
    }

    public function testIgnoresARequestWithoutTheNostrAcceptHeader(): void
    {
        $this->assertNull($this->handler()->handleRequest($this->request(headers: ['accept' => 'text/html'])));
    }

    public function testIgnoresARequestForAnotherPath(): void
    {
        $this->assertNull($this->handler()->handleRequest($this->request(path: '/favicon.ico')));
    }

    public function testIgnoresANonGetRequest(): void
    {
        $this->assertNull($this->handler()->handleRequest($this->request(method: 'POST')));
    }

    public function testIgnoresARequestWithNoAcceptHeaderAtAll(): void
    {
        $this->assertNull($this->handler()->handleRequest($this->request(headers: [])));
    }

    public function testNegotiatesWhenTheAcceptHeaderListsSeveralTypes(): void
    {
        $this->assertNotNull($this->handler()->handleRequest(
            $this->request(headers: ['accept' => 'text/html, application/nostr+json;q=0.9']),
        ));
    }

    public function testIgnoresARequestThatExplicitlyRefusesTheTypeWithZeroQuality(): void
    {
        $this->assertNull($this->handler()->handleRequest(
            $this->request(headers: ['accept' => 'application/nostr+json;q=0']),
        ));
    }

    public function testNegotiatesRegardlessOfHeaderCasing(): void
    {
        $this->assertNotNull($this->handler()->handleRequest(
            $this->request(headers: ['accept' => 'Application/Nostr+JSON']),
        ));
    }

    public function testIgnoresAWildcardAcceptHeader(): void
    {
        $this->assertNull($this->handler()->handleRequest($this->request(headers: ['accept' => '*/*'])));
    }

    public function testIgnoresATypeThatMerelyContainsTheMediaTypeAsASubstring(): void
    {
        $this->assertNull($this->handler()->handleRequest(
            $this->request(headers: ['accept' => 'application/nostr+json-ld']),
        ));
    }

    /**
     * @param non-empty-string                $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(
        string $method = 'GET',
        string $path = '/',
        array $headers = ['accept' => 'application/nostr+json'],
    ): Request {
        return new Request($this->createStub(Client::class), $method, Http::new($path), $headers);
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
