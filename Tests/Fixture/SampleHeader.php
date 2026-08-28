<?php

declare(strict_types=1);

namespace Storm\Message\Tests\Fixture;

use Storm\Contracts\Message\HeaderKey;

/**
 * Application-defined header key, used to exercise HeaderKey extensibility.
 */
enum SampleHeader: string implements HeaderKey
{
    case Channel = 'channel';

    public function key(): string
    {
        return $this->value;
    }
}
