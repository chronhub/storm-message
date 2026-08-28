<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use Generator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Storm\Contracts\Message\MetaIdentityGenerator;
use Storm\Message\Enricher\MessageIdEnricher;
use Storm\Message\Enricher\MessageTypeEnricher;
use Storm\Message\EnricherRegistry;
use Storm\Message\Message;

final class EnricherRegistryTest extends TestCase
{
    #[Test]
    public function applies_each_enricher(): void
    {
        $registry = new EnricherRegistry([
            new MessageIdEnricher($this->identityReturning('evt')),
            new MessageTypeEnricher,
        ]);

        $message = $registry->enrich(new Message(new stdClass));

        $this->assertSame('evt', $message->messageId());
        $this->assertSame(stdClass::class, $message->messageType());
    }

    #[Test]
    public function empty_registry_returns_message_unchanged(): void
    {
        $message = new Message(new stdClass);

        $this->assertSame($message, (new EnricherRegistry)->enrich($message));
    }

    #[Test]
    public function accepts_a_traversable_of_enrichers(): void
    {
        $registry = new EnricherRegistry((static function () {
            yield new MessageTypeEnricher;
        })());

        $message = $registry->enrich(new Message(new stdClass));

        $this->assertSame(stdClass::class, $message->messageType());
    }

    #[Test]
    public function keeps_every_enricher_of_a_traversable_whose_keys_collide(): void
    {
        // The chain applies each enricher in turn, so it must not inherit the iterable's key space.
        // A Traversable is free to yield twice under one key, and collapsing on that key drops an
        // enricher silently: the message would leave the chain missing a stamp, with nothing failing.
        $registry = new EnricherRegistry((function (): Generator {
            yield 'enricher' => new MessageIdEnricher($this->identityReturning('evt'));
            yield 'enricher' => new MessageTypeEnricher;
        })());

        $message = $registry->enrich(new Message(new stdClass));

        $this->assertSame('evt', $message->messageId());
        $this->assertSame(stdClass::class, $message->messageType());
    }

    #[Test]
    public function normalizes_array_keys_to_a_list(): void
    {
        $enricher1 = new MessageIdEnricher($this->identityReturning('evt'));
        $enricher2 = new MessageTypeEnricher;

        $registry = new EnricherRegistry([
            'first' => $enricher1,
            'second' => $enricher2,
        ]);

        $reflection = new ReflectionProperty(EnricherRegistry::class, 'enrichers');
        $enrichers = $reflection->getValue($registry);

        $this->assertSame([$enricher1, $enricher2], $enrichers);
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
