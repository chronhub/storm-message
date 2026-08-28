<?php

declare(strict_types=1);

namespace Storm\Message\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Message\UuidV7MetaIdentityGenerator;

final class UuidV7MetaIdentityGeneratorTest extends TestCase
{
    #[Test]
    public function generates_a_uuid_v7(): void
    {
        $id = (new UuidV7MetaIdentityGenerator)->generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    #[Test]
    public function generates_unique_values(): void
    {
        $generator = new UuidV7MetaIdentityGenerator;

        $this->assertNotSame($generator->generate(), $generator->generate());
    }
}
