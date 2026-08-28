<?php

declare(strict_types=1);

namespace Storm\Message;

use Storm\Contracts\Message\HeaderKey;
use Storm\Message\Exception\InvalidMessageException;

/**
 * The framework's canonical message header keys.
 *
 * These are the well-known headers Storm reads and writes on every Message: identity, type,
 * timestamp and the cross-cutting trace of correlation, causation, actor, aggregate, and tenant.
 * They are persisted to the event store partly as native columns, the rest in the header jsonb
 * column.
 *
 * The string values are stable wire keys with a reserved double-underscore prefix, so they never
 * collide with application-defined headers. Applications add their own keys by declaring a backed
 * enum implementing HeaderKey without the prefix.
 *
 * Note on time: Header::OccurredAt is the message's own datetime, set on the writing path from the
 * Storm clock and therefore freezable in tests. It is distinct from the event store's recorded_at
 * column, which the database auto-generates at appending time and which is surfaced on the read
 * side as part of the EventRecord, not as a header.
 *
 * @see Message
 */
enum Header: string implements HeaderKey
{
    /** The reserved prefix that keeps framework header keys from colliding with application-defined ones. */
    public const string PREFIX = '__';

    case MessageId = '__message_id';

    /**
     * The wrapped class, for in-process hydration; never the durable type. At the durable boundaries,
     * the event-store row and the neutral wire, the codecs strip this header and re-derive it from the
     * external `type` column or field via the `EventTypeMapper` alias; that alias, not the FQCN, is the
     * rename-proof stored type. One deliberate exception: the saga outbox persists the command FQCN for
     * the row's short pending life, so renaming a command class follows the same drain-before-refactor
     * deploy contract as a serializer change.
     */
    case MessageType = '__message_type';
    case OccurredAt = '__occurred_at';
    case CorrelationId = '__correlation_id';
    case CausationId = '__causation_id';
    case ActorId = '__actor_id';
    case ActorType = '__actor_type';
    case AggregateId = '__aggregate_id';
    case AggregateIdType = '__aggregate_id_type';
    case AggregateType = '__aggregate_type';
    case AggregateVersion = '__aggregate_version';
    case TenantId = '__tenant_id';
    case StreamName = '__stream_name';

    public function key(): string
    {
        return $this->value;
    }

    /**
     * Assert every framework header present in $headers carries its declared wire type and, for the
     * string cases, a non-blank value: AggregateVersion is an int, all the others are strings. The
     * serializer runs this at BOTH codec boundaries so a malformed reserved key fails loudly where the
     * bytes cross, rather than silently as a wrong-typed string read back as null, or late, deep in a
     * handler or the store; a lost correlation would otherwise mean a saga that never advances.
     * OccurredAt is checked as a non-blank string only; its datetime format is parsed by
     * Message::occurredAt() at read.
     *
     * A `__`-prefixed key that is not a known case passes UNTOUCHED: this check also guards the
     * hydration of durable data, and a row written by a past or future framework version may carry a
     * reserved key this version does not know; rejecting it would brick the row. The write gates
     * reject such keys via {@see self::assertWriteBag()}.
     *
     * @param  array<string, mixed>  $headers
     *
     * @throws InvalidMessageException when a present reserved key has the wrong wire type or a blank value
     */
    public static function assertWellTyped(array $headers): void
    {
        // driven by the BAG, not the case list: a typical bag holds 5 to 8 keys against 13 cases,
        // and this gate runs twice per event on the hottest path in the framework; the string cast
        // covers the integer key PHP mints from a numeric-string writer
        foreach ($headers as $key => $value) {
            self::tryFrom((string) $key)?->assertValue($value);
        }
    }

    /**
     * The full write-gate check for a header bag: every known framework header is well typed and
     * non-blank via {@see self::assertWellTyped()}, and no UNKNOWN key squats the reserved `__`
     * namespace. Both write doors hold it, `withHeader()` and the Message constructor alike, so the
     * promise does not depend on which door was chosen.
     *
     * Write gates only. Hydration from durable data, store rows and stored-header stamps, must stay
     * tolerant to unknown reserved keys and goes through {@see Message::fromStored()} instead.
     *
     * @param  array<string, mixed>  $headers
     *
     * @throws InvalidMessageException when a known header is malformed or blank, or an unknown key
     *                                 uses the reserved `__` prefix
     */
    public static function assertWriteBag(array $headers): void
    {
        self::assertWellTyped($headers);

        foreach (array_keys($headers) as $key) {
            $key = (string) $key; // a runtime bag may carry int keys despite the phpdoc shape

            if (str_starts_with($key, self::PREFIX) && self::tryFrom($key) === null) {
                throw InvalidMessageException::reservedHeaderKey($key);
            }
        }
    }

    /**
     * The wire check for one framework header value: int for AggregateVersion, a non-blank string for
     * every other case. Every string case is an operational identifier, a type, or a timestamp; a
     * blank one is never a legitimate value, only a bug that would otherwise surface downstream as a
     * colliding dedup key, a saga cross-route, or an unusable stored type. Shared by the codec
     * boundaries via assertWellTyped and the in-process write doors, so a malformed framework header
     * fails at the writing, not as a silent null read downstream.
     *
     * @throws InvalidMessageException when the value has the wrong wire type for this header, or is a
     *                                 blank string where an identifier is expected
     */
    public function assertValue(mixed $value): void
    {
        $expectsInt = $this === self::AggregateVersion;

        if ($expectsInt ? ! is_int($value) : ! is_string($value)) {
            throw InvalidMessageException::malformedHeader(
                $this->value,
                $expectsInt ? 'int' : 'string',
                get_debug_type($value),
            );
        }

        // blank spans Unicode: an NBSP-only id renders empty everywhere it is read, yet survives an
        // ASCII trim as present and would poison deduplication as a visually blank key
        if (! $expectsInt && preg_match('/^[\s\p{Z}]*$/u', $value) === 1) {
            throw InvalidMessageException::blankHeader($this->value);
        }
    }
}
