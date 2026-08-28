<?php

declare(strict_types=1);

namespace Storm\Message\Exception;

use LogicException;

/**
 * A holder's bind()/clear() protocol was violated: clear() was called with no bound frame. Always a
 * wiring bug, a double-clear or a clear without its bind, so a `LogicException`, surfaced at the
 * faulty call instead of silently popping the parent's frame, where the corruption would show up
 * far from its cause as mis-attributed ambient context.
 */
final class UnbalancedContextFrame extends LogicException
{
    public static function clearedEmpty(string $holder): self
    {
        return new self(sprintf(
            'clear() called on %s with no bound frame — every clear() must mirror exactly one bind().',
            $holder,
        ));
    }
}
