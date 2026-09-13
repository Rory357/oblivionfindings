<?php

namespace App\Domain\It\Data;

/** Unscanned provider evidence, never an authorized download or persisted file. */
final readonly class ItEmailAttachment
{
    public function __construct(
        public string $name,
        public string $mime,
        public int $size,
        public bool $inline,
        public string $provider,
        public ?string $remoteId,
        public ?string $encodedContent,
        public string $providerName,
    ) {}
}
