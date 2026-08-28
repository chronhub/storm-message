<?php

declare(strict_types=1);

namespace Storm\Message;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Traversable;

/**
 * Applies a chain of MessageEnricher implementations to a message, in order.
 *
 * The registry is itself a MessageEnricher, following the composite pattern, so callers depend on a
 * single enrichment entry point rather than juggling the individual enrichers. Order matters and is
 * the order given at construction; in the bundle this comes from a priority-sorted tagged iterator,
 * the storm.message_enricher tag.
 *
 * Because each enricher is idempotent and only fills missing headers, running the whole chain twice
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
        foreach ($this->enrichers as $enricher) {
            $message = $enricher->enrich($message);
        }

        return $message;
    }
}
