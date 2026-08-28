<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Message\CurrentStoredHeader;
use Storm\Message\Exception\UnbalancedContextFrame;
use Storm\Message\Header;
use Storm\Message\Message;

final class CurrentStoredHeaderTest extends TestCase
{
    #[Test]
    public function a_null_frame_shadows_the_parent_stored_header(): void
    {
        // the truth-keeper for nested unstamped dispatches: the accessors answer for the message
        // being handled NOW, null, never for the enclosing one.
        $current = new CurrentStoredHeader;
        $current->bind(new Message(new stdClass, [
            Header::AggregateType->value => 'App\\Account',
            Header::AggregateIdType->value => 'App\\AccountId',
            Header::AggregateVersion->value => 7,
            Header::OccurredAt->value => '2026-01-01T10:00:00.000000Z',
        ]));
        $current->bind(null);

        // every accessor, all four, not just the first: they each answer for the frame on top, and
        // one that reached past a null frame would hand a nested dispatch the enclosing message's
        // identity, or its instant
        $this->assertNull($current->aggregateType());
        $this->assertNull($current->aggregateIdType());
        $this->assertNull($current->aggregateVersion());
        $this->assertNull($current->occurredAt());

        $current->clear();

        $this->assertSame('App\\Account', $current->aggregateType());
        $this->assertSame('App\\AccountId', $current->aggregateIdType());
        $this->assertSame(7, $current->aggregateVersion());
        $this->assertSame('2026-01-01T10:00:00.000000+00:00', $current->occurredAt()?->toString());
    }

    #[Test]
    public function reset_wipes_leaked_frames(): void
    {
        // the kernel.reset safety net: an unbalanced app-side bind must not outlive its
        // handling window in a long-running worker.
        $current = new CurrentStoredHeader;
        $current->bind(new Message(new stdClass, [Header::AggregateType->value => 'App\\Account']));
        $current->bind(new Message(new stdClass, [Header::AggregateType->value => 'App\\Bulletin']));

        $current->reset();

        $this->assertNull($current->aggregateType());
    }

    #[Test]
    public function clear_without_a_bound_frame_fails_loud(): void
    {
        // a double-clear would otherwise silently pop the PARENT's frame; the wiring bug must
        // explode at its source, not as mis-attributed context far away.
        $this->expectException(UnbalancedContextFrame::class);

        new CurrentStoredHeader()->clear();
    }
}
