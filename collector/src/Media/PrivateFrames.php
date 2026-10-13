<?php

namespace Oblivion\Collector\Media;

use RuntimeException;

final class PrivateFrames
{
    public static function write($stream, string $json): void
    {
        if (strlen($json) === 0 || strlen($json) > 65536) {
            throw new RuntimeException('Media IPC unavailable.');
        }
        $data = pack('N', strlen($json)).$json;
        while ($data !== '') {
            $n = fwrite($stream, $data);
            if (! is_int($n) || $n <= 0) {
                throw new RuntimeException('Media IPC unavailable.');
            } $data = substr($data, $n);
        }
        fflush($stream);
    }

    public static function read($stream): array
    {
        $header = self::bytes($stream, 4);
        $size = unpack('Nsize', $header)['size'];
        if ($size < 1 || $size > 65536) {
            throw new RuntimeException('Media IPC unavailable.');
        }
        $raw = self::bytes($stream, $size);
        try {
            $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } finally {
            sodium_memzero($raw);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Media IPC unavailable.');
        }

        return $value;
    }

    private static function bytes($stream, int $size): string
    {
        $raw = '';
        while (strlen($raw) < $size) {
            $part = fread($stream, $size - strlen($raw));
            if (! is_string($part) || $part === '') {
                throw new RuntimeException('Media IPC unavailable.');
            } $raw .= $part;
        }

return $raw;
    }
}
