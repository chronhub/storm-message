<?php

declare(strict_types=1);

namespace Storm\Message\Exception;

use RuntimeException;
use Storm\Message\Header;

/**
 * Thrown when a Message is constructed or mutated with an invalid payload or header; a malformed
 * wire or header condition surfaced at the boundary, so a `RuntimeException`.
 */
final class InvalidMessageException extends RuntimeException
{
    public static function cannotWrapMessage(): self
    {
        return new self('A Message cannot wrap another Message instance.');
    }

    public static function blankHeaderKey(string $key): self
    {
        return new self(sprintf(
            'The header key "%s" is blank or carries surrounding whitespace: "x", " x" and "x " would be three DIFFERENT headers, a silent near-collision.',
            addcslashes($key, "\0..\37\177"),
        ));
    }

    public static function reservedHeaderKey(string $key): self
    {
        return new self(sprintf(
            'The header key "%s" uses the "%s" prefix reserved for framework headers; application keys must not use it.',
            $key,
            Header::PREFIX,
        ));
    }

    public static function blankHeader(string $key): self
    {
        return new self(sprintf(
            'Header "%s" is present but blank: a framework header is an operational identifier, and a blank one only surfaces downstream — as a colliding dedup key, a saga cross-route, or an unusable stored type.',
            $key,
        ));
    }

    public static function halfActorIdentity(string $presentKey, string $missingKey): self
    {
        return new self(sprintf(
            'The actor identity is an atomic pair: "%s" is present but "%s" is missing. Both halves travel together or not at all — a lone half would silently drop from ambient propagation, and completing it from another provenance would forge an identity that never existed.',
            $presentKey,
            $missingKey,
        ));
    }

    public static function unjsonableHeaderValue(string $key, string $offense): self
    {
        return new self("Header '$key' carries a value outside the JSON tree the wire contract announces: $offense. Custom header values are finite scalars and string-keyed arrays of them, nothing else survives the JSON round-trip faithfully.");
    }

    public static function malformedHeader(string $key, string $expectedType, string $actualType): self
    {
        return new self(sprintf(
            'Header "%s" is present but malformed: expected %s, got %s.',
            $key,
            $expectedType,
            $actualType,
        ));
    }
}
