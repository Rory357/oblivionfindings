<?php

namespace Oblivion\Collector\Discovery;

/**
 * Resolves IEEE 802 MAC addresses against the compiled public IEEE Registration Authority
 * MA-L (24-bit OUI) and MA-M (28-bit) datasets on the Site hub.
 *
 * Official sources:
 * - https://standards-oui.ieee.org/oui/oui.csv (IEEE MA-L / OUI)
 * - https://standards-oui.ieee.org/oui28/mam.csv (IEEE MA-M 28-bit)
 */
final class MacVendorLookup
{
    private const string DEFAULT_DATA_PATH = __DIR__.'/data/ieee-oui.tsv';

    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(
        private readonly string $dataPath = self::DEFAULT_DATA_PATH,
    ) {}

    public static function normaliseUnicastMac(?string $mac): ?string
    {
        if (! is_string($mac)) {
            return null;
        }

        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', trim($mac)) ?? '');
        if (strlen($hex) !== 12 || $hex === '000000000000' || $hex === 'FFFFFFFFFFFF') {
            return null;
        }

        $firstOctet = hexdec(substr($hex, 0, 2));
        if (($firstOctet & 0x01) === 0x01) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    public static function isLocallyAdministered(?string $mac): bool
    {
        $normalised = self::normaliseUnicastMac($mac);
        if ($normalised === null) {
            return false;
        }

        $firstOctet = hexdec(substr($normalised, 0, 2));

        return ($firstOctet & 0x02) === 0x02;
    }

    public function lookup(?string $mac): ?string
    {
        $normalised = self::normaliseUnicastMac($mac);
        if ($normalised === null) {
            return null;
        }

        $hex = str_replace(':', '', $normalised);

        $mam = $this->findPrefix(substr($hex, 0, 7));
        if ($mam !== null) {
            return $mam;
        }

        return $this->findPrefix(substr($hex, 0, 6));
    }

    private function findPrefix(string $prefix): ?string
    {
        if (array_key_exists($prefix, $this->cache)) {
            return $this->cache[$prefix];
        }

        if (! is_file($this->dataPath)) {
            return null;
        }

        $handle = @fopen($this->dataPath, 'rb');
        if (! is_resource($handle)) {
            return null;
        }

        try {
            $stat = fstat($handle);
            $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            if ($size <= 0) {
                return null;
            }

            $low = 0;
            $high = $size;
            $found = null;

            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                fseek($handle, $mid);
                if ($mid > $low) {
                    fgets($handle);
                }
                $linePos = ftell($handle);
                if ($linePos === false || $linePos >= $high) {
                    fseek($handle, $low);
                    while (($pos = ftell($handle)) !== false && $pos < $high) {
                        $line = fgets($handle);
                        if (! is_string($line)) {
                            break;
                        }
                        [$key, $vendor] = $this->splitLine($line);
                        $cmp = strcmp($key, $prefix);
                        if ($cmp === 0) {
                            $found = $vendor;
                            break;
                        }
                        if ($cmp > 0) {
                            break;
                        }
                    }
                    break;
                }

                $line = fgets($handle);
                if (! is_string($line)) {
                    break;
                }
                $afterPos = ftell($handle);
                [$key, $vendor] = $this->splitLine($line);
                $cmp = strcmp($key, $prefix);

                if ($cmp === 0) {
                    $found = $vendor;
                    break;
                }
                if ($cmp < 0) {
                    $low = is_int($afterPos) ? $afterPos : $linePos + strlen($line);
                } else {
                    $high = $linePos;
                }
            }

            if (count($this->cache) >= 512) {
                $this->cache = array_slice($this->cache, -256, null, true);
            }
            $this->cache[$prefix] = $found;

            return $found;
        } finally {
            fclose($handle);
        }
    }

    /** @return array{string, string|null} */
    private function splitLine(string $line): array
    {
        $trimmed = rtrim($line, "\r\n");
        $tabPos = strpos($trimmed, "\t");
        if ($tabPos === false) {
            return [$trimmed, null];
        }

        $key = substr($trimmed, 0, $tabPos);
        $vendor = trim(substr($trimmed, $tabPos + 1));

        return [$key, $vendor !== '' ? $vendor : null];
    }
}
