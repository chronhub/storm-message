<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Contracts\Message\MessageContext;
use Storm\Message\ContextValues;
use Storm\Message\CurrentMessageContext;
use Storm\Message\Exception\UnbalancedContextFrame;

final class CurrentMessageContextTest extends TestCase
{
    #[Test]
    public function starts_empty(): void
    {
        $context = new CurrentMessageContext;

        $this->assertContext(ContextValues::empty(), $context);
    }

    #[Test]
    public function bind_exposes_the_bound_values(): void
    {
        $context = new CurrentMessageContext;

        $context->bind(new ContextValues(correlationId: 'corr', actorId: 'actor'));

        $this->assertSame('corr', $context->correlationId());
        $this->assertSame('actor', $context->actorId());
    }

    #[Test]
    public function clear_resets_to_empty(): void
    {
        $context = new CurrentMessageContext;
        $context->bind(new ContextValues(correlationId: 'corr'));

        $context->clear();

        $this->assertNull($context->correlationId());
    }

    #[Test]
    public function rebind_replaces_previous_values(): void
    {
        $context = new CurrentMessageContext;
        $context->bind(new ContextValues(correlationId: 'first'));

        $context->bind(new ContextValues(correlationId: 'second'));

        $this->assertSame('second', $context->correlationId());
    }

    #[Test]
    public function clear_restores_the_parent_frame_after_a_nested_bind(): void
    {
        // a nested dispatch runs bind then clear; without nesting it would wipe the enclosing context,
        // and a later handler such as SagaOutcomeRouter would read a null correlation and silently skip
        $context = new CurrentMessageContext;
        $context->bind(new ContextValues(correlationId: 'outer'));

        $context->bind(new ContextValues(correlationId: 'inner')); // nested dispatch begins
        $context->clear();                                          // nested handler finished

        $this->assertSame('outer', $context->correlationId());      // parent restored, not wiped

        $context->clear();                                          // outer handler finished
        $this->assertNull($context->correlationId());               // empty again at the top level
    }

    #[Test]
    public function reset_wipes_leaked_frames(): void
    {
        // kernel.reset: an unbalanced application bind must not outlive its handling window in a
        // long-running worker
        $context = new CurrentMessageContext;
        $context->bind(new ContextValues(correlationId: 'leaked-outer'));
        $context->bind(new ContextValues('inner', 'cause', 'actor', 'user', 'tenant', ['trace' => 'inner']));

        $context->reset();

        $this->assertContext(ContextValues::empty(), $context);

        $context->bind(new ContextValues(correlationId: 'next'));
        $context->clear();

        $this->assertContext(ContextValues::empty(), $context);
        $this->expectException(UnbalancedContextFrame::class);
        $context->clear();
    }

    #[Test]
    public function nested_frames_restore_every_context_value(): void
    {
        $context = new CurrentMessageContext;
        $parent = new ContextValues('parent', 'parent-cause', 'parent-actor', 'user', 'parent-tenant', ['trace' => 'parent']);
        $child = new ContextValues('child', 'child-cause', 'child-actor', 'service', 'child-tenant', ['trace' => 'child']);

        $context->bind($parent);
        $context->bind($child);
        $this->assertContext($child, $context);

        $context->bind(ContextValues::empty());
        $this->assertContext(ContextValues::empty(), $context);

        $context->clear();
        $this->assertContext($child, $context);

        $context->clear();
        $this->assertContext($parent, $context);

        $context->clear();
        $this->assertContext(ContextValues::empty(), $context);
    }

    private function assertContext(MessageContext $expected, MessageContext $actual): void
    {
        $this->assertSame($expected->correlationId(), $actual->correlationId());
        $this->assertSame($expected->causationId(), $actual->causationId());
        $this->assertSame($expected->actorId(), $actual->actorId());
        $this->assertSame($expected->actorType(), $actual->actorType());
        $this->assertSame($expected->tenantId(), $actual->tenantId());
        $this->assertSame($expected->bag(), $actual->bag());
    }

    #[Test]
    public function clear_without_a_bound_frame_fails_loud(): void
    {
        // a double clear would otherwise silently pop the parent's frame; the wiring bug must fail at
        // its source, not as misattributed context far away
        $this->expectException(UnbalancedContextFrame::class);

        new CurrentMessageContext()->clear();
    }
}
