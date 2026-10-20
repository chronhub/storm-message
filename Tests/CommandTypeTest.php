<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Message\AliasDeclaration;
use Storm\Message\CommandType;
use Storm\Message\EventType;
use Storm\Message\Exception\InvalidCommandType;

final class CommandTypeTest extends TestCase
{
    #[Test]
    public function declares_alias_and_replaces_as_an_alias_declaration(): void
    {
        $type = new CommandType('billing.charge', ['billing.charge.v0']);

        $this->assertInstanceOf(AliasDeclaration::class, $type);
        $this->assertInstanceOf(AliasDeclaration::class, new EventType('order.placed'));
        $this->assertSame('billing.charge', $type->alias);
        $this->assertSame(['billing.charge.v0'], $type->replaces);
    }

    #[Test]
    public function defaults_to_no_replaces(): void
    {
        $this->assertSame([], new CommandType('billing.charge')->replaces);
    }

    #[Test]
    public function rejects_a_blank_alias(): void
    {
        // the alias is stored verbatim on durable rows, so a blank one would register a degenerate entry
        $this->expectException(InvalidCommandType::class);
        $this->expectExceptionMessageIsOrContains('alias must be a non-empty string');

        new CommandType("\u{00A0}");
    }

    #[Test]
    public function rejects_a_blank_replaced_alias(): void
    {
        $this->expectException(InvalidCommandType::class);
        $this->expectExceptionMessageIsOrContains('non-empty former aliases');

        new CommandType('billing.charge', [' ']);
    }

    #[Test]
    public function rejects_a_self_replacing_alias(): void
    {
        $this->expectException(InvalidCommandType::class);
        $this->expectExceptionMessageIsOrContains('must not contain the alias itself');

        new CommandType('billing.charge', ['billing.charge']);
    }

    #[Test]
    public function rejects_a_duplicate_replaced_alias(): void
    {
        $this->expectException(InvalidCommandType::class);
        $this->expectExceptionMessageIsOrContains('must not repeat');

        new CommandType('billing.charge', ['billing.charge.v0', 'billing.charge.v0']);
    }

    #[Test]
    public function rejects_an_associative_replaces(): void
    {
        $this->expectException(InvalidCommandType::class);
        $this->expectExceptionMessageIsOrContains('must be a list');

        // @phpstan-ignore argument.type (a hostile value: the guard under test)
        new CommandType('billing.charge', ['old' => 'billing.charge.v0']);
    }

    #[Test]
    public function rejects_a_non_string_replaced_alias(): void
    {
        $this->expectException(InvalidCommandType::class);
        $this->expectExceptionMessageIsOrContains('strings only, got int');

        // @phpstan-ignore argument.type (a hostile value: the guard under test)
        new CommandType('billing.charge', [1]);
    }
}
