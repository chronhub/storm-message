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
 * Ambient holder of the stored header of the message being handled.
 *
 * A Messenger handler, such as a reaction or a saga, receives only the message object, never the
 * envelope or its stamps. `CurrentMessageContext` already exposes correlation, causation, actor and
 * tenant, and the aggregate id lives on the event itself. What is left travels in the
 * `StoredHeaderStamp` of a republished event:
 *
 * - `occurred_at`
 * - The aggregate type and id type
 * - The aggregate version
 *
 * The `BindStoredHeader` middleware binds this holder from that stamp around the handler, then
 * clears it. `bind()` and `clear()` nest as a stack: every dispatch pushes its own frame, a
 * `Message` when a stored header rides along and null when none does, then pops back to the
 * parent's on return, so it never clobbers or leaks the enclosing message's stored header.
 *
 * Every accessor is null when the message being handled has no stored header, such as an
 * in-process dispatch that never went through the outbox, even one nested under a republished
 * event: the null frame shadows the parent's header, so the accessors always answer for the message
 * handled now. Like `CurrentMessageContext`, it holds state.
 *
 * The Story references below are fully qualified because `Message` must not import `Story`, the
 * layer above that binds this holder; they stay documentation-only.
 *
 * @see CurrentMessageContext
 * @see \Storm\Story\Stamp\StoredHeaderStamp
 * @see \Storm\Story\Middleware\BindStoredHeader
 */
final class CurrentStoredHeader implements ResetInterface
{
    /**
     * @var list<?Message> the nesting stack; every dispatch pushes a frame, null meaning no stored header,
     *                     then pops back to the parent's
     */
    private array $stack = [];

    private ?Message $message = null;

    /**
     * Bind the stored header for the message about to be handled, pushing a nesting frame.
     *
     * Null pushes an empty frame: a dispatch without a stored header must shadow its parent's, not
     * inherit it.
     */
    public function bind(?Message $message): void
    {
        $this->stack[] = $this->message = $message;
    }

    /**
     * Pop the current frame, restoring the parent's stored header or none at the top level.
     *
     * The `BindStoredHeader` middleware pairs it with `bind()` in a `finally`, so it stays balanced
     * even on an exception.
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
     * Messenger's worker runs it between messages through `kernel.reset` autoconfiguration. It wipes
     * any frame an unbalanced application `bind()` leaked, so a leak lasts one handling window, never
     * the worker's remaining lifetime. The framework's own binders pair `bind()` with `clear()` in a
     * `finally` and never need it.
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
