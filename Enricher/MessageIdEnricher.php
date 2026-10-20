<?php

declare(strict_types=1);

namespace Storm\Message\Enricher;

use Storm\Contracts\Message\MetaIdentityGenerator;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\MessageEnricher;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Assigns a unique message id to `Header::MessageId` when the message has none.
 */
#[AutoconfigureTag('storm.message_enricher', ['priority' => 200])]
final readonly class MessageIdEnricher implements MessageEnricher
{
    public function __construct(
        private MetaIdentityGenerator $identity,
    ) {}

    public function enrich(Message $message): Message
    {
        if ($message->hasHeader(Header::MessageId)) {
            return $message;
        }

        return $message->withHeader(Header::MessageId, $this->identity->generate());
    }
}
