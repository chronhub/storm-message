<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Message\Tests\Fixture\PayloadEvent;

final class HasConstructablePayloadTest extends TestCase
{
    #[Test]
    public function the_factory_builds_the_payload_from_typed_args(): void
    {
        $event = PayloadEvent::record('acc-9', 1200);

        $this->assertSame(['accountId' => 'acc-9', 'amount' => 1200], $event->toPayload());
    }

    #[Test]
    public function reconstructs_typed_values_and_the_aggregate_id_from_the_payload(): void
    {
        $event = PayloadEvent::fromPayload(['accountId' => 'acc-1', 'amount' => 500]);

        $this->assertSame('acc-1', $event->aggregateId());
        $this->assertSame(500, $event->amount); // rebuilt by the get-hook from the payload
    }

    #[Test]
    public function round_trips_to_and_from_payload(): void
    {
        $event = PayloadEvent::record('acc-1', 500);

        $this->assertEquals($event->toPayload(), PayloadEvent::fromPayload($event->toPayload())->toPayload());
    }
}
