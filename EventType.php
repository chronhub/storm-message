<?php

declare(strict_types=1);

namespace Storm\Message;

use Attribute;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Message\Exception\InvalidEventType;

/**
 * Declares the stable stored type of a domain event: its alias, decoupled from the PHP FQCN so it
 * is rename-proof and portable on the wire.
 *
 * The alias is dev-owned and stored verbatim, with no auto-derivation from the class, which would
 * re-couple it to the FQCN and defeat the purpose. The replaces list holds former aliases so old
 * rows still resolve after a rare, deliberate alias rename. The EventTypeMapper reads this
 * attribute: toType from the class through reflection, toClass from the scanned reverse map.
 *
 * Optional: an event without it falls back to its FQCN for gradual adoption, but a wire-bound event
 * should declare one, since an FQCN is not portable cross-language.
 *
 * The shape invariants are enforced here, in the constructor: the WHOLE declared shape, not only
 * what PHP types check. The alias and every replaces entry are non-blank strings, and replaces is a
 * genuine list without duplicates or the alias itself. A degenerate declaration explodes when the
 * mapper instantiates the attributes, at the first boot of a consuming service and before any
 * stored row, not silently at some later read. PHP only enforces the outer `array` of `$replaces`;
 * the phpdoc shape is a claim this constructor must make true.
 *
 * @see \Storm\Contracts\Chronicler\EventTypeMapper
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EventType
{
    /**
     * @param  non-empty-string  $alias  the canonical stored type, e.g. order.placed
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
        if (self::isBlank($this->alias)) {
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

            if (self::isBlank($replaced)) {
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

    // Blank to every renderer: empty, ASCII whitespace, or Unicode separators, which /u alone does
    // not fold into \s; the alias is the DURABLE type column, the most permanent value a blank
    // could reach, so it gets the same blank test the header values carry
    private static function isBlank(string $value): bool
    {
        return preg_match('/^[\s\p{Z}]*$/u', $value) === 1;
    }
}
