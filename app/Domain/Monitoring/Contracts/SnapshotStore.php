<?php

namespace App\Domain\Monitoring\Contracts;

interface SnapshotStore
{
    public function put(string $path, string $contents): void;

    public function read(string $path): string;

    public function delete(string $path): void;

    public function exists(string $path): bool;
}
