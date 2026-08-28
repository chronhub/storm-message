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
 * A Message pairs a pure domain object with a flat bag of metadata headers; the object is a
 * command, query, or plain-object event, and only events carry the DomainEvent contract. It is the
 * single source of truth for that metadata across the framework: the aggregate repository produces
 * it, the event store persists it, and projectors read it. It deliberately has no knowledge of the
 * Symfony Messenger transport: correlation, causation, and actor travel as Messenger stamps only
 * at the dispatch boundary, where dedicated middleware maps them to and from these headers.
 * Everything else sees a plain Message.
 *
 * Headers are keyed by HeaderKey, either the framework's Header enum or an application's own, with
 * a raw string accepted as an escape hatch for ad-hoc keys. Values are JSON-friendly scalars or
 * arrays, so a Message maps cleanly onto the event store's native columns and header jsonb column.
 *
 * The class is immutable: every mutator returns a new instance.
 *
 * Two doors in, one promise. The constructor and `withHeader()` are WRITE gates holding the same
 * invariants: framework headers well typed and non-blank, the reserved `__` namespace closed to
 * unknown keys, custom keys non-blank and trimmed, and every CUSTOM value inside the JSON tree, no
 * object, no non-finite float, no sparse int-keyed level. So an envelope accepted in memory is
 * serializable and rereadable without changing its identity. The ONE deliberate asymmetry is
 * provenance: the constructor accepts framework keys spelled as raw strings, the wholesale-bag
 * door the enrichers and codecs ride, where `withHeader()` reserves the `__` namespace to the
 * `Header` enum alone, one key at a time. Hydration from DURABLE data goes through the named
 * `fromStored()` gate, which stays tolerant to unknown reserved keys a past or future framework
 * version may have written.
 *
 * @see \Storm\Contracts\Message\DomainEvent
 */
final readonly class Message
{
    /**
     * The strict write gate: the whole bag is validated here, so the reserved-namespace and
     * well-typed promises no longer depend on which construction door was chosen.
     *
     * ALWAYS strict: the write gate runs on every construction, and a public trusted-bag switch
     * would be a validation bypass one named argument away, so none exists. The validated-copy and
     * durable-hydration paths that legitimately skip it ride `clone with` from INSIDE this class;
     * the readonly private property is rewritable only from this scope, so the trusted plumbing is
     * unreachable by construction, not by discipline.
     *
     * @param  array<string, scalar|array<mixed>|null>  $headers
     *
     * @throws InvalidMessageException when attempting to wrap another Message, when a framework
     *                                 header is malformed or blank, when an unknown key squats
     *                                 the reserved `__` namespace, or when a custom value leaves
     *                                 the JSON tree
     */
    public function __construct(
        private object $message,
        private array $headers = [],
    ) {
        if ($message instanceof self) {
            throw InvalidMessageException::cannotWrapMessage();
        }

        Header::assertWriteBag($headers);

        // the second door holds the first's promise: a custom key entering here rides the same
        // nets withHeader() applies, the blank-key refusal and the JSON-tree walk, so "two doors,
        // one promise" is a fact and not a claim; framework keys were just gated by
        // assertWriteBag's typed checks
        foreach ($headers as $key => $value) {
            $key = (string) $key; // a runtime bag may carry int keys despite the phpdoc shape

            if (str_starts_with($key, Header::PREFIX)) {
                continue;
            }

            if ($key === '' || $key !== trim($key)) {
                throw InvalidMessageException::blankHeaderKey($key);
            }

            self::assertJsonTree($key, $value);
        }
    }

    /**
     * The named hydration gate for DURABLE data: store rows, stored-header stamps. Skips the write
     * bag validation deliberately, since its callers own it: `deserialize()` asserts the header form
     * at the codec boundary, and a stored header was validated when written. A row carrying a
     * reserved key from a past or future framework version must stay readable, not brick the read.
     * Never a shortcut for fresh construction: a new envelope goes through the constructor or
     * `withHeader()`.
     *
     * @param  array<string, scalar|array<mixed>|null>  $headers
     *
     * @throws InvalidMessageException when attempting to wrap another Message
     */
    public static function fromStored(object $message, array $headers = []): self
    {
        // strict construction on an empty bag, then the stored headers ride in through `clone with`,
        // the only validation-free door, and it only opens from inside this class
        return clone (new self($message), ['headers' => $headers]);
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
     * Returns a new Message with the given header set, overwriting any existing.
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
     *                                 custom value leaves the JSON tree, namely an object, a
     *                                 non-finite float, or an int-castable array key
     */
    public function withHeader(HeaderKey|string $key, int|float|string|bool|array|null $value): self
    {
        $name = self::keyOf($key);

        if ($key instanceof Header) {
            $key->assertValue($value);
        } else {
            if ($name === '' || $name !== trim($name)) {
                throw InvalidMessageException::blankHeaderKey($name);
            }

            if (str_starts_with($name, Header::PREFIX)) {
                throw InvalidMessageException::reservedHeaderKey($name);
            }

            self::assertJsonTree($name, $value);
        }

        $headers = $this->headers;
        $headers[$name] = $value;

        // this instance's bag already passed its gate and the delta was just checked; re-validating
        // the whole bag would tax every enricher step on the append path for nothing
        return clone ($this, ['headers' => $headers]);
    }

    /**
     * The value gate of the CUSTOM header surface: the docblock announces a JSON tree, the
     * signature alone would accept any `array<mixed>`. What this gate refuses:
     *
     * - An object nested in an array keeps a live reference, so the "immutable" Message mutates
     *   from outside and comes back as a plain array after the wire;
     *
     * - `NAN`/`INF` serialize past construction and explode later, at persistence, far from the
     *   writer;
     *
     * - A sparse or mixed int-keyed level flips between JSON list and object shapes across the
     *   round-trip.
     *
     * All die HERE, at the write, naming the key, so the wire contract stays checked where it is
     * authored. Genuine LISTS pass: `array_is_list` levels are the JSON arrays the tree announces.
     * Honest limit: a writer's `"0"`-string key is cast to int by PHP BEFORE any gate can see it,
     * and that single ambiguity is unobservable here, so it stays with the writer.
     *
     * @throws InvalidMessageException when the value holds an object, a non-finite float, or a
     *                                 sparse/mixed int-keyed array level
     */
    private static function assertJsonTree(string $key, mixed $value): void
    {
        // unreachable through withHeader(), whose parameter type excludes objects; the constructor
        // bag is array<string, mixed> at runtime, so the top level needs the same refusal the
        // nested levels always had
        if (is_object($value)) {
            throw InvalidMessageException::unjsonableHeaderValue($key, sprintf('a %s object keeps a live reference, mutable from outside, and comes back as a plain array after the wire', $value::class));
        }

        if (is_float($value) && ! is_finite($value)) {
            throw InvalidMessageException::unjsonableHeaderValue($key, 'a non-finite float (NAN/INF) serializes here and explodes at persistence');
        }

        if (! is_array($value)) {
            return; // remaining scalars and null are JSON-faithful by construction
        }

        $isList = array_is_list($value);

        foreach ($value as $nestedKey => $nested) {
            if (! $isList && is_int($nestedKey)) {
                throw InvalidMessageException::unjsonableHeaderValue($key, sprintf('array key %d sits in a non-list level — a sparse or mixed int-keyed array flips between JSON list and object shapes across the round-trip', $nestedKey));
            }

            // a nested object needs no arm of its own: the recursion's object refusal above meets
            // it first, one refusal for every depth
            self::assertJsonTree($key, $nested);
        }
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
     * The source stream id, for example `account-1`. Restored on every durable read: the event-store
     * `stream` column and the outbox republish alike, the latter as `partition_key AS stream`, the
     * same value under its ordering name. Null only fresh-from-domain, before the store assigned a
     * stream.
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
     * The aggregate version this event was appended at, or null when the message carries none, such
     * as a command, a query, or a fresh-from-domain message.
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
     * The message datetime from Header::OccurredAt, parsed back into a PointInTime. Distinct from
     * the store's recorded_at.
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
     *                                 must never read as the SAME null an absent one answers, since
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
