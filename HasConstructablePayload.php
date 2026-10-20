<?php

declare(strict_types=1);

namespace Storm\Message;

use Storm\Contracts\Message\SerializablePayload;

/**
 * Default `SerializablePayload` implementation for a domain event: the event is its payload array.
 *
 * The payload is the single source of truth, readonly and set once at construction, so a
 * reassignment outside the constructor is fatal and immutability holds. The trait is opt-in: an
 * event needing custom serialization or deserialization simply does not use it.
 *
 * A trap the trait cannot see: a composing class that declares its own constructor silently
 * replaces this one through PHP trait precedence, and `fromPayload()`'s `new static($payload)` then
 * feeds an array into the wrong signature, a `TypeError` at deserialization, far from the mistake.
 * Keep the composing class `final` and constructor-consistent, as the `PayloadEvent` test fixture
 * does.
 *
 * @template TPayload of array<string, mixed> = array<string, mixed>
 *
 * @see \Storm\Contracts\Message\SerializablePayload
 */
trait HasConstructablePayload
{
    /**
     * @param  TPayload  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    /**
     * @return TPayload
     */
    public function toPayload(): array
    {
        // public by `SerializablePayload`: narrowed, a using class cannot load, which mutation
        // testing scores as an error rather than a kill, so its config leaves that mutant out
        return $this->payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): static
    {
        // public by `SerializablePayload`, like `toPayload()`, and left out of mutation the same way.
        // Stored payloads retain the broad deserialization contract and are trusted here.
        return new static($payload); // @phpstan-ignore argument.type
    }
}
