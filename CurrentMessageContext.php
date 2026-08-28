<?php

declare(strict_types=1);

namespace Storm\Message;

use Override;
use Storm\Contracts\Message\MessageContext;
use Storm\Message\Exception\UnbalancedContextFrame;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The ambient, message-scoped holder of MessageContext, the single shared service the writing path
 * reads without threading identifiers through every handler.
 *
 * It is bound at the dispatch boundary: the Story bridge middleware reads the Messenger stamps,
 * builds a ContextValues snapshot, calls bind() around the handler, then clear() afterward.
 * bind()/clear() nest as a stack; a handler that dispatches a nested message pushes its own frame
 * and pops back to the parent's on return, so a nested dispatch never clobbers the enclosing
 * message's context and the next top-level message starts clean. This holder is the only stateful,
 * mutable piece; it stays framework-neutral, never importing the transport, and simply delegates to
 * the currently bound snapshot.
 */
#[AsAlias(MessageContext::class)]
final class CurrentMessageContext implements MessageContext, ResetInterface
{
    /** @var list<MessageContext> the nesting stack; a nested dispatch pushes its frame, then pops back to the parent's */
    private array $stack = [];

    private MessageContext $current;

    public function __construct()
    {
        $this->current = ContextValues::empty();
    }

    /**
     * Bind the context for the message about to be handled, pushing a nesting frame so a nested
     * dispatch, a handler that dispatches another message, does not clobber its parent's context.
     */
    public function bind(MessageContext $context): void
    {
        $this->stack[] = $this->current = $context;
    }

    /**
     * Pop the current frame once the message has been handled, restoring the parent's context, or an
     * empty one at the top level, so the next message starts clean. Paired with bind() by the Story
     * bridge middleware, which binds then clears in `finally`, it stays balanced even on an exception.
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
     * The between-messages safety net, kernel.reset via autoconfiguration run by Messenger's worker
     * between messages: wipes any frame an unbalanced app-side bind() leaked, bounding a leak to one
     * handling window, never the worker's remaining lifetime. The framework's own binders are
     * finally-paired and never need it.
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
