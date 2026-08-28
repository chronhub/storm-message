<?php

declare(strict_types=1);

namespace Storm\Message\Tests\Enricher;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Message\ContextValues;
use Storm\Message\Enricher\ContextEnricher;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;

final class ContextEnricherTest extends TestCase
{
    #[Test]
    public function copies_context_into_headers(): void
    {
        $context = new ContextValues(
            correlationId: 'corr',
            causationId: 'caus',
            actorId: 'actor',
            actorType: 'user',
            tenantId: 'tenant',
        );

        $message = new ContextEnricher($context)->enrich(new Message(new stdClass));

        $this->assertSame('corr', $message->correlationId());
        $this->assertSame('caus', $message->causationId());
        $this->assertSame('actor', $message->actorId());
        $this->assertSame('user', $message->actorType());
        $this->assertSame('tenant', $message->tenantId());
    }

    #[Test]
    public function does_not_overwrite_existing_headers(): void
    {
        $context = new ContextValues(correlationId: 'corr');

        $message = new ContextEnricher($context)->enrich(
            new Message(new stdClass)->withHeader(Header::CorrelationId, 'pre'),
        );

        $this->assertSame('pre', $message->correlationId());
    }

    #[Test]
    public function ignores_null_context_values(): void
    {
        $message = new ContextEnricher(ContextValues::empty())->enrich(new Message(new stdClass));

        $this->assertSame([], $message->headers());
    }

    #[Test]
    public function copies_the_declared_bag_into_headers(): void
    {
        $context = new ContextValues(bag: ['origin' => 'http', 'attempt' => 2]);

        $message = new ContextEnricher($context)->enrich(new Message(new stdClass));

        $this->assertSame('http', $message->headers()['origin']);
        $this->assertSame(2, $message->headers()['attempt']);
    }

    #[Test]
    public function an_existing_header_wins_over_the_bag(): void
    {
        $context = new ContextValues(bag: ['origin' => 'ambient']);

        $message = new ContextEnricher($context)->enrich(
            new Message(new stdClass)->withHeader('origin', 'explicit'),
        );

        $this->assertSame('explicit', $message->headers()['origin']);
    }

    #[Test]
    public function an_explicit_actor_pair_is_preserved_together(): void
    {
        // the message's own pair wins as a unit; no half is completed from the ambient context
        $context = new ContextValues(actorId: 'ambient-actor', actorType: 'service');

        $message = new ContextEnricher($context)->enrich(
            new Message(new stdClass)
                ->withHeader(Header::ActorId, 'explicit-actor')
                ->withHeader(Header::ActorType, 'user'),
        );

        $this->assertSame('explicit-actor', $message->actorId());
        $this->assertSame('user', $message->actorType());
    }

    #[Test]
    #[Group('adversarial')]
    #[DataProvider('lone_actor_halves')]
    public function a_message_carrying_half_an_actor_is_refused(Header $present, string $missingKey): void
    {
        // Completing a lone half from the ambient context would forge a two-provenance identity, an
        // actor that never existed anywhere; the half fails loud instead. Both orientations are run,
        // and the refusal names which half it HAS and which it wants: the operator reading it has
        // one header to go add, and a message that swapped them sends them to the wrong one.
        $context = new ContextValues(actorId: 'ambient-actor', actorType: 'service');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains(sprintf('"%s" is present but "%s" is missing', $present->key(), $missingKey));

        new ContextEnricher($context)->enrich(
            new Message(new stdClass)->withHeader($present, 'explicit-half'),
        );
    }

    /**
     * @return iterable<string, array{Header, string}>
     */
    public static function lone_actor_halves(): iterable
    {
        yield 'the id without its type' => [Header::ActorId, Header::ActorType->key()];
        yield 'the type without its id' => [Header::ActorType, Header::ActorId->key()];
    }

    #[Test]
    public function a_half_ambient_actor_copies_nothing(): void
    {
        // the ambient side is a pair by construction; a defensive half-empty context must not
        // propagate a lone half downstream
        $context = new ContextValues(actorId: 'ambient-actor');

        $message = new ContextEnricher($context)->enrich(new Message(new stdClass));

        $this->assertNull($message->actorId());
        $this->assertNull($message->actorType());
    }
}
