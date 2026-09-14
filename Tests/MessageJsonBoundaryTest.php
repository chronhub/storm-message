<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Message;

final class MessageJsonBoundaryTest extends TestCase
{
    #[DataProvider('invalidInputs')]
    public function test_write_gate_refuses_a_value_the_json_codec_cannot_encode(string $door, string $case): void
    {
        $resource = fopen('php://temp', 'w+');
        self::assertIsResource($resource);
        try {
            if ($case === 'closed-resource') {
                fclose($resource);
            }
            $key = $case === 'invalid-header-key' ? "a\xFFb" : 'meta';
            $value = match ($case) {
                'resource', 'closed-resource' => ['value' => $resource],
                'invalid-value' => "a\xFFb",
                'invalid-nested-key' => ["a\xFFb" => 'value'],
                default => 'value',
            };
            $this->expectException(InvalidMessageException::class);
            $this->expectExceptionMessageIsOrContains(match ($case) {
                'resource', 'closed-resource' => 'only JSON scalars, null and arrays are accepted',
                'invalid-value' => 'a string is not valid UTF-8',
                'invalid-nested-key' => 'an array key is not valid UTF-8',
                'invalid-header-key' => 'the header key is not valid UTF-8',
                default => self::fail('Unknown invalid header fixture.'),
            });
            $door === 'constructor'
                ? new Message(new stdClass, [$key => $value])
                : new Message(new stdClass)->withHeader($key, $value);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInputs(): iterable
    {
        foreach (['constructor', 'withHeader'] as $door) {
            foreach (['resource', 'closed-resource', 'invalid-value', 'invalid-nested-key', 'invalid-header-key'] as $case) {
                yield $door.'-'.$case => [$door, $case];
            }
        }
    }

    #[DataProvider('doors')]
    public function test_deepest_accepted_header_round_trips_inside_the_wire_envelope(string $door): void
    {
        $value = ['label' => 'é', 'items' => [1, true, null, 1.25]];
        for ($i = 0; $i < 507; $i++) {
            $value = ['child' => $value];
        }
        $message = $door === 'constructor'
            ? new Message(new stdClass, ['meta' => $value])
            : new Message(new stdClass)->withHeader('meta', $value);
        $wire = ['header' => $message->headers(), 'content' => []];
        self::assertSame($wire, json_decode(json_encode($wire, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('doors')]
    public function test_write_gate_refuses_depth_that_cannot_round_trip_in_the_wire_envelope(string $door): void
    {
        $value = 'leaf';
        for ($i = 0; $i < 510; $i++) {
            $value = ['child' => $value];
        }
        $wire = ['header' => ['meta' => $value], 'content' => []];
        $encoding = json_encode($wire, JSON_THROW_ON_ERROR);
        try {
            json_decode($encoding, true, flags: JSON_THROW_ON_ERROR);
            self::fail('The default wire decoder must reject this nesting depth.');
        } catch (JsonException $error) {
            self::assertSame(JSON_ERROR_DEPTH, $error->getCode());
        }
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessageIsOrContains('array nesting exceeds');
        $door === 'constructor'
            ? new Message(new stdClass, ['meta' => $value])
            : new Message(new stdClass)->withHeader('meta', $value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function doors(): iterable
    {
        yield 'constructor' => ['constructor'];
        yield 'withHeader' => ['withHeader'];
    }
}
