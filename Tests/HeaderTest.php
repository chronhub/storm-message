<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        // a string version would otherwise throw deep at read, where the store reads it for OCC; caught here instead.
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
        // the Unicode arm, pinned where the unit lives: `\p{Z}` is load-bearing in the blank
        // pattern, since `/u` alone does not extend `\s` past ASCII, and a NBSP-only identifier
        // renders exactly as blank in every log and column it reaches
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
        // PHP casts a numeric-string array key to int on the way in, so a bag round-tripped through
        // json_decode or built from a list arrives with int keys whatever the declared shape says.
        // The prefix check reads them as strings; handed an int under strict types it would not
        // refuse the bag, it would die on the comparison.
        Header::assertWriteBag([0 => 'first', '7' => 'seventh']); // @phpstan-ignore argument.type (the runtime shape the cast defends, which the declared one cannot express)

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function assert_well_typed_tolerates_an_unknown_reserved_key(): void
    {
        // deliberately NOT the write-bag rule: this check also guards durable hydration, and a row
        // written by a past or future framework version may carry a reserved key this version does
        // not know; rejecting it would brick the row
        Header::assertWellTyped(['__future_framework_key' => 'v']);

        $this->expectNotToPerformAssertions();
    }
}
