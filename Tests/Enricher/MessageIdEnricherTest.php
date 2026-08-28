<?php

declare(strict_types=1);

namespace Storm\Message\Tests\Enricher;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Contracts\Message\MetaIdentityGenerator;
use Storm\Message\Enricher\MessageIdEnricher;
use Storm\Message\Header;
use Storm\Message\Message;

final class MessageIdEnricherTest extends TestCase
{
    #[Test]
    public function sets_message_id_when_absent(): void
    {
        $enricher = new MessageIdEnricher($this->identityReturning('fixed-id'));

        $message = $enricher->enrich(new Message(new stdClass));

        $this->assertSame('fixed-id', $message->messageId());
    }

    #[Test]
    public function keeps_existing_message_id(): void
    {
        $enricher = new MessageIdEnricher($this->identityReturning('fixed-id'));

        $message = $enricher->enrich(
            new Message(new stdClass)->withHeader(Header::MessageId, 'existing'),
        );

        $this->assertSame('existing', $message->messageId());
    }

    private function identityReturning(string $id): MetaIdentityGenerator
    {
        return new readonly class($id) implements MetaIdentityGenerator
        {
            public function __construct(private string $id) {}

            public function generate(): string
            {
                return $this->id;
            }
        };
    }
}
