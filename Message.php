<?php

declare(strict_types=1);

namespace Storm\Message;

use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Message\DomainEvent;
use Storm\Contracts\Message\HeaderKey;
use Storm\Message\Exception\InvalidMessageException;

/**
 * The neutral, canonical envelope around a domain message.
 *
 * A `Message` pairs a pure domain object, a command, a query or a plain-object event, with a flat
 * bag of metadata headers; only events carry the `DomainEvent` contract. It is the single source of
 * truth for that metadata across the framework: the aggregate repository produces it, the event
 * store persists it, and projectors read it.
 *
 * It has no knowledge of the Symfony Messenger transport. Correlation, causation and actor travel
 * as Messenger stamps only at the dispatch boundary, where dedicated middleware maps them to and
 * from these headers; everything else sees a plain `Message`.
 *
 * Headers are keyed by `HeaderKey`, the framework's `Header` enum or an application's own, with a
 * raw string accepted for ad-hoc keys. Values are JSON-friendly scalars or arrays, so a `Message`
 * maps onto the event store's native columns and header jsonb column.
 *
 * The class is immutable: every mutator returns a new instance. Header arrays are copied
 * recursively on entry, which detaches PHP references without converting scalar types.
 *
 * The constructor and `withHeader()` are two write gates that hold the same invariants, checked at
 * write time:
 *
 * - Framework headers are well typed and non-blank
 *
 * - The reserved `__` namespace is closed to unknown keys
 *
 * - Custom keys are non-blank and trimmed
 *
 * - Custom values stay inside the JSON tree: no object, no non-finite float, no sparse int-keyed
 *   level. Custom strings and keys must be UTF-8, leaves must be JSON scalars or null, and nesting
 *   stops at 509 array levels, which leaves room for the header bag and the wire envelope within
 *   the default JSON decoding depth
 *
 * Their one asymmetry is provenance. The constructor accepts framework keys spelled as raw strings,
 * the wholesale-bag door the enrichers and codecs use, while `withHeader()` reserves the `__`
 * namespace to the `Header` enum alone, one key at a time. Hydration from durable data goes through
 * the named `fromStored()` gate, which stays tolerant to unknown reserved keys a past or future
 * framework version may have written.
 *
 * @see \Storm\Contracts\Message\DomainEvent
 */
final readonly class Message
{
    /**
     * @var array<string, scalar|array<mixed>|null>
     */
    private array $headers;

    /**
     * The strict write gate, which validates the whole bag.
     *
     * The reserved-namespace and well-typed promises then hold whichever door builds the message. The
     * gate runs on every construction: a public trusted-bag switch would put a validation bypass one
     * named argument away, so none exists. The validated-copy and durable-hydration paths that skip
     * it use `clone with` from inside this class; the readonly private property is rewritable only
     * from this scope, so the trusted path is unreachable by construction rather than by discipline.
     *
     * @param  array<string, scalar|array<mixed>|null>  $headers
     *
     * @throws InvalidMessageException when attempting to wrap another Message, when a framework
     *                                 header is malformed or blank, when an unknown key takes
     *                                 the reserved `__` namespace, or when a custom value leaves
     *                                 the JSON tree
     */
    public function __construct(
        private object $message,
        array $headers = [],
    ) {
        if ($message instanceof self) {
            throw InvalidMessageException::cannotWrapMessage();
        }

        Header::assertWriteBag($headers);

        // a custom key entering here gets the checks withHeader() applies, the blank-key refusal and
        // the JSON-tree walk; assertWriteBag() has just checked the framework keys
        foreach ($headers as $key => $value) {
            $key = (string) $key; // a runtime bag may carry int keys despite the phpdoc shape

            if (str_starts_with($key, Header::PREFIX)) {
                continue;
            }

            Header::assertCustomKey($key);
            self::assertJsonTree($key, $value);
        }

        $this->headers = self::detachHeaders($headers);
    }

    /**
     * The named hydration gate for durable data: store rows and stored-header stamps.
     *
     * It skips the write-bag validation, since its callers own it: `deserialize()` asserts the header
     * form at the codec boundary, and a stored header was validated when written. A row carrying a
     * reserved key from a past or future framework version must stay readable. Array copying keeps
     * historical keys and shapes but bounds nesting to the stored header JSON depth.
     *
     * It is never a shortcut for fresh construction: a new envelope goes through the constructor or
     * `withHeader()`.
     *
     * @param  array<string, scalar|array<mixed>|null>  $headers
     *
     * @throws InvalidMessageException when attempting to wrap another Message or when stored
     *                                 arrays exceed the stored header nesting depth
     */
    public static function fromStored(object $message, array $headers = []): self
    {
        // strict construction on an empty bag, then the stored headers ride in through `clone with`,
        // the only validation-free door, and it only opens from inside this class
        return clone (new self($message), ['headers' => self::detachHeaders($headers)]);
    }

    /**
     * The wrapped domain message: a command, query, or event.
     */
    public function message(): object
    {
        return $this->message;
    }

    public function header(HeaderKey|string $key): mixed
    {
        return $this->headers[self::keyOf($key)] ?? null;
    }

    public function hasHeader(HeaderKey|string $key): bool
    {
        return array_key_exists(self::keyOf($key), $this->headers);
    }

    /**
     * Returns a new `Message` with the given header set, overwriting any existing one.
     *
     * @param  scalar|array<mixed>|null  $value
     *
     * @throws InvalidMessageException when the key uses the reserved framework prefix, raw string or
     *                                 custom HeaderKey enum alike, since the framework's own Header
     *                                 enum is the only allowed `__` writer; when the key is blank or
     *                                 whitespace, a silent near-collision where `x`, ` x` and `x `
     *                                 are three different headers; when a framework header value has
     *                                 the wrong wire type or is blank, since a mistyped
     *                                 CorrelationId would otherwise read back as a silent null, and
     *                                 a lost correlation means a saga that never advances; or when a
     *                                 custom key or value violates the JSON tree contract
     */
    public function withHeader(HeaderKey|string $key, int|float|string|bool|array|null $value): self
    {
        $name = self::keyOf($key);

        if ($key instanceof Header) {
            $key->assertValue($value);
        } else {
            Header::assertCustomKey($name);
            self::assertJsonTree($name, $value);
        }

        $headers = $this->headers;
        // one level under the bag: assertJsonTree() already stops the value at 509 levels, below the
        // 510 the detach allows, so the mutation config leaves the equivalent `0` and `2` mutants out
        $headers[$name] = is_array($value) ? self::detachHeaders($value, 1) : $value;

        // this instance's bag already passed its gate and the delta was just checked; re-validating
        // the whole bag would tax every enricher step on the append path for nothing
        return clone ($this, ['headers' => $headers]);
    }

    /**
     * Validates custom values before they reach a JSON codec.
     *
     * Refuses:
     *
     * - Objects and resources
     * - Non-finite floats
     * - Invalid UTF-8 in strings or keys
     * - Sparse or mixed int-keyed array levels
     * - More than 509 nested array levels, including cycles
     *
     * Lists retain their JSON array shape. PHP casts integer-like string keys before this gate,
     * so an original string key that PHP converts to an integer is not distinguishable here.
     *
     * @throws InvalidMessageException when a custom key or value violates these constraints
     */
    private static function assertJsonTree(string $key, mixed $value, int $depth = 0): void
    {
        if ($depth === 0 && preg_match('//u', $key) !== 1) {
            throw InvalidMessageException::unjsonableHeaderValue($key, 'the header key is not valid UTF-8');
        }

        if (is_string($value) && preg_match('//u', $value) !== 1) {
            throw InvalidMessageException::unjsonableHeaderValue($key, 'a string is not valid UTF-8');
        }

        // unreachable through withHeader(), whose parameter type excludes objects; the constructor bag
        // is array<string, mixed> at runtime, so the top level needs the refusal the nested levels have
        if (is_object($value)) {
            throw InvalidMessageException::unjsonableHeaderValue($key, sprintf('a %s object keeps a live reference, mutable from outside, and comes back as a plain array after the wire', $value::class));
        }

        if (is_float($value) && ! is_finite($value)) {
            throw InvalidMessageException::unjsonableHeaderValue($key, 'a non-finite float (NAN/INF) serializes here and explodes at persistence');
        }

        if (! is_array($value)) {
            if ($value !== null && ! is_scalar($value)) {
                throw InvalidMessageException::unjsonableHeaderValue($key, 'only JSON scalars, null and arrays are accepted');
            }

            return;
        }

        if ($depth >= 509) {
            throw InvalidMessageException::unjsonableHeaderValue($key, 'array nesting exceeds the 509 levels available inside the wire envelope');
        }

        $isList = array_is_list($value);

        foreach ($value as $nestedKey => $nested) {
            if (is_string($nestedKey) && preg_match('//u', $nestedKey) !== 1) {
                throw InvalidMessageException::unjsonableHeaderValue($key, 'an array key is not valid UTF-8');
            }

            if (! $isList && is_int($nestedKey)) {
                throw InvalidMessageException::unjsonableHeaderValue($key, sprintf('array key %d sits in a non-list level — a sparse or mixed int-keyed array flips between JSON list and object shapes across the round-trip', $nestedKey));
            }

            // a nested object needs no arm of its own: the recursion's object refusal above meets
            // it first, one refusal for every depth
            self::assertJsonTree($key, $nested, $depth + 1);
        }
    }

    /**
     * @template T of array
     *
     * @param  T  $values
     * @return T
     */
    private static function detachHeaders(array $values, int $depth = 0): array
    {
        if ($depth > 510) {
            throw InvalidMessageException::unjsonableHeaderValue('headers', 'array nesting exceeds the 510 levels available inside stored header JSON');
        }

        $copy = [];
        foreach ($values as $key => $value) {
            $copy[$key] = is_array($value) ? self::detachHeaders($value, $depth + 1) : $value;
        }

        // Every key and leaf type is preserved; only reference cells are detached.
        // @phpstan-ignore return.type
        return $copy;
    }

    /**
     * @return array<string, scalar|array<mixed>|null>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function messageId(): ?string
    {
        return $this->stringHeader(Header::MessageId);
    }

    public function messageType(): ?string
    {
        return $this->stringHeader(Header::MessageType);
    }

    public function correlationId(): ?string
    {
        return $this->stringHeader(Header::CorrelationId);
    }

    public function causationId(): ?string
    {
        return $this->stringHeader(Header::CausationId);
    }

    public function actorId(): ?string
    {
        return $this->stringHeader(Header::ActorId);
    }

    public function actorType(): ?string
    {
        return $this->stringHeader(Header::ActorType);
    }

    public function aggregateId(): ?string
    {
        return $this->stringHeader(Header::AggregateId);
    }

    public function aggregateType(): ?string
    {
        return $this->stringHeader(Header::AggregateType);
    }

    /**
     * The source stream id, such as `account-1`.
     *
     * Every durable read restores it: the event-store `stream` column, and the outbox republish as
     * `partition_key AS stream`, the same value under its ordering name. It is null only fresh from
     * the domain, before the store assigned a stream.
     */
    public function streamName(): ?string
    {
        return $this->stringHeader(Header::StreamName);
    }

    public function aggregateIdType(): ?string
    {
        return $this->stringHeader(Header::AggregateIdType);
    }

    public function tenantId(): ?string
    {
        return $this->stringHeader(Header::TenantId);
    }

    /**
     * The saga-issued mark a trusted neutral wire carries, the command's own message id.
     *
     * It is never ambient context: only the neutral serializer reads it, to rebuild the mark.
     */
    public function sagaCommandId(): ?string
    {
        return $this->stringHeader(Header::SagaCommandId);
    }

    /**
     * The aggregate version this event was appended at, or null when the message carries none.
     *
     * A command, a query or a message fresh from the domain carries none.
     *
     * @throws InvalidMessageException when the header is present but not an integer; a corrupt
     *                                 envelope surfaced rather than masked, because this value feeds
     *                                 optimistic concurrency
     */
    public function aggregateVersion(): ?int
    {
        if (! $this->hasHeader(Header::AggregateVersion)) {
            return null;
        }

        $value = $this->header(Header::AggregateVersion);

        if (! is_int($value)) {
            throw InvalidMessageException::malformedHeader(
                Header::AggregateVersion->key(),
                'int',
                get_debug_type($value),
            );
        }

        return $value;
    }

    /**
     * The message datetime from `Header::OccurredAt`, parsed back into a `PointInTime`.
     *
     * It is distinct from the store's `recorded_at`.
     *
     * @throws InvalidDateTimeException when the stored header is not a canonical Storm datetime
     */
    public function occurredAt(): ?PointInTime
    {
        $value = $this->stringHeader(Header::OccurredAt);

        return $value === null ? null : PointInTime::from($value);
    }

    /**
     * @throws InvalidMessageException when the header is present but not a string: a corrupt value
     *                                 must never read as the same null an absent one answers, since
     *                                 a silently lost correlation is a saga that never advances;
     *                                 the mirror of the `aggregateVersion()` refusal
     */
    private function stringHeader(HeaderKey $key): ?string
    {
        $value = $this->header($key);

        if ($value !== null && ! is_string($value)) {
            throw InvalidMessageException::malformedHeader(self::keyOf($key), 'string', get_debug_type($value));
        }

        return $value;
    }

    private static function keyOf(HeaderKey|string $key): string
    {
        return $key instanceof HeaderKey ? $key->key() : $key;
    }
}
