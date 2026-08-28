<?php

declare(strict_types=1);

namespace Storm\Message;

use RuntimeException;

/**
 * Adds or completes headers on a Message before it is appended to the event store; the
 * AggregateRepository write path is the chain's one consumer. Dispatch-side metadata instead
 * travels as Messenger stamps through AssignMessageMetadata, and the saga outbox writer builds
 * its headers explicitly: enrichment is a write-path concern, not a bus concern.
 *
 * Enrichers are small, single-purpose, and idempotent. Each fills in exactly one concern: the
 * message id, the message type, the occurred-at timestamp, or the ambient context of correlation,
 * causation, actor, and tenant. Each must leave an
 * already-present header untouched, so enriching twice is safe. Implementations are collected in
 * priority order and applied as a chain by EnricherRegistry, and because Message is immutable an
 * enricher returns a new instance rather than mutating its argument.
 *
 * This contract lives in the Message package rather than Contracts because it references the
 * concrete Message envelope; that keeps chronhub/storm-contracts free of any dependency on an
 * implementation package.
 */
interface MessageEnricher
{
    /**
     * Applies this enricher to the message, returning a new instance.
     *
     * @throws RuntimeException when enrichment fails, for example an invalid clock value or header
     */
    public function enrich(Message $message): Message;
}
