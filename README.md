# Storm Message

The neutral, transport-free **envelope** for domain messages.

A `Message` pairs a pure domain object — a command, query or event — with a flat bag
of **headers** (its metadata). It is the single source of truth for that metadata
across Storm: the aggregate repository produces it, the event store persists it, and
projectors read it. It has **no knowledge of the transport** (Symfony Messenger):
correlation/causation/actor travel as Messenger stamps only at the dispatch boundary,
where a single middleware maps them to and from these headers. Everything else sees a
plain `Message`.

> Part of the [Storm](../../README.md) monorepo. This package is split, read-only, to
> `storm/message`; develop it in the monorepo.

## Install

```bash
composer require chronhub/storm-message
```

> **Two audiences, one package.** This is the envelope INFRASTRUCTURE (Message, Header, enrichers,
> ambient holders) — and also the domain-authoring KIT an application's domain layer requires
> (`HasConstructablePayload`, `#[EventType]`). The kit itself uses none of the package's
> dependencies, but pulls them into a pure domain's vendor tree. A clean cut (a dependency-free
> `storm-message-kit`, or relaxing Contracts' interfaces-only stance) is a subtree-split-era
> decision — deliberately not made before the packages are published separately.

## The domain-authoring kit

Three pieces your domain layer uses directly; none drags infrastructure into it:

- **`HasConstructablePayload`** — the explicit-serialization idiom: your event IS its payload
  array (`private readonly array $payload` + `toPayload()`), accessors read keys, `fromPayload()`
  reconstructs. Storm serializes NOTHING by reflection — an event that cannot state its own array
  shape does not travel.

```php
use Storm\Message\EventType;
use Storm\Message\HasConstructablePayload;
use Storm\Contracts\Message\DomainEvent;

#[EventType('order.placed', version: 1)]
final class OrderPlaced implements DomainEvent
{
    use HasConstructablePayload;

    public string $orderId { get => (string) $this->payload['order_id']; }
}
```

- **`#[EventType(alias, version, replaces)]`** — the portable identity of an event: the alias is
  what the store's `type` column and the neutral wire carry (rename-proof: the FQCN never leaves
  the process), the version drives upcasting, `replaces` keeps former aliases resolvable.
- **`#[Personal(subject, keys, fallback)]`** — the crypto-shredding declaration: which payload
  keys are personal data encrypted per subject, and what each renders as once the subject's key is
  destroyed. Declaring it is all a domain does; the machinery lives elsewhere.

## Headers

Headers are keyed by `HeaderKey`. The framework keys are the `Header` enum; values are
JSON-friendly scalars or arrays, so a `Message` maps cleanly onto the event store's
native columns and `header` jsonb column.

```php
use Storm\Message\Header;
use Storm\Message\Message;

$message = (new Message($orderPlaced))
    ->withHeader(Header::CorrelationId, $correlationId);
// __message_id, __message_type and __occurred_at are stamped by the enrichers on the write path

$message->correlationId();   // typed accessor for a framework header
$message->occurredAt();      // ?PointInTime — null until enriched
$message->headers();         // the full bag
```

`Message` is immutable — every mutator returns a new instance.

### Custom headers

Applications extend the header space with their own backed enum implementing
`HeaderKey` (no registry, no central registration). The `__` prefix is reserved for
framework keys.

```php
use Storm\Contracts\Message\HeaderKey;

enum OrderHeader: string implements HeaderKey
{
    case Channel = 'channel';

    public function key(): string
    {
        return $this->value;
    }
}

$message = $message->withHeader(OrderHeader::Channel, 'web'); // type-safe
$message = $message->withHeader('x-debug', '1');              // raw string escape hatch
```

## Message context

`MessageContext` is a tiny, framework-neutral view of "who/what caused the work in
flight" (correlation, causation, actor, tenant). `CurrentMessageContext` is the
ambient holder bound at the dispatch boundary; the aggregate repository reads it to
stamp recorded events without depending on the transport.

```php
use Storm\Message\ContextValues;
use Storm\Message\CurrentMessageContext;

$context = new CurrentMessageContext();
$context->bind(new ContextValues(correlationId: $cid, causationId: $caid));
// ... handle the message ...
$context->clear();
```

`CurrentStoredHeader` is its sibling for the STORED header of the message being handled: a bus
handler receives only the message object, so the parts not on the event itself — `occurred_at`
above all — are exposed ambiently there, bound by the consuming middleware.

### The declared bag

Beyond the fixed identifiers, the context carries a **declared transverse bag**: opaque
key/value metadata that propagates along the causal chain exactly like correlation does, and
that the framework never reads. Which keys propagate is DECLARED wiring
(`storm.context.propagated_keys`); an undeclared header stays a one-message annotation.

```php
$context->bind(new ContextValues(
    correlationId: $cid,
    bag: ['origin' => 'http', 'trace' => $traceparent],
));
// every event recorded downstream now carries `origin` and `trace` as headers,
// and — once declared — the outbox, the saga hop and the neutral wire re-stamp them
```

Two rules keep the bag honest: **domain data never belongs in it** (a downstream fact or
decision that branches on a value makes it payload, versioned), and **personal data is
forbidden** (headers are not covered by crypto-shredding). The `__` prefix stays reserved.

## Enrichers

Enrichers fill in headers before a message is appended to the event store — the write path of the
aggregate repository is their single consumer. Dispatch does not run them: the bus carries context
as Messenger stamps, and the saga outbox seals its own headers. Each is small, single-purpose and
idempotent (it never overwrites an existing header):

- `MessageIdEnricher` — assigns the message id (`MetaIdentityGenerator`, UUID v7 by default)
- `MessageTypeEnricher` — records the message type, FQCN by default (`Header::MessageType`)
- `OccurredAtEnricher` — stamps the message datetime from the `Clock`
- `ContextEnricher` — copies correlation/causation/actor/tenant from `MessageContext`

`EnricherRegistry` applies a chain of enrichers in order and is itself a
`MessageEnricher`:

```php
use Storm\Message\EnricherRegistry;
use Storm\Message\Enricher\MessageIdEnricher;
use Storm\Message\Enricher\MessageTypeEnricher;

$registry = new EnricherRegistry([
    new MessageIdEnricher($identityGenerator),
    new MessageTypeEnricher(),
]);

$message = $registry->enrich($message);
```

## Time

`Header::OccurredAt` is the message's own datetime, set from the Storm `Clock` (UTC,
microsecond precision, freezable in tests). It is distinct from the event store's
`recorded_at`, which the database generates at append time.

## Failures

All bugs by classification, none contracted: `InvalidEventType` (a malformed `#[EventType]`
declaration), `InvalidMessageException` (a malformed envelope or header write),
`InvalidPersonalDeclaration` (an inconsistent `#[Personal]`), `UnbalancedContextFrame` (an ambient
bind/clear mismatch). They surface at boot or first use, loud — you fix the declaration, you do
not catch them.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
