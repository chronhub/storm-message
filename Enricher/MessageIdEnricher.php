<?php

declare(strict_types=1);

namespace Storm\Message\Enricher;

use Storm\Contracts\Message\MetaIdentityGenerator;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\MessageEnricher;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Assigns a unique message id to Header::MessageId when the message has none.
 *
 * Idempotent: an already-identified message is returned untouched, so enriching a message more
 * than once never changes its id.
 */
#[AutoconfigureTag('storm.message_enricher', ['priority' => 200])]
final readonly class MessageIdEnricher implements MessageEnricher
{
    public function __construct(
        private MetaIdentityGenerator $identity,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws InvalidMessageException propagated from `Message::withHeader()`, which refuses a blank
     *                                 framework header; reachable only when the identity generator
     *                                 breaks its contract and hands back an empty id
     */
    public function enrich(Message $message): Message
    {
        if ($message->hasHeader(Header::MessageId)) {
            return $message;
        }

        return $message->withHeader(Header::MessageId, $this->identity->generate());
    }
}
