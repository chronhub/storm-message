<?php

declare(strict_types=1);

namespace Storm\Message;

use Storm\Contracts\Message\MetaIdentityGenerator;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * The UUID v7 implementation of MetaIdentityGenerator.
 */
#[AsAlias(MetaIdentityGenerator::class)]
final class UuidV7MetaIdentityGenerator implements MetaIdentityGenerator
{
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
