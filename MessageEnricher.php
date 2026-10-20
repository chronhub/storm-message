<?php

declare(strict_types=1);

namespace Storm\Message;

use Storm\Message\Exception\InvalidMessageException;

/**
 * Adds or completes headers on a `Message` before it is appended to the event store.
 *
 * The `AggregateRepository` write path is the chain's only consumer. Dispatch-side metadata travels
 * as Messenger stamps through `AssignMessageMetadata` instead, and the saga outbox writer builds its
 * headers explicitly: enrichment is a write-path concern, not a bus concern.
 *
 * Enrichers are small, single-purpose and idempotent. Each fills exactly one concern:
 *
 * - The message id
 * - The message type
 * - The occurred-at timestamp
 * - The ambient context of correlation, causation, actor and tenant
 *
 * Each must leave an already-present header untouched, so enriching twice is safe.
 * `EnricherRegistry` collects the implementations in priority order and applies them as a chain,
 * and since `Message` is immutable, an enricher returns a new instance rather than mutating its
 * argument.
 *
 * This contract lives in the Message package rather than in Contracts because it references the
 * concrete `Message` envelope, which keeps `chronhub/storm-contracts` free of any dependency on an
 * implementation package.
 */
interface MessageEnricher
{
    /**
     * Applies this enricher to the message, returning a new instance.
     *
     * @throws InvalidMessageException when the message carries one half of the actor identity, or a
     *                                 header the enricher sets is refused
     * @throws \Storm\Contracts\Clock\ClockExceptionContract when the clock fails to give the current time
     */
    public function enrich(Message $message): Message;
}
