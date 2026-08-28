<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Message\ContextValues;
use Storm\Message\CurrentMessageContext;
use Storm\Message\Exception\UnbalancedContextFrame;

final class CurrentMessageContextTest extends TestCase
{
    #[Test]
    public function starts_empty(): void
    {
        $context = new CurrentMessageContext;

        $this->assertNull($context->correlationId());
        $this->assertNull($context->actorId());
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
        // Regression: a nested dispatch is a handler that dispatches another message, running bind
        // then clear. Without nesting, that wipes the enclosing message's context, so a later handler
        // such as SagaOutcomeRouter reads a null correlation and silently skips. bind() and clear()
        // nest, so the parent is restored.
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
        // the kernel.reset safety net: an unbalanced app-side bind must not outlive its
        // handling window in a long-running worker.
        $context = new CurrentMessageContext;
        $context->bind(new ContextValues(correlationId: 'leaked-outer'));
        $context->bind(new ContextValues(correlationId: 'leaked-inner'));

        $context->reset();

        $this->assertNull($context->correlationId());
    }

    #[Test]
    public function clear_without_a_bound_frame_fails_loud(): void
    {
        // a double-clear would otherwise silently pop the PARENT's frame; the wiring bug must
        // explode at its source, not as mis-attributed context far away.
        $this->expectException(UnbalancedContextFrame::class);

        new CurrentMessageContext()->clear();
    }
}
