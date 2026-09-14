<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PayloadShapeTest extends TestCase
{
    #[Test]
    public function shapes_check_reads_and_writes_without_requiring_annotations(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir().'/storm-payload-'.bin2hex(random_bytes(8));
        mkdir($directory);
        copy(__DIR__.'/Fixture/payload-shape.php.fixture', $directory.'/probe.php');
        file_put_contents($directory.'/phpstan.neon', "parameters:\n    level: 8\n    tmpDir: ".$directory."/cache\n");
        $process = new Process([PHP_BINARY, $root.'/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '-c', $directory.'/phpstan.neon', $directory.'/probe.php'], $root);
        $process->setTimeout(120);
        try {
            $process->run();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['errors'], $process->getErrorOutput());
            $messages = array_merge(...array_map(static fn (array $file): array => $file['messages'], array_values($result['files'])));
            self::assertCount(2, $messages, $process->getOutput());
            self::assertSame(['offsetAccess.notFound', 'argument.type'], array_column($messages, 'identifier'));
            self::assertStringContainsString("Offset 'fooo'", $messages[0]['message']);
            self::assertStringContainsString('constructor expects array{foo: string}', $messages[1]['message']);
            self::assertSame(1, $process->getExitCode());
        } finally {
            new Process(['rm', '-rf', '--', $directory])->mustRun();
        }
    }
}
