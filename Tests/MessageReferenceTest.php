<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;

final class MessageReferenceTest extends TestCase
{
    public function test_constructor_freezes_referenced_identity_before_later_mutation(): void
    {
        $actor = 'alice';
        $headers = [Header::ActorId->value => &$actor, Header::ActorType->value => 'user'];
        $message = new Message(new stdClass, $headers);
        $enriched = $message->withHeader(Header::MessageId, 'event-original');
        $actor = 'mallory';
        self::assertSame('alice', $message->actorId());
        self::assertSame('alice', $enriched->actorId());
    }

    public function test_constructor_freezes_referenced_custom_arrays(): void
    {
        $leaf = 'validated';
        $tree = ['leaf' => &$leaf];
        $message = new Message(new stdClass, ['meta' => &$tree]);
        $leaf = new stdClass;
        $tree['new'] = true;
        self::assertSame(['leaf' => 'validated'], $message->header('meta'));
    }

    public function test_with_header_freezes_nested_references_before_later_mutation(): void
    {
        $leaf = 'validated';
        $tree = ['items' => [&$leaf, &$leaf]];
        $original = new Message(new stdClass);
        $message = $original->withHeader('meta', $tree);
        $leaf = new stdClass;
        self::assertSame(['items' => ['validated', 'validated']], $message->header('meta'));
        self::assertSame([], $original->headers());
    }

    public function test_returned_header_array_cannot_mutate_the_envelope(): void
    {
        $id = 'event-original';
        $leaf = 'validated';
        $message = new Message(new stdClass, [Header::MessageId->value => &$id, 'meta' => ['leaf' => &$leaf]]);
        $export = $message->headers();
        $export[Header::MessageId->value] = 'event-overwritten';
        self::assertIsArray($export['meta']);
        $export['meta']['leaf'] = 'overwritten';
        $value = $message->header('meta');
        $value['leaf'] = 'overwritten-again';
        self::assertSame('event-original', $message->messageId());
        self::assertSame(['leaf' => 'validated'], $message->header('meta'));
        self::assertSame('validated', $leaf);
    }

    public function test_from_stored_detaches_references_without_rejecting_unknown_reserved_keys(): void
    {
        $id = 'stored-id';
        $leaf = 'historical';
        $headers = [Header::MessageId->value => &$id, '__future_header' => ['leaf' => &$leaf]];
        $message = Message::fromStored(new stdClass, $headers);
        $copy = $message->withHeader('new', 'value');
        $id = 'changed';
        $leaf = 'changed';
        $export = $message->headers();
        self::assertIsArray($export['__future_header']);
        $export['__future_header']['leaf'] = 'changed-by-reader';
        self::assertSame('stored-id', $message->messageId());
        self::assertSame(['leaf' => 'historical'], $message->header('__future_header'));
        self::assertSame(['leaf' => 'historical'], $copy->header('__future_header'));
    }

    public function test_detachment_preserves_scalar_types_keys_order_and_wrapped_object(): void
    {
        $event = new stdClass;
        $float = 1.0;
        $large = PHP_INT_MAX;
        $headers = ['meta' => ['float' => &$float, 'large' => &$large, 'list' => [true, null, 'é']]];
        $message = new Message($event, $headers);
        self::assertSame(['meta' => ['float' => 1.0, 'large' => PHP_INT_MAX, 'list' => [true, null, 'é']]], $message->headers());
        self::assertSame($event, $message->message());
        $stored = Message::fromStored($event, ['__unknown' => [2 => 'sparse', 7 => 'historic']]);
        self::assertSame([2 => 'sparse', 7 => 'historic'], $stored->header('__unknown'));
    }

    public function test_from_stored_detaches_the_deepest_stored_header(): void
    {
        $leaf = 'historical';
        $tree = ['leaf' => &$leaf];
        for ($i = 1; $i < 510; $i++) {
            $tree = ['child' => $tree];
        }
        $headers = ['__future_header' => $tree];
        $expected = json_decode(json_encode($headers, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $message = Message::fromStored(new stdClass, $headers);
        $leaf = 'changed';
        self::assertSame($expected, $message->headers());
    }

    public function test_from_stored_bounds_array_copying_before_unbounded_recursion(): void
    {
        $tree = 'leaf';
        for ($i = 0; $i < 511; $i++) {
            $tree = ['child' => $tree];
        }
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('array nesting exceeds');
        Message::fromStored(new stdClass, ['__future_header' => $tree]);
    }
}
