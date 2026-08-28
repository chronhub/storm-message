<?php

declare(strict_types=1);

namespace Storm\Message;

use Storm\Contracts\Message\MessageContext;
use Storm\Message\Exception\InvalidMessageException;

/**
 * The immutable value-object form of MessageContext: a frozen set of identifiers.
 *
 * The Story bridge middleware builds one from the incoming Messenger stamps and binds it into the
 * ambient CurrentMessageContext. Being a plain value, it is trivial to construct in tests.
 *
 * @see CurrentMessageContext
 */
final readonly class ContextValues implements MessageContext
{
    /**
     * @param  array<string, bool|float|int|string>  $bag  declared transverse metadata; keys are
     *                                                     application header keys, `__` refused.
     *                                                     Value shape is the phpdoc contract here;
     *                                                     the runtime net is Message::withHeader,
     *                                                     which refuses an unjsonable value
     *
     * @throws InvalidMessageException when a bag key is blank, padded or `__`-reserved
     */
    public function __construct(
        private ?string $correlationId = null,
        private ?string $causationId = null,
        private ?string $actorId = null,
        private ?string $actorType = null,
        private ?string $tenantId = null,
        private array $bag = [],
    ) {
        foreach (array_keys($bag) as $key) {
            $key = (string) $key; // a runtime bag may carry int keys despite the phpdoc shape

            // the same refusal Message's own gate applies, one layer down: a key this boundary
            // accepts must never blow up mid-enrichment when withHeader() replays it
            if ($key === '' || $key !== trim($key)) {
                throw InvalidMessageException::blankHeaderKey($key);
            }

            if (str_starts_with($key, '__')) {
                throw InvalidMessageException::reservedHeaderKey($key);
            }
        }
    }

    /**
     * An empty context: nothing known yet.
     */
    public static function empty(): self
    {
        return new self;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function causationId(): ?string
    {
        return $this->causationId;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function actorType(): ?string
    {
        return $this->actorType;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function bag(): array
    {
        return $this->bag;
    }
}
