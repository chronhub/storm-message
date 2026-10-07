<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Message\TraceHeaders;

final class TraceHeadersTest extends TestCase
{
    #[Test]
    public function reads_a_carrier_value_of_exactly_512_bytes(): void
    {
        $value = str_repeat('a', 512);

        $this->assertSame(['traceparent' => $value], TraceHeaders::read(['traceparent' => $value]));
    }

    #[Test]
    public function drops_a_carrier_value_beyond_512_bytes(): void
    {
        $this->assertSame([], TraceHeaders::read(['tracestate' => str_repeat('a', 513)]));
    }
}
