<?php

declare(strict_types=1);

namespace Storm\Message\Enricher;

use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\MessageEnricher;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Records the message type in Header::MessageType when absent.
 *
 * Defaults to the wrapped message's fully qualified class name, the in-process hydration type. The
 * durable, portable type is a different concept: the EventTypeMapper alias, which the storage and
 * wire codecs externalize into their `type` column or field, stripping this header on the writer
 * and re-injecting the FQCN on read. Through those codecs the FQCN never reaches a durable surface;
 * the saga outbox is the one deliberate exception, persisting the FQCN only for its short-lived
 * rows.
 *
 * Idempotent: a message that already declares a type is left untouched, which is what lets an
 * upcasted or aliased type survive re-enrichment.
 */
#[AutoconfigureTag('storm.message_enricher', ['priority' => 100])]
final readonly class MessageTypeEnricher implements MessageEnricher
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidMessageException propagated from `Message::withHeader()`, which refuses a blank
     *                                 framework header; unreachable here, since a class name is
     *                                 always a non-blank string
     */
    public function enrich(Message $message): Message
    {
        if ($message->hasHeader(Header::MessageType)) {
            return $message;
        }

        return $message->withHeader(Header::MessageType, $message->message()::class);
    }
}
