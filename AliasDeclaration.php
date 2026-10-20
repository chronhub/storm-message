<?php

declare(strict_types=1);

namespace Storm\Message;

/**
 * A class-target attribute declaring the stable stored type of a message, its alias.
 *
 * `EventType` and `CommandType` implement it, so one alias map serves every sort: the mapper and the
 * build-time scan read any declaration through this shape, and an alias stays unique across sorts.
 */
interface AliasDeclaration
{
    /** The canonical stored type, such as `order.placed`. */
    public string $alias { get; }

    /**
     * Former aliases that still resolve to this class.
     *
     * @var list<non-empty-string>
     */
    public array $replaces { get; }
}
