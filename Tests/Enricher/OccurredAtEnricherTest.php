<?php

declare(strict_types=1);

namespace Storm\Message\Tests\Enricher;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\FrozenClock;
use Storm\Message\Enricher\OccurredAtEnricher;
use Storm\Message\Header;
use Storm\Message\Message;

final class OccurredAtEnricherTest extends TestCase
{
    #[Test]
    public function stamps_occurred_at_from_the_clock(): void
    {
        $enricher = new OccurredAtEnricher(FrozenClock::at('2024-01-01T10:00:00.000000Z'));

        $message = $enricher->enrich(new Message(new stdClass));

        $this->assertSame('2024-01-01T10:00:00.000000+00:00', $message->header(Header::OccurredAt));
    }

    #[Test]
    public function keeps_existing_occurred_at(): void
    {
        $enricher = new OccurredAtEnricher(FrozenClock::at('2024-01-01T10:00:00.000000Z'));

        $message = $enricher->enrich(
            new Message(new stdClass)->withHeader(Header::OccurredAt, '2020-01-01T00:00:00.000000+00:00'),
        );

        $this->assertSame('2020-01-01T00:00:00.000000+00:00', $message->header(Header::OccurredAt));
    }
}
