<?php

namespace App\Support\HealthSafety;

/**
 * Reads an MP4 / MOV / M4V video's length from its "mvhd" box, in plain PHP (no ffmpeg on the server). Returns whole
 * seconds, or null when the file isn't one of those or the box can't be found — callers then fall back to the length the
 * browser reported, still capped (G3 board M5d, decision 22).
 */
final class VideoDuration
{
    public static function seconds(string $absolutePath): ?int
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            return self::scan($handle, 0, (int) filesize($absolutePath), 0);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function scan($handle, int $start, int $end, int $depth): ?int
    {
        $offset = $start;
        while ($offset + 8 <= $end && $depth < 4) {
            fseek($handle, $offset);
            $header = fread($handle, 8);
            if ($header === false || strlen($header) < 8) {
                return null;
            }
            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerSize = 8;
            if ($size === 1) {
                $large = fread($handle, 8);
                if ($large === false || strlen($large) < 8) {
                    return null;
                }
                $parts = unpack('N2', $large);
                $size = ($parts[1] * 4294967296) + $parts[2];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $end - $offset;
            }
            if ($size < $headerSize) {
                return null;
            }
            if ($type === 'moov') {
                return self::scan($handle, $offset + $headerSize, $offset + $size, $depth + 1);
            }
            if ($type === 'mvhd') {
                fseek($handle, $offset + $headerSize);
                $versionFlags = fread($handle, 4);
                if ($versionFlags === false || strlen($versionFlags) < 4) {
                    return null;
                }
                if (ord($versionFlags[0]) === 1) {
                    $body = fread($handle, 28);
                    if ($body === false || strlen($body) < 28) {
                        return null;
                    }
                    $timescale = unpack('N', substr($body, 16, 4))[1];
                    $d = unpack('N2', substr($body, 20, 8));
                    $duration = ($d[1] * 4294967296) + $d[2];
                } else {
                    $body = fread($handle, 16);
                    if ($body === false || strlen($body) < 16) {
                        return null;
                    }
                    $timescale = unpack('N', substr($body, 8, 4))[1];
                    $duration = unpack('N', substr($body, 12, 4))[1];
                }

                return $timescale > 0 ? (int) ceil($duration / $timescale) : null;
            }
            $offset += $size;
        }

        return null;
    }
}
