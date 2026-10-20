<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Message\ContextValues;
use Storm\Message\Exception\InvalidMessageException;

final class ContextValuesTest extends TestCase
{
    #[Test]
    public function empty_has_all_null_values(): void
    {
        $context = ContextValues::empty();

        $this->assertNull($context->correlationId());
        $this->assertNull($context->causationId());
        $this->assertNull($context->actorId());
        $this->assertNull($context->actorType());
        $this->assertNull($context->tenantId());
        $this->assertSame([], $context->bag());
    }

    #[Test]
    public function exposes_the_declared_bag(): void
    {
        $context = new ContextValues(bag: ['origin' => 'http', 'attempt' => 2]);

        $this->assertSame(['origin' => 'http', 'attempt' => 2], $context->bag());
    }

    #[Test]
    public function a_reserved_bag_key_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);

        new ContextValues(bag: ['__origin' => 'http']);
    }

    #[Test]
    public function a_padded_reserved_bag_key_is_refused_as_padded(): void
    {
        // the blank check runs before the prefix check, so the refusal names the near-collision
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('carries surrounding whitespace');

        new ContextValues(bag: ['__origin ' => 'http']);
    }

    #[Test]
    public function a_blank_bag_key_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);

        new ContextValues(bag: [' ' => 'http']);
    }

    #[Test]
    public function a_padded_bag_key_is_refused_with_the_near_collision_named(): void
    {
        // Message's own refusal, one layer down: a padded key accepted here fails mid-enrichment, far
        // from its producer; the message must name the key's near-collision, not a blank header value
        try {
            new ContextValues(bag: ['x-origin ' => 'http']);
            self::fail('a padded key must be refused at the boundary');
        } catch (InvalidMessageException $e) {
            $this->assertStringContainsString('near-collision', $e->getMessage());
        }
    }

    #[Test]
    public function an_integer_bag_key_is_validated_not_died_on(): void
    {
        // PHP normalizes '7' to int 7 at array write; under strict types an uncast key would fail
        // on trim() with a TypeError the contract never declared
        // @phpstan-ignore argument.type (a hostile value: the guard under test)
        $values = new ContextValues(bag: ['7' => 'http']);

        $this->assertSame(['7' => 'http'], $values->bag());
    }

    #[Test]
    public function exposes_provided_values(): void
    {
        $context = new ContextValues(
            correlationId: 'corr',
            causationId: 'caus',
            actorId: 'actor',
            actorType: 'user',
            tenantId: 'tenant',
        );

        $this->assertSame('corr', $context->correlationId());
        $this->assertSame('caus', $context->causationId());
        $this->assertSame('actor', $context->actorId());
        $this->assertSame('user', $context->actorType());
        $this->assertSame('tenant', $context->tenantId());
    }
}
