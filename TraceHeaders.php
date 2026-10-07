<?php

declare(strict_types=1);

namespace Storm\Message;

/**
 * Bounds the dedicated W3C carrier before SDK validation; generic bag propagation does not own it.
 */
final class TraceHeaders
{
    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    public static function read(array $headers): array
    {
        $carrier = [];
        foreach (['traceparent', 'tracestate'] as $key) {
            $value = $headers[$key] ?? null;
            if (is_string($value) && $value !== '' && strlen($value) <= 512) {
                $carrier[$key] = $value;
            }
        }

        return $carrier;
    }
}
