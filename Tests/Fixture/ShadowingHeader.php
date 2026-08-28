<?php

declare(strict_types=1);

namespace Storm\Message\Tests\Fixture;

use Storm\Contracts\Message\HeaderKey;

/**
 * A hostile application header key shadowing a reserved framework key, exercising the provenance
 * guard: implementing HeaderKey does not grant access to the `__` namespace, only the framework's
 * own Header enum writes there.
 */
enum ShadowingHeader: string implements HeaderKey
{
    case Correlation = '__correlation_id';

    public function key(): string
    {
        return $this->value;
    }
}
