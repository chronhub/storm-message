<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;

final class HeaderTest extends TestCase
{
    #[Test]
    public function assert_well_typed_accepts_well_typed_and_absent_reserved_keys(): void
    {
        // AggregateVersion is the one int key; the rest are strings; an absent reserved key is skipped, and a
        // non-reserved application key is not checked at all.
        Header::assertWellTyped([
            Header::MessageType->value => 'App\\SomeEvent',
            Header::CorrelationId->value => 'corr-1',
            Header::AggregateVersion->value => 7,
            'app_custom' => 42, // not a reserved key, so ignored
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function assert_well_typed_rejects_a_non_int_aggregate_version(): void
    {
        // a string version would otherwise throw deep at read, where the store reads it for OCC; caught here
        // instead.
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('expected int, got string');

        Header::assertWellTyped([Header::AggregateVersion->value => '7']);
    }

    #[Test]
    public function assert_well_typed_rejects_a_non_string_text_header(): void
    {
        // a wrong-typed string header would otherwise be read back as null, a silently lost correlation.
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('expected string, got int');

        Header::assertWellTyped([Header::CorrelationId->value => 7]);
    }

    #[Test]
    public function assert_value_rejects_a_blank_identifier(): void
    {
        // a blank identifier is never legitimate; it only surfaces downstream, as a colliding dedup
        // key or a saga cross-route; refused at the writing, where the bug is visible
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('is present but blank');

        Header::CorrelationId->assertValue('');
    }

    #[Test]
    public function assert_value_rejects_a_whitespace_identifier(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('is present but blank');

        Header::MessageId->assertValue('   ');
    }

    #[Test]
    public function assert_value_rejects_a_unicode_separator_identifier(): void
    {
        // the Unicode arm, pinned at its unit: `\p{Z}` matters in the blank pattern, since `/u` alone does
        // not extend `\s` past ASCII, and an identifier of no-break spaces renders blank everywhere
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('is present but blank');

        Header::MessageId->assertValue("\u{00A0}");
    }

    #[Test]
    public function assert_write_bag_rejects_an_unknown_reserved_key(): void
    {
        // the `__` namespace promise, held at the bag level: an unknown reserved key stored today
        // could collide with a header a future framework version adds
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('reserved for framework headers');

        Header::assertWriteBag(['__future_framework_key' => 'v']);
    }

    #[Test]
    public function assert_write_bag_accepts_known_reserved_and_application_keys(): void
    {
        Header::assertWriteBag([
            Header::CorrelationId->value => 'corr-1',
            'app_custom' => 42, // application namespace, free
        ]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function assert_write_bag_survives_a_bag_whose_keys_are_ints(): void
    {
        // PHP casts a numeric-string key to int, so a bag from json_decode or a list arrives with int keys;
        // the prefix check reads them as strings, where an int under strict types would fail the comparison
        // the runtime shape the cast defends, which the declared one cannot express
        // @phpstan-ignore argument.type
        Header::assertWriteBag([0 => 'first', '7' => 'seventh']);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function assert_well_typed_tolerates_an_unknown_reserved_key(): void
    {
        // not the write-bag rule: this check also guards durable hydration, where a row from a past or future
        // framework version may carry an unknown reserved key, and rejecting it would make it unreadable
        Header::assertWellTyped(['__future_framework_key' => 'v']);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function assert_custom_key_refuses_a_padded_reserved_key_as_padded(): void
    {
        // the blank check runs before the prefix check, so the refusal names the near-collision
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('carries surrounding whitespace');

        Header::assertCustomKey('__x ');
    }

    #[Test]
    public function the_reserved_prefix_is_spelled_by_header_alone(): void
    {
        // a second literal of the prefix could drift from `Header::PREFIX` and leave one gate guarding
        // another namespace than the rest; every check reads the constant instead
        $module = dirname(__DIR__);
        $files = $where = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || str_contains($path, '/Tests/')) {
                continue;
            }

            foreach (PhpToken::tokenize((string) file_get_contents($path)) as $token) {
                if ($token->is(T_CONSTANT_ENCAPSED_STRING) && trim($token->text, '\'"') === Header::PREFIX) {
                    $files[] = substr($path, strlen($module) + 1);
                    $where[] = end($files).':'.$token->line;
                }
            }
        }
        sort($files);

        // the constant's own declaration is the one literal, which also proves the scan read the module
        $this->assertSame(['Header.php'], $files, 'Read Header::PREFIX instead of the literal at '.implode(', ', $where));
    }
}
