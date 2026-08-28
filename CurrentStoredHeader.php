<?php

declare(strict_types=1);

namespace Storm\Message;

use Override;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Exception\UnbalancedContextFrame;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ambient holder for the stored header of the message currently being handled; the part a bus
 * handler, a reaction or saga, cannot reach otherwise.
 *
 * A Messenger handler receives only the message object, never the envelope or stamps. The
 * cross-cutting context of correlation, causation, actor, and tenant is already exposed via
 * CurrentMessageContext, and the aggregate id lives on the event itself. What is left, occurred_at,
 * the aggregate type, id-type, and version, travels in the StoredHeaderStamp of a republished
 * event. The BindStoredHeader middleware binds this holder from that stamp around the handler, then
 * clears it. bind()/clear() nest as a stack: every dispatch pushes its own frame, a Message when a
 * stored header rides along and null when none does, then pops back to the parent's on return, so
 * it never clobbers nor leaks the enclosing message's stored header.
 *
 * All accessors are null when the message being handled has no stored header, for example an
 * in-process dispatch that never went through the outbox, including one nested under a republished
 * event: the null frame shadows the parent's header, and the accessors always answer for the
 * message being handled now. Stateful by design, like CurrentMessageContext.
 *
 * The Story references below are fully qualified on purpose: `Message` must not import `Story`, the
 * layer above it that binds this holder, so those references stay documentation-only.
 *
 * @see CurrentMessageContext
 * @see \Storm\Story\Stamp\StoredHeaderStamp
 * @see \Storm\Story\Middleware\BindStoredHeader
 */
final class CurrentStoredHeader implements ResetInterface
{
    /** @var list<?Message> the nesting stack; every dispatch pushes a frame, null meaning no stored header, then pops back to the parent's */
    private array $stack = [];

    private ?Message $message = null;

    /**
     * Bind the stored header for the message about to be handled, pushing a nesting frame so a nested
     * dispatch does not clobber its parent's stored header. Null pushes an empty frame: a dispatch
     * without a stored header must shadow its parent's, not inherit it.
     */
    public function bind(?Message $message): void
    {
        $this->stack[] = $this->message = $message;
    }

    /**
     * Pop the current frame, restoring the parent's stored header, or none at the top level. Paired
     * with bind() by BindStoredHeader, which binds then clears in `finally`, it stays balanced even
     * on an exception.
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
        $this->message = $this->stack === [] ? null : array_last($this->stack);
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
        $this->message = null;
    }

    /**
     * @throws InvalidDateTimeException when the stored header is not a canonical Storm datetime
     */
    public function occurredAt(): ?PointInTime
    {
        return $this->message?->occurredAt();
    }

    public function aggregateType(): ?string
    {
        return $this->message?->aggregateType();
    }

    public function aggregateIdType(): ?string
    {
        return $this->message?->aggregateIdType();
    }

    /**
     * @throws InvalidMessageException when the stored header carries a non-int aggregate version;
     *                                 a corrupt envelope surfaced by the delegate, never masked
     */
    public function aggregateVersion(): ?int
    {
        return $this->message?->aggregateVersion();
    }
}
