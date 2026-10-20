<?php

declare(strict_types=1);

namespace Storm\Message\Exception;

use LogicException;

/**
 * A `#[CommandType]` attribute declared with an invalid shape.
 *
 * A declaration bug, so a `LogicException`. The attribute's own constructor throws it, and the
 * mapper instantiates that constructor while scanning, so it fails at container build instead of
 * writing a degenerate alias to a durable row.
 */
final class InvalidCommandType extends LogicException
{
    public static function emptyAlias(): self
    {
        return new self('A #[CommandType] alias must be a non-empty string: it is the canonical stored type.');
    }

    public static function emptyReplacedAlias(string $alias): self
    {
        return new self(sprintf(
            'The #[CommandType("%s")] replaces list must hold non-empty former aliases.',
            $alias,
        ));
    }

    public static function selfReplacingAlias(string $alias): self
    {
        return new self(sprintf(
            'The #[CommandType("%s")] replaces list must not contain the alias itself.',
            $alias,
        ));
    }

    public static function nonListReplaces(string $alias): self
    {
        return new self(sprintf(
            'The #[CommandType("%s")] replaces argument must be a list, not an associative array.',
            $alias,
        ));
    }

    public static function nonStringReplacedAlias(string $alias, string $actualType): self
    {
        return new self(sprintf(
            'The #[CommandType("%s")] replaces list must hold strings only, got %s.',
            $alias,
            $actualType,
        ));
    }

    public static function duplicateReplacedAlias(string $alias): self
    {
        return new self(sprintf(
            'The #[CommandType("%s")] replaces list must not repeat a former alias.',
            $alias,
        ));
    }
}
