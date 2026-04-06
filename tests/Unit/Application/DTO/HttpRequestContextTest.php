<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\DTO;

use Innis\Nostr\Relay\Application\DTO\HttpRequestContext;
use PHPUnit\Framework\TestCase;

final class HttpRequestContextTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $context = new HttpRequestContext(
            'POST',
            '/api',
            ['content-type' => 'application/json'],
            '{"key":"value"}'
        );

        $this->assertSame('POST', $context->getMethod());
        $this->assertSame('/api', $context->getPath());
        $this->assertSame(['content-type' => 'application/json'], $context->getHeaders());
        $this->assertSame('{"key":"value"}', $context->getBody());
    }

    public function testGetHeaderReturnsCaseInsensitiveMatch(): void
    {
        $context = new HttpRequestContext(
            'GET',
            '/',
            ['Content-Type' => 'text/html', 'Accept' => 'application/json'],
            ''
        );

        $this->assertSame('text/html', $context->getHeader('content-type'));
        $this->assertSame('text/html', $context->getHeader('Content-Type'));
        $this->assertSame('application/json', $context->getHeader('ACCEPT'));
    }

    public function testGetHeaderReturnsNullForMissingHeader(): void
    {
        $context = new HttpRequestContext('GET', '/', [], '');

        $this->assertNull($context->getHeader('x-missing'));
    }
}
