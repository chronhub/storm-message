<?php

declare(strict_types=1);

namespace Storm\Message\Enricher;

use Storm\Contracts\Message\MessageContext;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\MessageEnricher;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Propagates the ambient cross-cutting context onto the message.
 *
 * Copies correlation, causation, actor, and tenant identifiers, plus the declared transverse bag,
 * from the current MessageContext into the corresponding headers. This is how events recorded by
 * an aggregate inherit the context of the command that produced them, without the domain or the
 * handler ever touching the transport.
 *
 * Each header is only set when the context has a value and the message does not already carry
 * it, so an explicitly stamped header always wins, and enriching twice is safe.
 *
 * The actor is an ATOMIC PAIR, never two independent headers:
 *
 * - A message carrying both halves keeps them together;
 *
 * - A message carrying neither inherits both ambient halves together;
 *
 * - A message carrying exactly one half is refused loud, since completing it from the ambient
 *   context would forge a composite identity whose two halves come from different provenances, an
 *   actor that never existed.
 *
 * The ambient side is a pair by construction: it is read from the `Actor` value object, which
 * refuses blanks, so a half-empty ambient context copies nothing.
 */
#[AutoconfigureTag('storm.message_enricher', ['priority' => 50])]
final readonly class ContextEnricher implements MessageEnricher
{
    public function __construct(
        private MessageContext $context,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws InvalidMessageException when the message carries exactly one half of the actor
     *                                 identity; an atomic pair is both or neither, and completing
     *                                 a lone half from the ambient context would forge a
     *                                 two-provenance identity
     */
    public function enrich(Message $message): Message
    {
        /** @var list<array{Header, string|null}> $singles */
        $singles = [
            [Header::CorrelationId, $this->context->correlationId()],
            [Header::CausationId, $this->context->causationId()],
            [Header::TenantId, $this->context->tenantId()],
        ];

        foreach ($singles as [$header, $value]) {
            if ($value !== null && ! $message->hasHeader($header)) {
                $message = $message->withHeader($header, $value);
            }
        }

        // The declared transverse bag rides along under the same idempotence: an existing header
        // wins, so a replayed or republished message keeps its original truth. Keys were validated
        // at ContextValues construction; the framework never reads the values.
        foreach ($this->context->bag() as $key => $value) {
            if (! $message->hasHeader($key)) {
                $message = $message->withHeader($key, $value);
            }
        }

        return $this->enrichActorPair($message);
    }

    /**
     * @throws InvalidMessageException when the message carries exactly one actor half
     */
    private function enrichActorPair(Message $message): Message
    {
        $hasId = $message->hasHeader(Header::ActorId);
        $hasType = $message->hasHeader(Header::ActorType);

        if ($hasId !== $hasType) {
            throw InvalidMessageException::halfActorIdentity(
                ($hasId ? Header::ActorId : Header::ActorType)->key(),
                ($hasId ? Header::ActorType : Header::ActorId)->key(),
            );
        }

        if ($hasId) {
            return $message; // the explicit pair wins, together
        }

        $actorId = $this->context->actorId();
        $actorType = $this->context->actorType();

        if ($actorId === null || $actorType === null) {
            return $message; // no complete ambient pair, copy nothing, never a half
        }

        return $message
            ->withHeader(Header::ActorId, $actorId)
            ->withHeader(Header::ActorType, $actorType);
    }
}
