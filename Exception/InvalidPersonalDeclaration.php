<?php

declare(strict_types=1);

namespace Storm\Message\Exception;

use LogicException;

/**
 * An invalid `#[Personal]` declaration: a bad shape, or a class that is not a domain event.
 *
 * A declaration bug, so a `LogicException`. The attribute's own constructor throws it for a shape,
 * and the compile-time scan, which instantiates that constructor, throws it for a carrier, so it
 * fails at container build, the earliest point, instead of writing rows whose personal fields are
 * unprotected, or unrecoverable once their subject's key is destroyed.
 */
final class InvalidPersonalDeclaration extends LogicException
{
    public static function emptySubject(): self
    {
        return new self('A #[Personal] subject must be a non-empty payload key — it locates the subject\'s cipher key.');
    }

    public static function keysNotAList(string $subject): self
    {
        return new self(sprintf(
            'The #[Personal(subject: "%s")] keys must be a non-empty list of payload keys to encrypt.',
            $subject,
        ));
    }

    public static function blankKey(string $subject): self
    {
        return new self(sprintf(
            'The #[Personal(subject: "%s")] keys must be non-empty strings — a blank key protects nothing.',
            $subject,
        ));
    }

    public static function duplicateKey(string $subject): self
    {
        return new self(sprintf(
            'The #[Personal(subject: "%s")] keys must be unique — a duplicate is a declaration typo, not a second protection.',
            $subject,
        ));
    }

    public static function subjectAmongKeys(string $subject): self
    {
        return new self(sprintf(
            'The #[Personal] subject key "%s" cannot be among the encrypted keys — it locates the cipher key, so encrypting it makes every read unable to find it.',
            $subject,
        ));
    }

    public static function missingFallback(string $subject, string $key): self
    {
        return new self(sprintf(
            'The #[Personal(subject: "%s")] key "%s" has no fallback — the fallback is what a reader sees once the subject is forgotten, a design value the domain must choose.',
            $subject,
            $key,
        ));
    }

    public static function orphanFallback(string $subject, string $key): self
    {
        return new self(sprintf(
            'The #[Personal(subject: "%s")] fallback "%s" names no declared key — declaration drift: it documents a key that is not protected.',
            $subject,
            $key,
        ));
    }

    public static function nonScalarFallback(string $subject, string $key, string $type): self
    {
        return new self(sprintf(
            'The #[Personal(subject: "%s")] fallback "%s" must be scalar or null, got %s — a fallback is a rendered value, not a structure.',
            $subject,
            $key,
            $type,
        ));
    }

    public static function notADomainEvent(string $class): self
    {
        return new self(sprintf(
            '%s declares #[Personal] but is not a domain event — only an event row stores the alias its envelopes bind, so personal data lives on domain events alone.',
            $class,
        ));
    }

    public static function nonFiniteFallback(string $subject, string $key): self
    {
        return new self(sprintf(
            'The #[Personal(subject: "%s")] fallback "%s" must be finite so the payload remains JSON encodable after forgetting.',
            $subject,
            $key,
        ));
    }
}
