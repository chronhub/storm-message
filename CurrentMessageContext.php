<?php

declare(strict_types=1);

namespace Storm\Message;

use Override;
use Storm\Contracts\Message\MessageContext;
use Storm\Message\Exception\UnbalancedContextFrame;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The ambient, message-scoped holder of `MessageContext`.
 *
 * It is the single shared service the writing path reads, so no handler threads identifiers
 * through. The Story bridge middleware binds it at the dispatch boundary:
 *
 * - It reads the Messenger stamps and builds a `ContextValues` snapshot
 * - It calls `bind()` around the handler, then `clear()`
 *
 * `bind()` and `clear()` nest as a stack: a handler that dispatches a nested message pushes its own
 * frame and pops back to the parent's on return, so a nested dispatch never clobbers the enclosing
 * message's context and the next top-level message starts clean.
 *
 * This holder is the only stateful, mutable piece. It stays framework-neutral, never importing the
 * transport, and delegates to the currently bound snapshot.
 */
#[AsAlias(MessageContext::class)]
final class CurrentMessageContext implements MessageContext, ResetInterface
{
    /**
     * @var list<MessageContext> the nesting stack; a nested dispatch pushes its frame, then pops back to the
     *                           parent's
     */
    private array $stack = [];

    private MessageContext $current;

    public function __construct()
    {
        $this->current = ContextValues::empty();
    }

    /**
     * Bind the context for the message about to be handled, pushing a nesting frame.
     */
    public function bind(MessageContext $context): void
    {
        $this->stack[] = $this->current = $context;
    }

    /**
     * Pop the current frame, restoring the parent's context or an empty one at the top level.
     *
     * The Story bridge middleware pairs it with `bind()` in a `finally`, so it stays balanced even on
     * an exception.
     *
     * @throws UnbalancedContextFrame when called with no bound frame; a wiring bug, surfaced here
     *                                rather than silently popping the parent's frame
     */
    public function clear(): void
    {
        if ($this->stack === []) {
            throw UnbalancedContextFrame::clearedEmpty(self::class);
        }

        array_pop($this->stack);
        $this->current = $this->stack === [] ? ContextValues::empty() : array_last($this->stack);
    }

    /**
     * {@inheritDoc}
     *
     * Messenger's worker runs it between messages through `kernel.reset` autoconfiguration. It wipes
     * any frame an unbalanced application `bind()` leaked, so a leak lasts one handling window, never
     * the worker's remaining lifetime. The framework's own binders pair `bind()` with `clear()` in a
     * `finally` and never need it.
     */
    #[Override]
    public function reset(): void
    {
        $this->stack = [];
        $this->current = ContextValues::empty();
    }

    public function correlationId(): ?string
    {
        return $this->current->correlationId();
    }

    public function causationId(): ?string
    {
        return $this->current->causationId();
    }

    public function actorId(): ?string
    {
        return $this->current->actorId();
    }

    public function actorType(): ?string
    {
        return $this->current->actorType();
    }

    public function tenantId(): ?string
    {
        return $this->current->tenantId();
    }

    public function bag(): array
    {
        return $this->current->bag();
    }
}
