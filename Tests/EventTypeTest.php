<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Message\EventType;
use Storm\Message\Exception\InvalidEventType;

final class EventTypeTest extends TestCase
{
    #[Test]
    public function declares_alias_version_and_replaces(): void
    {
        $type = new EventType('order.placed', 2, ['order.created']);

        $this->assertSame('order.placed', $type->alias);
        $this->assertSame(2, $type->version);
        $this->assertSame(['order.created'], $type->replaces);
    }

    #[Test]
    public function defaults_to_version_one_with_no_replaces(): void
    {
        $type = new EventType('order.placed');

        $this->assertSame(1, $type->version);
        $this->assertSame([], $type->replaces);
    }

    #[Test]
    public function rejects_an_empty_alias(): void
    {
        // '' would register a degenerate alias-map entry and be written to the store's type column
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('alias must be a non-empty string');

        new EventType(''); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }

    #[Test]
    public function rejects_a_unicode_separator_alias(): void
    {
        // the alias is stored verbatim in the type column, the most durable value a blank can
        // reach; a NBSP-only alias renders exactly as blank everywhere, and /u alone does not fold
        // Unicode separators into \s
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('alias must be a non-empty string');

        new EventType("\u{00A0}");
    }

    #[Test]
    public function rejects_a_unicode_separator_replaced_alias(): void
    {
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('replaces list must hold non-empty former aliases');

        new EventType('order.placed', 1, ["\u{00A0}"]);
    }

    #[Test]
    public function rejects_a_non_positive_version(): void
    {
        // version 0 would flow into the upcaster range math
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('version must be >= 1, got 0');

        new EventType('order.placed', 0); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }

    #[Test]
    public function rejects_an_empty_replaced_alias(): void
    {
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('replaces list must hold non-empty former aliases');

        new EventType('order.placed', 1, ['']); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }

    #[Test]
    public function rejects_a_self_replacing_alias(): void
    {
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('must not contain the alias itself');

        new EventType('order.placed', 1, ['order.placed']);
    }

    #[Test]
    public function rejects_a_whitespace_alias(): void
    {
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('alias must be a non-empty string');

        new EventType('   '); // whitespace IS a non-empty-string; the phpdoc shape cannot catch this one
    }

    #[Test]
    public function rejects_a_non_string_replaced_alias(): void
    {
        // the mapper is contract-bound to expose list<string> stored types; an int here would
        // violate it downstream, in projection and live-query filters
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('must hold strings only, got int');

        new EventType('order.placed', 1, [123]); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }

    #[Test]
    public function rejects_a_whitespace_replaced_alias(): void
    {
        // technically not '', an unusable durable type all the same
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('replaces list must hold non-empty former aliases');

        new EventType('order.placed', 1, ['   ']); // whitespace IS a non-empty-string; only the runtime guard catches it
    }

    #[Test]
    public function rejects_a_duplicate_replaced_alias(): void
    {
        // the mapper's reverse map would silently absorb the duplicate; the declaration stays canonical
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('replaces list holds a duplicate');

        new EventType('order.placed', 1, ['order.created', 'order.created']);
    }

    #[Test]
    public function rejects_an_associative_replaces(): void
    {
        // list<non-empty-string> is the declared shape; an associative array would silently reindex
        $this->expectException(InvalidEventType::class);
        $this->expectExceptionMessageIsOrContains('must be a genuine list');

        new EventType('order.placed', 1, [2 => 'order.created']); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }
}
