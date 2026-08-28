<?php

declare(strict_types=1);

namespace Storm\Message\Enricher;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\MessageEnricher;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Stamps the message datetime in Header::OccurredAt from the Storm clock.
 *
 * Uses the injected Clock, UTC with microsecond precision, so the value is deterministic under a
 * frozen clock in tests. Idempotent: a message that already carries an occurred-at is left
 * untouched.
 *
 * This is the message's own time. The event store's recorded_at, the moment the row was persisted,
 * is a separate, database-generated value.
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

    /**
     * {@inheritDoc}
     *
     * @throws ClockExceptionContract propagated from the clock
     * @throws InvalidMessageException propagated from `Message::withHeader()`, which refuses a blank
     *                                 framework header; unreachable here, since a formatted
     *                                 `PointInTime` is always a non-blank string
     */
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
