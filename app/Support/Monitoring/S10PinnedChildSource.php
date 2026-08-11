<?php

namespace App\Support\Monitoring;

use Throwable;

final class S10PinnedChildSource
{
    private const int MAXIMUM_SOURCE_BYTES = 32_768;

    public function read(string $path, string $committedSource): ?string
    {
        if ($committedSource === ''
            || strlen($committedSource) > self::MAXIMUM_SOURCE_BYTES
            || is_link($path)) {
            return null;
        }

        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        if (! is_array($before) || $handle === false) {
            return null;
        }

        try {
            $opened = @fstat($handle);
            $size = is_array($opened) ? ($opened['size'] ?? null) : null;
            if (! is_int($size)
                || $size !== strlen($committedSource)
                || $size > self::MAXIMUM_SOURCE_BYTES
                || (($opened['mode'] ?? 0) & 0170000) !== 0100000) {
                return null;
            }

            $source = stream_get_contents($handle, self::MAXIMUM_SOURCE_BYTES + 1);
            $read = @fstat($handle);
            $final = @lstat($path);
            if (! is_string($source)
                || ! hash_equals($committedSource, $source)
                || ! is_array($read)
                || ! is_array($final)
                || ! $this->sameFile($before, $opened)
                || ! $this->sameFile($opened, $read)
                || ! $this->sameFile($read, $final)) {
                return null;
            }

            return $source;
        } catch (Throwable) {
            return null;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string|int, mixed> $left @param array<string|int, mixed> $right */
    private function sameFile(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $key) {
            if (! array_key_exists($key, $left)
                || ! array_key_exists($key, $right)
                || $left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }
}
