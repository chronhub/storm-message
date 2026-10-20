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
 * It copies the correlation, causation, actor and tenant identifiers, plus the declared transverse
 * bag, from the current `MessageContext` into the matching headers. Events recorded by an aggregate
 * thus inherit the context of the command that produced them, without the domain or the handler
 * touching the transport.
 *
 * A header is set only when the context has a value for it.
 *
 * The actor's two headers form an atomic pair:
 *
 * - A message carrying both halves keeps them together
 *
 * - A message carrying neither inherits both ambient halves together
 *
 * - A message carrying exactly one half is refused: completing it from the ambient context would
 *   forge a composite identity whose halves come from different provenances, an actor that never
 *   existed
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

        // the bag follows the same rule, an existing header wins, so a replayed or republished message
        // keeps its values; ContextValues validated the keys, and the framework never reads the values
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
