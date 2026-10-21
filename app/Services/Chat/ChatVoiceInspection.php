<?php

namespace App\Services\Chat;

final readonly class ChatVoiceInspection
{
    public function __construct(public string $state, public ?string $mime = null, public ?int $durationMs = null, public ?string $errorCode = null) {}
}
