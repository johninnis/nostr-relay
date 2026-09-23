<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\Concurrency;

use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

final class AmphpDeferredExecutorTest extends TestCase
{
    public function testADeferredTaskRuns(): void
    {
        $ran = false;

        async(static function () use (&$ran): void {
            new AmphpDeferredExecutor()->defer(static function () use (&$ran): void {
                $ran = true;
            });
            delay(0);
        })->await();

        $this->assertTrue($ran);
    }

    public function testADeferredTaskThatThrowsIsReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Deferred task failed',
            ['error' => 'the deferred work failed', 'type' => RuntimeException::class],
        );
        $executor = new AmphpDeferredExecutor($logger);

        async(static function () use ($executor): void {
            $executor->defer(static function (): void {
                throw new RuntimeException('the deferred work failed');
            });
            delay(0);
        })->await();
    }
}
