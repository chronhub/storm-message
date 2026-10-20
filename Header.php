<?php

declare(strict_types=1);

namespace Storm\Message;

use Storm\Contracts\Message\HeaderKey;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Text\Str;

/**
 * The framework's canonical message header keys.
 *
 * Storm reads and writes them on every `Message`:
 *
 * - Identity, type and timestamp
 * - The cross-cutting trace of correlation, causation, actor, aggregate and tenant
 *
 * The event store persists some as native columns and the rest in the header jsonb column.
 *
 * The string values are stable wire keys with a reserved double-underscore prefix, so they never
 * collide with application-defined headers. An application adds its own keys through a backed enum
 * implementing `HeaderKey`, without the prefix.
 *
 * `Header::OccurredAt` is the message's own datetime, set on the writing path from the Storm clock
 * and so freezable in tests. It is distinct from the event store's `recorded_at` column, which the
 * database generates at append time and the read side surfaces on the `EventRecord`, not as a
 * header.
 *
 * @see Message
 */
enum Header: string implements HeaderKey
{
    /** The reserved prefix that keeps framework header keys from colliding with application-defined ones. */
    public const string PREFIX = '__';

    case MessageId = '__message_id';

    /**
     * The wrapped class, for in-process hydration, never the durable type.
     *
     * Every durable boundary of Storm strips this header and derives it again from its external `type`
     * column or field through the `MessageTypeMapper` alias, the rename-proof stored type:
     *
     * - The event-store row
     * - The neutral wire
     * - The saga outbox row, and the event a redelivery seals inside it
     *
     * A Messenger lane on `PhpSerializer` and the failure transport keep the class, so renaming a
     * message class waits until they are drained.
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

    /**
     * The saga-issued mark on a neutral wire, valued with the command's own `__message_id`.
     *
     * Only a trusted channel turns it back into the mark, and it never becomes ambient context.
     */
    case SagaCommandId = '__saga_command_id';

    public function key(): string
    {
        return $this->value;
    }

    /**
     * Assert that every framework header present in `$headers` carries its declared wire type.
     *
     * `AggregateVersion` is an int; every other case is a non-blank string. The serializer runs this
     * at both codec boundaries, so a malformed reserved key fails where the bytes cross. Read back
     * later, deep in a handler or the store, a wrong-typed string would silently come out as null,
     * and a lost correlation would leave a saga that never advances. `OccurredAt` is checked as a
     * non-blank string only; `Message::occurredAt()` parses its datetime format at read.
     *
     * A `__`-prefixed key that is not a known case passes untouched: this check also guards the
     * hydration of durable data, and a row written by a past or future framework version may carry a
     * reserved key this version does not know; rejecting it would make the row unreadable. The write
     * gates reject such keys through {@see self::assertWriteBag()}.
     *
     * @param  array<string, mixed>  $headers
     *
     * @throws InvalidMessageException when a present reserved key has the wrong wire type or a blank value
     */
    public static function assertWellTyped(array $headers): void
    {
        // iterates the bag, typically 5 to 8 keys, rather than the 14 cases: this gate runs twice per
        // event on the hottest path; the string cast covers the int key PHP mints from a numeric string
        foreach ($headers as $key => $value) {
            self::tryFrom((string) $key)?->assertValue($value);
        }
    }

    /**
     * The full write-gate check for a header bag.
     *
     * Every known framework header is well typed and non-blank, through
     * {@see self::assertWellTyped()}, and no unknown key takes the reserved `__` namespace. Both
     * write doors run it, `withHeader()` and the `Message` constructor, so the promise does not depend
     * on the door.
     *
     * It serves write gates only. Hydration from durable data, store rows and stored-header stamps,
     * must stay tolerant to unknown reserved keys and goes through {@see Message::fromStored()}
     * instead.
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
     * Assert that `$key` can name an application header.
     *
     * The key is non-blank and unpadded, since `x`, ` x` and `x ` would be three different headers,
     * and stays outside the reserved prefix. The blank check runs first, so a padded reserved key is
     * refused as padded.
     *
     * @throws InvalidMessageException when the key is blank or padded, or uses the reserved `__` prefix
     */
    public static function assertCustomKey(string $key): void
    {
        if ($key === '' || $key !== trim($key)) {
            throw InvalidMessageException::blankHeaderKey($key);
        }

        if (str_starts_with($key, self::PREFIX)) {
            throw InvalidMessageException::reservedHeaderKey($key);
        }
    }

    /**
     * The wire check for one framework header value.
     *
     * `AggregateVersion` takes an int and every other case a non-blank string. Each string case is an
     * operational identifier, a type or a timestamp, so a blank one is never legitimate, only a bug
     * that would otherwise surface downstream as one of these:
     *
     * - A colliding deduplication key
     * - A saga routed to the wrong instance
     * - An unusable stored type
     *
     * The codec boundaries, through `assertWellTyped()`, and the in-process write doors share it, so a
     * malformed framework header fails at the write, not as a silent null read downstream.
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

        // blank spans Unicode: an id of no-break spaces renders empty everywhere it is read, yet survives
        // an ASCII trim as present and would defeat deduplication as a visually blank key
        if (! $expectsInt && Str::isBlank($value)) {
            throw InvalidMessageException::blankHeader($this->value);
        }
    }
}
