<?php

namespace Oblivion\Collector\Terminal;

/** A terminal that never opened (refused before any login): reported as closed with its reason. */
final class ClosedShell implements TerminalShell
{
    public function hostKeyFingerprint(): ?string
    {
        return null;
    }

    public function login(string $username, array $material): bool
    {
        return false;
    }

    public function write(string $text): void {}

    public function read(): string
    {
        return '';
    }

    public function isOpen(): bool
    {
        return false;
    }

    public function close(): void {}
}
