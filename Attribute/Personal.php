<?php

declare(strict_types=1);

namespace Storm\Message\Attribute;

use Attribute;
use Storm\Message\EventType;
use Storm\Message\Exception\InvalidPersonalDeclaration;

/**
 * Declares which payload keys of an event carry personal data, and whose they are.
 *
 * This is the marking half of crypto-shredding: those keys are encrypted at rest with a key per
 * subject, and forgetting the subject destroys the key, so the stored history stays intact while
 * its personal fields become unreadable and render their declared fallback instead.
 *
 * The declaration speaks PAYLOAD keys, never properties: the wire is explicit-only, so what
 * `toPayload()` emits is the only shape that exists at rest, and the ciphering serializer transforms
 * exactly the keys named here. `subject` names the payload key holding the subject's OPAQUE id; it
 * stays in clear since it locates the key, and it must never itself be personal data. Every entry of
 * `keys` must have its `fallback` value: what a reader sees once the key is destroyed is a DESIGN
 * value the domain chooses, never an accidental null.
 *
 * The shape invariants are enforced in the constructor, the `EventType` pattern: the compile-time
 * scan instantiates the attribute, so a degenerate declaration explodes at container build, before
 * any row is written with unprotected or unrecoverable fields.
 *
 * Two design rules follow from the marking and are enforced by doctrine, not code: a personal key is
 * never a SQL query criterion, since an encrypted value is neither indexable nor comparable and
 * needing that is the sign an opaque id is missing; and headers never carry personal data at all.
 *
 * @see EventType the sibling class-target attribute and the enforcement pattern
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Personal
{
    /**
     * @param  non-empty-string  $subject  the payload key holding the subject's opaque id, kept in clear
     * @param  non-empty-list<non-empty-string>  $keys  the payload keys to encrypt per subject
     * @param  array<non-empty-string, scalar|null>  $fallback  per encrypted key, the value rendered
     *                                                          once the subject's key is destroyed; every
     *                                                          entry of `$keys` must have one
     *
     * @throws InvalidPersonalDeclaration when the subject key is blank or listed in `$keys`, when
     *                                    `$keys` is empty or not a list of unique non-blank strings,
     *                                    when a declared key has no `$fallback` entry, or when a
     *                                    `$fallback` entry names an undeclared key or is not
     *                                    scalar|null
     */
    public function __construct(
        public string $subject,
        public array $keys,
        public array $fallback,
    ) {
        // phpstan deems several of these dead under the very phpdoc types they defend.
        if (self::isBlank($this->subject)) {
            throw InvalidPersonalDeclaration::emptySubject();
        }

        if ($this->keys === []) { // @phpstan-ignore identical.alwaysFalse
            throw InvalidPersonalDeclaration::keysNotAList($this->subject);
        }

        if (! array_is_list($this->keys)) { // @phpstan-ignore function.alreadyNarrowedType
            throw InvalidPersonalDeclaration::keysNotAList($this->subject);
        }

        foreach ($this->keys as $key) {
            if (! is_string($key) || self::isBlank($key)) { // @phpstan-ignore function.alreadyNarrowedType
                throw InvalidPersonalDeclaration::blankKey($this->subject);
            }
        }

        if (count($this->keys) !== count(array_unique($this->keys))) {
            throw InvalidPersonalDeclaration::duplicateKey($this->subject);
        }

        if (in_array($this->subject, $this->keys, true)) {
            // the subject id locates the key, so it cannot be encrypted with it; declaring it
            // personal would make every read of the subject's own events unable to find their key
            throw InvalidPersonalDeclaration::subjectAmongKeys($this->subject);
        }

        foreach ($this->keys as $key) {
            if (! array_key_exists($key, $this->fallback)) {
                throw InvalidPersonalDeclaration::missingFallback($this->subject, $key);
            }
        }

        foreach ($this->fallback as $key => $value) {
            if (! in_array($key, $this->keys, true)) {
                // an orphan fallback is declaration drift: it documents a key that is not protected
                throw InvalidPersonalDeclaration::orphanFallback($this->subject, (string) $key);
            }

            if ($value !== null && ! is_scalar($value)) { // @phpstan-ignore booleanAnd.alwaysFalse, function.alreadyNarrowedType
                throw InvalidPersonalDeclaration::nonScalarFallback($this->subject, (string) $key, get_debug_type($value));
            }
        }
    }

    // Blank to every renderer: empty, ASCII whitespace, or Unicode separators, which /u alone does
    // not fold into \s; the subject and keys address durable ciphered payloads, so they get the
    // same blank test the header values carry
    private static function isBlank(string $value): bool
    {
        return preg_match('/^[\s\p{Z}]*$/u', $value) === 1;
    }
}
