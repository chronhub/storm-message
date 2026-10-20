<?php

declare(strict_types=1);

namespace Storm\Message;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Traversable;

/**
 * Applies a chain of `MessageEnricher` implementations to a message, in order.
 *
 * As a composite, it gives callers a single enrichment entry point instead of the individual
 * enrichers. Order matters and is the order given at construction; in the bundle it comes from a
 * priority-sorted tagged iterator, the `storm.message_enricher` tag.
 *
 * Each enricher is idempotent and only fills missing headers, so running the whole chain twice
 * yields the same message.
 */
#[AsAlias(MessageEnricher::class)]
final readonly class EnricherRegistry implements MessageEnricher
{
    /** @var list<MessageEnricher> */
    private array $enrichers;

    /**
     * @param  iterable<MessageEnricher>  $enrichers
     */
    public function __construct(
        #[AutowireIterator('storm.message_enricher')]
        iterable $enrichers = [],
    ) {
        $this->enrichers = $enrichers instanceof Traversable
            ? iterator_to_array($enrichers, false)
            : array_values($enrichers);
    }

    public function enrich(Message $message): Message
    {
        return array_reduce(
            $this->enrichers,
            static fn (Message $current, MessageEnricher $enricher): Message => $enricher->enrich($current),
            $message,
        );
    }
}
