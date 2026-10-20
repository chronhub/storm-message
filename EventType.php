<?php

declare(strict_types=1);

namespace Storm\Message;

use Attribute;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Message\Exception\InvalidEventType;
use Storm\Text\Str;

/**
 * Declares the stable stored type of a domain event, its alias.
 *
 * The alias is decoupled from the PHP FQCN, so it survives renames and stays portable on the wire.
 * It is owned by the developer and stored verbatim, never derived from the class, which would couple
 * it to the FQCN again. `replaces` holds former aliases, so old rows still resolve after a rare
 * alias rename. The `EventTypeMapper` reads this attribute: `toType()` from the class through
 * reflection, `toClass()` from the scanned reverse map.
 *
 * The attribute is optional: an event without it falls back to its FQCN for gradual adoption, but a
 * wire-bound event should declare one, since an FQCN is not portable across languages.
 *
 * The constructor enforces the whole declared shape, beyond what PHP types check:
 *
 * - The alias and every `replaces` entry are non-blank strings
 * - `replaces` is a real list, without duplicates or the alias itself
 *
 * A degenerate declaration fails when the mapper instantiates the attributes, at the first boot of a
 * consuming service and before any stored row, instead of silently at a later read. PHP enforces
 * only the outer `array` of `$replaces`; the PHPDoc shape is a claim this constructor must make
 * true.
 *
 * @see \Storm\Contracts\Chronicler\EventTypeMapper
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EventType implements AliasDeclaration
{
    /**
     * @param  non-empty-string  $alias  the canonical stored type, such as `order.placed`
     * @param  positive-int  $version  the current schema version of this event, which drives upcasting
     * @param  list<non-empty-string>  $replaces  former aliases that still resolve to this class
     *
     * @throws InvalidEventType when the alias is blank, the version is < 1, or replaces is not a
     *                          list of unique, non-blank strings distinct from the alias
     */
    public function __construct(
        public string $alias,
        public int $version = 1,
        public array $replaces = [],
    ) {
        // phpstan deems several of these dead under the very phpdoc types they defend.
        if (Str::isBlank($this->alias)) {
            throw InvalidEventType::emptyAlias();
        }

        if ($this->version < 1) { // @phpstan-ignore smaller.alwaysFalse
            throw InvalidEventType::nonPositiveVersion($this->alias, $this->version);
        }

        if (! array_is_list($this->replaces)) { // @phpstan-ignore function.alreadyNarrowedType
            throw InvalidEventType::nonListReplaces($this->alias);
        }

        foreach ($this->replaces as $replaced) {
            if (! is_string($replaced)) { // @phpstan-ignore function.alreadyNarrowedType
                throw InvalidEventType::nonStringReplacedAlias($this->alias, get_debug_type($replaced));
            }

            if (Str::isBlank($replaced)) {
                throw InvalidEventType::emptyReplacedAlias($this->alias);
            }

            if ($replaced === $this->alias) {
                throw InvalidEventType::selfReplacingAlias($this->alias);
            }
        }

        if (count($this->replaces) !== count(array_unique($this->replaces))) {
            // a duplicate would be silently absorbed by the mapper's reverse map; refuse it here,
            // where the declaration is written, so it stays canonical
            throw InvalidEventType::duplicateReplacedAlias($this->alias);
        }
    }
}
