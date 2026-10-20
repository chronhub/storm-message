<?php

declare(strict_types=1);

namespace Storm\Message\Exception;

use LogicException;

/**
 * An `#[EventType]` attribute declared with an invalid shape.
 *
 * A declaration bug, so a `LogicException`. The attribute's own constructor throws it, and the
 * mapper instantiates that constructor while scanning, so it fails at container build, the earliest
 * point, instead of writing a degenerate alias to stored rows or feeding a non-positive version into
 * the upcaster range math.
 */
final class InvalidEventType extends LogicException
{
    public static function emptyAlias(): self
    {
        return new self('An #[EventType] alias must be a non-empty string — it is the canonical stored type.');
    }

    public static function nonPositiveVersion(string $alias, int $version): self
    {
        return new self(sprintf(
            'The #[EventType("%s")] version must be >= 1, got %d — it drives the upcaster range math.',
            $alias,
            $version,
        ));
    }

    public static function emptyReplacedAlias(string $alias): self
    {
        return new self(sprintf(
            'The #[EventType("%s")] replaces list must hold non-empty former aliases.',
            $alias,
        ));
    }

    public static function selfReplacingAlias(string $alias): self
    {
        return new self(sprintf(
            'The #[EventType("%s")] replaces list must not contain the alias itself.',
            $alias,
        ));
    }

    public static function nonListReplaces(string $alias): self
    {
        return new self(sprintf(
            'The #[EventType("%s")] replaces must be a genuine list — an associative array would silently reindex on the wire.',
            $alias,
        ));
    }

    public static function nonStringReplacedAlias(string $alias, string $actualType): self
    {
        return new self(sprintf(
            'The #[EventType("%s")] replaces list must hold strings only, got %s — the mapper is contract-bound to expose list<string> stored types.',
            $alias,
            $actualType,
        ));
    }

    public static function duplicateReplacedAlias(string $alias): self
    {
        return new self(sprintf(
            'The #[EventType("%s")] replaces list holds a duplicate — the mapper would silently absorb it; keep the declaration canonical.',
            $alias,
        ));
    }
}
