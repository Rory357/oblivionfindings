<?php

namespace Oblivion\Collector\Runtime;

/**
 * One SSH connection, opened by the hub to a pinned numeric address.
 *
 * The order matters and the runner keeps it: read the device's host key first, compare it with the
 * trusted key, and only then log in.
 */
interface SshSession
{
    /** "SHA256:<43 base64 chars>", or null when the device offered no usable key. */
    public function hostKeyFingerprint(): ?string;

    /** @param array<string, scalar|null> $material */
    public function login(string $username, array $material): bool;

    /** @return array{output: string, truncated: bool, timed_out: bool} */
    public function exec(string $command, int $maxBytes, int $timeoutSeconds): array;

    public function close(): void;
}
