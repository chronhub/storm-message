<?php

declare(strict_types=1);

namespace Storm\Message\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\HasConstructablePayload;

/**
 * Exercises the payload-backed event pattern: the trait owns the payload + codec, a static factory
 * builds the payload from typed args, a PHP 8.4 virtual hook reconstructs a typed value on read,
 * and `aggregateId()` reads the id from the payload.
 */
final class PayloadEvent implements DomainEvent
{
    use HasConstructablePayload;

    public int $amount {
        get => (int) $this->payload['amount'];
    }

    public function aggregateId(): string
    {
        return (string) $this->payload['accountId'];
    }

    public static function record(string $accountId, int $amount): self
    {
        return new self(['accountId' => $accountId, 'amount' => $amount]);
    }
}
