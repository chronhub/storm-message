<?php

declare(strict_types=1);

namespace Storm\Message\Tests\Enricher;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Message\Enricher\MessageTypeEnricher;
use Storm\Message\Header;
use Storm\Message\Message;

final class MessageTypeEnricherTest extends TestCase
{
    #[Test]
    public function sets_message_type_to_the_fqcn(): void
    {
        $message = (new MessageTypeEnricher)->enrich(new Message(new stdClass));

        $this->assertSame(stdClass::class, $message->messageType());
    }

    #[Test]
    public function keeps_existing_message_type(): void
    {
        $message = (new MessageTypeEnricher)->enrich(
            new Message(new stdClass)->withHeader(Header::MessageType, 'order.placed'),
        );

        $this->assertSame('order.placed', $message->messageType());
    }
}
