<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

final class MessageCycleBoundaryTest extends TestCase
{
    #[DataProvider('doors')]
    public function test_write_gate_refuses_cycle_without_terminating_worker(string $door): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'max_execution_time=2', __DIR__.'/Fixture/message-cycle-worker.php', $door]);
        $process->setTimeout(4);
        try {
            $process->run();
        } catch (ProcessSignaledException) {
        }
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame("gate_refused\n", $process->getOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function doors(): iterable
    {
        yield 'constructor' => ['constructor'];
        yield 'withHeader' => ['withHeader'];
    }
}
