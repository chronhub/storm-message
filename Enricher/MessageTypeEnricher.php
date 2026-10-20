<?php

declare(strict_types=1);

namespace Storm\Message\Enricher;

use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\MessageEnricher;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Records the message type in `Header::MessageType` when absent.
 *
 * The default is the wrapped message's fully qualified class name, the in-process hydration type.
 * The durable, portable type is the `EventTypeMapper` alias: the storage and wire codecs write it
 * into their `type` column or field, strip this header on write and restore the FQCN on read, so the
 * FQCN never reaches a durable surface through them. The saga outbox is the only exception, and
 * persists the FQCN for its short-lived rows alone.
 *
 * A type the message already declares survives re-enrichment, so an upcasted or aliased type is kept.
 */
#[AutoconfigureTag('storm.message_enricher', ['priority' => 100])]
final readonly class MessageTypeEnricher implements MessageEnricher
{
    public function enrich(Message $message): Message
    {
        if ($message->hasHeader(Header::MessageType)) {
            return $message;
        }

        return $message->withHeader(Header::MessageType, $message->message()::class);
    }
}
