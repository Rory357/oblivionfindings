<?php

namespace Oblivion\Collector\Terminal;

/**
 * One interactive SSH command line on a device (S&D Phase G), behind an interface so tests can
 * stand in for a switch.
 */
interface TerminalShell
{
    /** The device's host key as "SHA256:…", read before any login is sent. */
    public function hostKeyFingerprint(): ?string;

    /** @param array<string, scalar|null> $material */
    public function login(string $username, array $material): bool;

    public function write(string $text): void;

    /** Whatever the device has sent since the last read, without waiting more than a moment. */
    public function read(): string;

    public function isOpen(): bool;

    public function close(): void;
}
