<?php

namespace App\Domain\It\Data;

/** Internal opaque identity; never a download or deletion authorization. */
final readonly class ItAttachmentStorageReservation
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $path,
    ) {}
}
