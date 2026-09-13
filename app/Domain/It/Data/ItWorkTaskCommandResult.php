<?php

namespace App\Domain\It\Data;

/** An opaque command acknowledgement, never current task content. */
final readonly class ItWorkTaskCommandResult
{
    public function __construct(public string $status, public array $data) {}

    public function toArray(): array
    {
        return ['status' => $this->status, 'data' => $this->data];
    }
}
