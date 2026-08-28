<?php

declare(strict_types=1);

namespace Storm\Message;

use Storm\Contracts\Message\SerializablePayload;

/**
 * Default SerializablePayload implementation for a domain event: the event is its payload array.
 *
 * The payload is the single source of truth: readonly, set once at construction, so a reassignment
 * outside the constructor is fatal, and immutability holds.
 *
 * Opt-in: an event needing custom serialization or deserialization simply does not use the trait.
 *
 * Constraint the trait cannot see: a composing class that declares its own constructor silently
 * replaces this one through PHP trait precedence, and fromPayload()'s `new static($payload)` then
 * feeds an array into the wrong signature, a TypeError at deserialize time, far from the mistake.
 * Keep the composer `final` and constructor-consistent; the PayloadEvent test fixture is the
 * pattern.
 *
 * @see \Storm\Contracts\Message\SerializablePayload
 */
trait HasConstructablePayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new static($payload);
    }
}
