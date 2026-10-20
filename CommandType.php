<?php

declare(strict_types=1);

namespace Storm\Message;

use Attribute;
use Storm\Message\Exception\InvalidCommandType;
use Storm\Text\Str;

/**
 * Declares the stable stored type of a command, its alias.
 *
 * The alias is owned by the developer and stored verbatim, never derived from the class, so a
 * command renamed under it is still dispatched from the surfaces that read it back: a saga outbox row
 * and a neutral transport. A Messenger lane on `PhpSerializer` and the failure transport, which stays
 * on `PhpSerializer`, keep the FQCN, so both are drained before a command class is renamed.
 *
 * `replaces` holds former aliases, so rows written under one still resolve. A command has no schema
 * version: it is handled once and never replayed from a store, so nothing upcasts it.
 *
 * The constructor enforces the declared shape beyond what PHP types check, and a degenerate
 * declaration fails when the mapper instantiates the attribute, at the first boot of a consuming
 * service:
 *
 * - The alias and every `replaces` entry are non-blank strings
 * - `replaces` is a real list, without duplicates or the alias itself
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CommandType implements AliasDeclaration
{
    /**
     * @param  non-empty-string  $alias  the canonical stored type, such as `billing.charge`
     * @param  list<non-empty-string>  $replaces  former aliases that still resolve to this class
     *
     * @throws InvalidCommandType when the alias is blank, or replaces is not a list of unique, non-blank
     *                            strings distinct from the alias
     */
    public function __construct(
        public string $alias,
        public array $replaces = [],
    ) {
        // phpstan deems several of these dead under the very phpdoc types they defend.
        if (Str::isBlank($this->alias)) {
            throw InvalidCommandType::emptyAlias();
        }

        if (! array_is_list($this->replaces)) { // @phpstan-ignore function.alreadyNarrowedType
            throw InvalidCommandType::nonListReplaces($this->alias);
        }

        foreach ($this->replaces as $replaced) {
            if (! is_string($replaced)) { // @phpstan-ignore function.alreadyNarrowedType
                throw InvalidCommandType::nonStringReplacedAlias($this->alias, get_debug_type($replaced));
            }

            if (Str::isBlank($replaced)) {
                throw InvalidCommandType::emptyReplacedAlias($this->alias);
            }

            if ($replaced === $this->alias) {
                throw InvalidCommandType::selfReplacingAlias($this->alias);
            }
        }

        if (count($this->replaces) !== count(array_unique($this->replaces))) {
            // the mapper's reverse map would absorb the duplicate in silence; refuse it where the
            // declaration is written
            throw InvalidCommandType::duplicateReplacedAlias($this->alias);
        }
    }
}
