<?php

declare(strict_types=1);

namespace Storm\Message\Enricher;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\MessageEnricher;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Stamps the message datetime in `Header::OccurredAt` from the Storm clock.
 *
 * The injected `Clock` gives UTC with microsecond precision, so the value is deterministic under a
 * frozen clock in tests.
 *
 * This is the message's own time. The event store's `recorded_at`, the moment the row was
 * persisted, is a separate value the database generates.
 */
#[AutoconfigureTag('storm.message_enricher', ['priority' => 150])]
final readonly class OccurredAtEnricher implements MessageEnricher
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private Clock $clock,
    ) {}

    public function enrich(Message $message): Message
    {
        if ($message->hasHeader(Header::OccurredAt)) {
            return $message;
        }

        return $message->withHeader(
            Header::OccurredAt,
            $this->clock->now()->format(PointInTime::FORMAT),
        );
    }
}
