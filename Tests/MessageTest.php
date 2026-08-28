<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\Tests\Fixture\SampleHeader;
use Storm\Message\Tests\Fixture\ShadowingHeader;

final class MessageTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    #[Test]
    public function wraps_a_message_with_no_headers(): void
    {
        $event = new stdClass;

        $message = new Message($event);

        $this->assertSame($event, $message->message());
        $this->assertSame([], $message->headers());
    }

    #[Test]
    public function throws_when_wrapping_another_message(): void
    {
        $this->expectException(InvalidMessageException::class);

        new Message(new Message(new stdClass));
    }

    // -------------------------------------------------------------------------
    // Header access
    // -------------------------------------------------------------------------

    #[Test]
    public function with_header_sets_value_via_enum_key(): void
    {
        $message = new Message(new stdClass)->withHeader(Header::CorrelationId, 'corr-1');

        $this->assertSame('corr-1', $message->header(Header::CorrelationId));
    }

    #[Test]
    public function with_header_accepts_a_raw_string_key(): void
    {
        $message = new Message(new stdClass)->withHeader('x-debug', '1');

        $this->assertSame('1', $message->header('x-debug'));
    }

    #[Test]
    public function with_header_accepts_a_json_faithful_custom_tree(): void
    {
        // the documented extension surface: finite scalars, string-keyed maps, genuine lists
        $message = new Message(new stdClass)->withHeader('x-audit', [
            'actor' => 'ops-1',
            'tags' => ['fee', 'manual'],
            'depth' => ['level' => 2, 'ratio' => 0.5],
        ]);

        $this->assertSame(['fee', 'manual'], $message->header('x-audit')['tags'] ?? null);
    }

    #[Test]
    public function with_header_rejects_a_nested_object_that_would_break_immutability(): void
    {
        // an object nested in the array keeps a live reference: the "immutable" Message would
        // mutate from outside and come back as a plain array after the wire
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('live reference');

        new Message(new stdClass)->withHeader('x-audit', ['actor' => new stdClass]);
    }

    #[Test]
    public function with_header_reaches_the_bottom_of_a_nested_tree(): void
    {
        // The guard walks the tree rather than its first level, and depth is exactly where an
        // offending value hides: a bag assembled by several enrichers nests, and a check that
        // stopped at the top would pass the header, then break at persistence with no key named.
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('live reference');

        new Message(new stdClass)->withHeader('x-audit', ['trace' => ['step' => ['actor' => new stdClass]]]);
    }

    #[Test]
    public function with_header_rejects_a_non_finite_float_before_it_explodes_at_persistence(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('non-finite');

        new Message(new stdClass)->withHeader('x-ratio', NAN);
    }

    #[Test]
    public function with_header_rejects_a_sparse_int_keyed_level(): void
    {
        // not a list, not an object: [1 => ...] flips between JSON shapes across the round-trip
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('non-list level');

        new Message(new stdClass)->withHeader('x-audit', [1 => 'a', 5 => 'b']);
    }

    #[Test]
    public function with_header_rejects_a_raw_string_key_using_the_reserved_prefix(): void
    {
        // the `__` prefix is reserved for framework headers; the raw-string escape hatch must not be
        // allowed to shadow one.
        $this->expectException(InvalidMessageException::class);

        new Message(new stdClass)->withHeader('__aggregate_version', 'oops');
    }

    #[Test]
    public function with_header_rejects_a_custom_header_key_shadowing_the_reserved_prefix(): void
    {
        // implementing HeaderKey does not grant the `__` namespace: the guard tests PROVENANCE,
        // the framework's own Header enum, not the key's PHP type.
        $this->expectException(InvalidMessageException::class);

        new Message(new stdClass)->withHeader(ShadowingHeader::Correlation, 'spoofed');
    }

    #[Test]
    public function with_header_rejects_a_mistyped_framework_header_value(): void
    {
        // an int correlation id would read back as a silent null, stamp skipped and saga starved;
        // the write door fails loud instead, sharing the boundary's own type table.
        $this->expectException(InvalidMessageException::class);

        new Message(new stdClass)->withHeader(Header::CorrelationId, 42);
    }

    #[Test]
    public function with_header_accepts_the_int_aggregate_version(): void
    {
        $message = new Message(new stdClass)->withHeader(Header::AggregateVersion, 7);

        $this->assertSame(7, $message->aggregateVersion());
    }

    #[Test]
    public function with_header_leaves_application_key_values_free(): void
    {
        // the wire-type table is framework-keys only; an application key keeps the full union
        $message = new Message(new stdClass)->withHeader(SampleHeader::Channel, 42);

        $this->assertSame(42, $message->header(SampleHeader::Channel));
    }

    #[Test]
    public function with_header_accepts_a_custom_header_key(): void
    {
        $message = new Message(new stdClass)->withHeader(SampleHeader::Channel, 'web');

        $this->assertSame('web', $message->header(SampleHeader::Channel));
        $this->assertSame('web', $message->header('channel'));
    }

    #[Test]
    public function header_returns_null_when_absent(): void
    {
        $this->assertNull(new Message(new stdClass)->header(Header::MessageId));
    }

    #[Test]
    public function has_header_reflects_presence(): void
    {
        $message = new Message(new stdClass)->withHeader(Header::MessageId, 'id-1');

        $this->assertTrue($message->hasHeader(Header::MessageId));
        $this->assertFalse($message->hasHeader(Header::MessageType));
    }

    #[Test]
    public function with_header_overwrites_an_existing_value(): void
    {
        // the with* family is SET semantics where the arg wins, the counterpart of the enrichers'
        // fill-missing-only contract
        $message = new Message(new stdClass)
            ->withHeader(Header::MessageId, 'id-1')
            ->withHeader(Header::MessageId, 'id-2');

        $this->assertSame('id-2', $message->messageId());
    }

    // -------------------------------------------------------------------------
    // Immutability
    // -------------------------------------------------------------------------

    #[Test]
    public function with_header_returns_new_instance(): void
    {
        $message = new Message(new stdClass);
        $result = $message->withHeader(Header::MessageId, 'id-1');

        $this->assertNotSame($message, $result);
    }

    #[Test]
    public function with_header_does_not_mutate_original(): void
    {
        $message = new Message(new stdClass);

        $message->withHeader(Header::MessageId, 'id-1');

        $this->assertSame([], $message->headers());
    }

    // -------------------------------------------------------------------------
    // Typed framework-header accessors
    // -------------------------------------------------------------------------

    #[Test]
    public function exposes_typed_framework_headers(): void
    {
        $message = new Message(new stdClass)
            ->withHeader(Header::MessageId, 'evt')
            ->withHeader(Header::MessageType, 'order.placed')
            ->withHeader(Header::CorrelationId, 'corr')
            ->withHeader(Header::CausationId, 'caus')
            ->withHeader(Header::ActorId, 'actor')
            ->withHeader(Header::ActorType, 'user')
            ->withHeader(Header::AggregateId, 'agg')
            ->withHeader(Header::AggregateIdType, 'App\\OrderId')
            ->withHeader(Header::AggregateType, 'order')
            ->withHeader(Header::AggregateVersion, 3)
            ->withHeader(Header::TenantId, 'tenant');

        $this->assertSame('evt', $message->messageId());
        $this->assertSame('order.placed', $message->messageType());
        $this->assertSame('corr', $message->correlationId());
        $this->assertSame('caus', $message->causationId());
        $this->assertSame('actor', $message->actorId());
        $this->assertSame('user', $message->actorType());
        $this->assertSame('agg', $message->aggregateId());
        $this->assertSame('App\\OrderId', $message->aggregateIdType());
        $this->assertSame('order', $message->aggregateType());
        $this->assertSame(3, $message->aggregateVersion());
        $this->assertSame('tenant', $message->tenantId());
    }

    #[Test]
    public function typed_accessors_are_null_when_absent(): void
    {
        $message = new Message(new stdClass);

        $this->assertNull($message->messageId());
        $this->assertNull($message->aggregateVersion());
        $this->assertNull($message->occurredAt());
    }

    #[Test]
    public function aggregate_version_throws_when_present_but_not_an_int(): void
    {
        // a corrupt envelope, for example a hand-edited jsonb row, must surface, not mask as "absent";
        // this value feeds optimistic-concurrency math. Built through fromStored, the trusted hydration
        // path and the only door such a value can enter by: both write gates reject the mistype.
        $message = Message::fromStored(new stdClass, [Header::AggregateVersion->value => 'three']);

        $this->expectException(InvalidMessageException::class);

        $message->aggregateVersion();
    }

    #[Test]
    public function occurred_at_is_parsed_into_point_in_time(): void
    {
        $message = new Message(new stdClass)
            ->withHeader(Header::OccurredAt, '2024-01-01T10:00:00.000000+00:00');

        $occurredAt = $message->occurredAt();

        $this->assertInstanceOf(PointInTime::class, $occurredAt);
        $this->assertSame('2024-01-01T10:00:00.000000+00:00', $occurredAt->toString());
    }

    #[Test]
    #[Group('adversarial')]
    public function the_constructor_rejects_an_unknown_reserved_key_like_with_header_does(): void
    {
        // the `__` namespace promise does not depend on which write door was chosen: a key
        // withHeader refuses, the constructor refuses too, leaving no silent round-trip of a squatted key
        $this->expectException(InvalidMessageException::class);

        new Message(new stdClass, ['__future_framework_key' => 'v']);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_constructor_rejects_a_mistyped_framework_header(): void
    {
        // the write gate refuses what the read gate would refuse: a mistyped framework header,
        // otherwise an accepted-but-unreadable envelope caught only at deserialize()
        $this->expectException(InvalidMessageException::class);

        new Message(new stdClass, [Header::CorrelationId->value => 42]);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_constructor_rejects_a_blank_identifier(): void
    {
        $this->expectException(InvalidMessageException::class);

        new Message(new stdClass, [Header::MessageId->value => '   ']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_unicode_blank_operational_identifier_is_refused(): void
    {
        // blankness spans Unicode: an NBSP-only id renders empty everywhere it is read and would
        // poison deduplication as a visually blank key, so the write door refuses it
        $this->expectException(InvalidMessageException::class);

        new Message(new stdClass)->withHeader(Header::MessageId, "\u{00A0}");
    }

    #[Test]
    public function from_stored_tolerates_an_unknown_reserved_key(): void
    {
        // the named hydration gate: a durable row written by a past or future framework version may
        // carry a reserved key this version does not know; it must stay readable, never brick
        $message = Message::fromStored(new stdClass, ['__future_framework_key' => 'v']);

        $this->assertSame('v', $message->header('__future_framework_key'));
    }

    #[Test]
    public function a_present_but_mistyped_string_header_throws_instead_of_reading_as_absent(): void
    {
        // the mirror of the aggregateVersion() refusal, decided rather than left open: a corrupt
        // correlation must never answer the SAME null an absent one does, since a silently lost
        // correlation is a saga that never advances
        $message = Message::fromStored(new stdClass, ['__correlation_id' => 42]);

        $this->expectException(InvalidMessageException::class);

        $message->correlationId();
    }

    #[Test]
    public function every_header_case_has_a_message_accessor_and_this_map_is_exhaustive(): void
    {
        // adding a Header case ripples through ten wiring sites and nothing structural catches a
        // partial one; THIS map is the structural half: a new case must be added here with its
        // accessor and a readable sample, or the build reds
        $samples = [
            Header::MessageId->value => ['msg-1', static fn (Message $m): ?string => $m->messageId()],
            Header::MessageType->value => [stdClass::class, static fn (Message $m): ?string => $m->messageType()],
            Header::OccurredAt->value => ['2024-01-01T10:00:00.000000+00:00', static fn (Message $m): ?PointInTime => $m->occurredAt()],
            Header::CorrelationId->value => ['c-1', static fn (Message $m): ?string => $m->correlationId()],
            Header::CausationId->value => ['k-1', static fn (Message $m): ?string => $m->causationId()],
            Header::ActorId->value => ['a-1', static fn (Message $m): ?string => $m->actorId()],
            Header::ActorType->value => ['user', static fn (Message $m): ?string => $m->actorType()],
            Header::AggregateId->value => ['agg-1', static fn (Message $m): ?string => $m->aggregateId()],
            Header::AggregateIdType->value => ['uuid', static fn (Message $m): ?string => $m->aggregateIdType()],
            Header::AggregateType->value => ['App\\Account', static fn (Message $m): ?string => $m->aggregateType()],
            Header::AggregateVersion->value => [3, static fn (Message $m): ?int => $m->aggregateVersion()],
            Header::TenantId->value => ['t-1', static fn (Message $m): ?string => $m->tenantId()],
            Header::StreamName->value => ['order-1', static fn (Message $m): ?string => $m->streamName()],
        ];

        $cases = array_map(static fn (Header $h): string => $h->value, Header::cases());
        sort($cases);
        $mapped = array_keys($samples);
        sort($mapped);
        $this->assertSame($cases, $mapped, 'a Header case has no accessor mapping here');

        foreach ($samples as $key => [$value, $read]) {
            $this->assertNotNull($read(Message::fromStored(new stdClass, [$key => $value])), $key);
        }
    }

    #[Test]
    public function an_integer_custom_key_is_validated_not_died_on(): void
    {
        // PHP normalizes '7' to int 7 at array write; under strict types an uncast key would die
        // on str_starts_with() with a TypeError the constructor's clause never declared
        $message = new Message(new stdClass, ['7' => 'v']); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)

        $this->assertSame('v', $message->header('7'));
    }

    #[Test]
    public function both_write_doors_refuse_a_blank_or_padded_custom_key(): void
    {
        // "x", " x" and "x " are three different headers, a silent near-collision the module's own
        // bag carriers refuse one layer up; the envelope, the thing that persists, must not be the
        // most permissive link in its own chain
        foreach (['', '   ', ' x', 'x '] as $key) {
            try {
                new Message(new stdClass, [$key => 'v']);
                $this->fail(sprintf('the constructor door must refuse the key "%s"', $key));
            } catch (InvalidMessageException $e) {
                $this->assertStringContainsString('blank', $e->getMessage());
            }

            try {
                new Message(new stdClass)->withHeader($key, 'v');
                $this->fail(sprintf('the withHeader door must refuse the key "%s"', $key));
            } catch (InvalidMessageException $e) {
                $this->assertStringContainsString('blank', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_constructor_door_holds_the_json_tree_net_for_custom_values(): void
    {
        // "two doors, one promise" as a fact: a nested object entering by the constructor refuses
        // exactly as it does through withHeader(), never a silent flatten on the wire far from its
        // writer
        $this->expectException(InvalidMessageException::class);

        new Message(new stdClass, ['custom' => ['nested' => new stdClass]]);
    }

    #[Test]
    public function the_constructor_door_refuses_a_top_level_object_value(): void
    {
        // withHeader()'s parameter type excludes objects; the constructor bag is mixed at runtime,
        // so the top level needs the same refusal the nested levels always had
        $this->expectException(InvalidMessageException::class);

        // @phpstan-ignore argument.type (the declared shape excludes objects; the runtime guard exists for the mixed-at-runtime caller this simulates)
        new Message(new stdClass, ['custom' => new stdClass]);
    }
}
