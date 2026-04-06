<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\DTO;

use Innis\Nostr\Relay\Application\DTO\HttpResponsePayload;
use PHPUnit\Framework\TestCase;

final class HttpResponsePayloadTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $payload = new HttpResponsePayload(
            200,
            ['content-type' => 'application/json'],
            '{"result":"ok"}'
        );

        $this->assertSame(200, $payload->getStatusCode());
        $this->assertSame(['content-type' => 'application/json'], $payload->getHeaders());
        $this->assertSame('{"result":"ok"}', $payload->getBody());
    }

    public function testErrorResponse(): void
    {
        $payload = new HttpResponsePayload(
            401,
            [],
            'Unauthorized'
        );

        $this->assertSame(401, $payload->getStatusCode());
        $this->assertSame('Unauthorized', $payload->getBody());
    }
}
