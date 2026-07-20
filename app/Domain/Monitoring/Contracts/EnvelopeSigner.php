<?php

namespace App\Domain\Monitoring\Contracts;

interface EnvelopeSigner
{
    public function activeKeyId(): string;

    public function sign(string $keyId, string $message): string;

    public function verify(string $keyId, string $message, string $signature): bool;
}
