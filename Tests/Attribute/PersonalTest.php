<?php

declare(strict_types=1);

namespace Storm\Message\Tests\Attribute;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Message\Attribute\Personal;
use Storm\Message\Exception\InvalidPersonalDeclaration;

final class PersonalTest extends TestCase
{
    #[Test]
    public function declares_subject_keys_and_fallbacks(): void
    {
        $personal = new Personal('customer_id', ['full_name', 'email'], ['full_name' => '⌫', 'email' => null]);

        $this->assertSame('customer_id', $personal->subject);
        $this->assertSame(['full_name', 'email'], $personal->keys);
        $this->assertSame(['full_name' => '⌫', 'email' => null], $personal->fallback);
    }

    #[Test]
    public function rejects_keys_that_are_not_a_list(): void
    {
        // the sibling of the empty-keys refusal: a keyed array reads as a list to nobody, and the
        // ciphering walks the keys positionally, so a gap or a string key would silently skip one
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('keys must be a non-empty list');

        // @phpstan-ignore argument.type (the guard defends a declaration the analyser cannot see through)
        new Personal('customer_id', [3 => 'full_name'], ['full_name' => null]);
    }

    #[Test]
    public function rejects_a_blank_subject(): void
    {
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('subject must be a non-empty payload key');

        new Personal(' ', ['full_name'], ['full_name' => null]);
    }

    #[Test]
    public function rejects_a_unicode_separator_subject(): void
    {
        // a NBSP-only subject renders exactly as blank in every log and column, and /u alone does
        // not fold Unicode separators into \s; the subject addresses durable ciphered payloads
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('subject must be a non-empty payload key');

        new Personal("\u{00A0}", ['full_name'], ['full_name' => null]);
    }

    #[Test]
    public function rejects_empty_keys(): void
    {
        // marking a class that protects nothing is a declaration bug, not a no-op
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('keys must be a non-empty list');

        new Personal('customer_id', [], []); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }

    #[Test]
    #[DataProvider('blank_keys')]
    public function rejects_a_blank_key(string $key): void
    {
        // Whitespace is the half the subject's own guard already covers, and the keys read the same
        // way: a payload key made of spaces names nothing, and it would reach the cipher as a key
        // that protects no field while looking declared.
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('keys must be non-empty strings');

        new Personal('customer_id', [$key], [$key => null]); // @phpstan-ignore argument.type, argument.type (hostile on purpose: the guard under test)
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blank_keys(): iterable
    {
        yield 'the empty string' => [''];
        yield 'a single space' => [' '];
        yield 'a tab' => ["\t"];
        yield 'a unicode separator' => ["\u{00A0}"];
    }

    #[Test]
    public function rejects_a_duplicate_key(): void
    {
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('keys must be unique');

        new Personal('customer_id', ['email', 'email'], ['email' => null]);
    }

    #[Test]
    public function rejects_the_subject_among_the_encrypted_keys(): void
    {
        // encrypting the subject makes every read unable to locate its own key
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('cannot be among the encrypted keys');

        new Personal('customer_id', ['customer_id', 'email'], ['customer_id' => null, 'email' => null]);
    }

    #[Test]
    public function rejects_a_declared_key_without_its_fallback(): void
    {
        // THE build-breaking guard: the fallback is a design value the domain must choose; a
        // missing one must never default silently
        try {
            new Personal('customer_id', ['full_name', 'email'], ['full_name' => '⌫']);
            $this->fail('a declared key without a fallback must refuse');
        } catch (InvalidPersonalDeclaration $e) {
            $this->assertStringContainsString('email', $e->getMessage());
            $this->assertStringContainsString('fallback', $e->getMessage());
        }
    }

    #[Test]
    public function rejects_an_orphan_fallback_naming_no_declared_key(): void
    {
        // drift in the other direction: a fallback documenting an unprotected key
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('fallback "email" names no declared key');

        new Personal('customer_id', ['full_name'], ['full_name' => '⌫', 'email' => null]);
    }

    #[Test]
    public function rejects_a_non_scalar_fallback(): void
    {
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageIsOrContains('must be scalar or null, got array');

        new Personal('customer_id', ['full_name'], ['full_name' => ['nested']]); // @phpstan-ignore argument.type (hostile on purpose: the guard under test)
    }

    #[Test]
    #[DataProvider('non_finite_fallbacks')]
    public function rejects_a_non_finite_fallback(float $fallback): void
    {
        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessage('fallback "name" must be finite');

        new Personal('subject', ['name'], ['name' => $fallback]);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function non_finite_fallbacks(): iterable
    {
        yield 'not a number' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    #[Test]
    public function preserves_finite_fallbacks_without_coercion(): void
    {
        foreach ([0.0, -0.0, PHP_FLOAT_MAX, -PHP_FLOAT_MAX, PHP_FLOAT_MIN, 1.5, PHP_INT_MAX, false, '', 'forgotten', null] as $fallback) {
            $personal = new Personal('subject', ['name'], ['name' => $fallback]);

            self::assertSame($fallback, $personal->fallback['name']);
            self::assertSame($fallback, json_decode(json_encode($personal->fallback['name'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function a_null_fallback_is_a_legitimate_design_choice(): void
    {
        $personal = new Personal('customer_id', ['email'], ['email' => null]);

        $this->assertNull($personal->fallback['email']);
    }
}
