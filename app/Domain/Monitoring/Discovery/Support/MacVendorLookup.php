<?php

namespace App\Domain\Monitoring\Discovery\Support;

/**
 * Resolves IEEE 802 MAC addresses against the compiled public IEEE Registration Authority
 * MA-L (24-bit OUI) and MA-M (28-bit) datasets.
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

    /**
     * Normalise a unicast MAC address to uppercase colon-delimited form (AA:BB:CC:DD:EE:FF).
     * Rejects broadcast, all-zero, and multicast (group-bit) MAC addresses.
     */
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
            // Multicast / broadcast group bit set (e.g. 01:00:5E:*, 33:33:*).
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /**
     * Whether a unicast MAC has the locally administered bit (U/L bit 1 of octet 0) set.
     * Common on randomised Wi-Fi client MACs (iOS, Android, modern Windows) and virtual NICs.
     */
    public static function isLocallyAdministered(?string $mac): bool
    {
        $normalised = self::normaliseUnicastMac($mac);
        if ($normalised === null) {
            return false;
        }

        $firstOctet = hexdec(substr($normalised, 0, 2));

        return ($firstOctet & 0x02) === 0x02;
    }

    /**
     * Look up the official IEEE OUI / MA-M vendor name for a MAC address.
     */
    public function lookup(?string $mac): ?string
    {
        $normalised = self::normaliseUnicastMac($mac);
        if ($normalised === null) {
            return null;
        }

        $hex = str_replace(':', '', $normalised);

        // Check 28-bit MA-M first, then 24-bit MA-L OUI.
        $mam = $this->findPrefix(substr($hex, 0, 7));
        if ($mam !== null) {
            return $mam;
        }

        return $this->findPrefix(substr($hex, 0, 6));
    }

    /**
     * Map a raw IEEE OUI vendor or protocol manufacturer string to a concise brand name.
     */
    public static function canonicalBrand(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $cleaned = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($cleaned === '') {
            return null;
        }

        $lower = strtolower($cleaned);
        $patterns = [
            '/\bubiquiti\b/i' => 'Ubiquiti',
            '/\bhikvision\b|\bhikrobot\b|\bezviz\b/i' => 'Hikvision',
            '/\bdahua\b|\bimou\b/i' => 'Dahua',
            '/\baxis communications\b|\baxis\b/i' => 'Axis',
            '/\bmilesight\b|\bursalink\b/i' => 'Milesight',
            '/\bqueclink\b/i' => 'Queclink',
            '/\bmikrotik\b|\brouterboard\b/i' => 'MikroTik',
            '/\bhewlett packard enterprise\b|\bhpe\b|\baruba\b/i' => 'HPE / Aruba',
            '/\bhewlett[- ]packard\b|\bhp inc\b/i' => 'HP',
            '/\bcisco\b|\bmeraki\b/i' => 'Cisco',
            '/\bfortinet\b/i' => 'Fortinet',
            '/\bjuniper\b/i' => 'Juniper',
            '/\bsynology\b/i' => 'Synology',
            '/\bqnap\b/i' => 'QNAP',
            '/\bbrother industries\b|\bbrother\b/i' => 'Brother',
            '/\bseiko epson\b|\bepson\b/i' => 'Epson',
            '/\bcanon\b/i' => 'Canon',
            '/\bricoh\b/i' => 'Ricoh',
            '/\bkyocera\b/i' => 'Kyocera',
            '/\bkonica minolta\b/i' => 'Konica Minolta',
            '/\breolink\b/i' => 'Reolink',
            '/\buniview\b/i' => 'Uniview',
            '/\bhanwha\b|\bwisenet\b/i' => 'Hanwha Vision',
            '/\bvivotek\b/i' => 'Vivotek',
            '/\bavigilon\b/i' => 'Avigilon',
            '/\bgallagher\b/i' => 'Gallagher',
            '/\bintegrated control technology\b|\bict\b/i' => 'ICT',
            '/\bparadox security\b|\bparadox\b/i' => 'Paradox',
            '/\btexecom\b/i' => 'Texecom',
            '/\bbosch security\b|\bbosch\b/i' => 'Bosch',
            '/\bamerican power conversion\b|\bschneider electric\b|\bapc\b/i' => 'APC',
            '/\beaton\b/i' => 'Eaton',
            '/\bgrandstream\b/i' => 'Grandstream',
            '/\byealink\b/i' => 'Yealink',
            '/\bsnom\b/i' => 'Snom',
            '/\bpolycom\b|\bpoly\b/i' => 'Poly',
            '/\braspberry pi\b/i' => 'Raspberry Pi',
            '/\bespressif\b/i' => 'Espressif',
            '/\bshelly\b|\ballterco\b/i' => 'Shelly',
            '/\bsignify\b|\bphilips lighting\b|\bphilips hue\b/i' => 'Philips Hue',
            '/\bsonos\b/i' => 'Sonos',
            '/\btp-link\b|\btplink\b/i' => 'TP-Link',
            '/\bnetgear\b/i' => 'Netgear',
            '/\bd-link\b|\bdlink\b/i' => 'D-Link',
            '/\basustek\b|\basus\b/i' => 'ASUS',
            '/\bapple\b/i' => 'Apple',
            '/\bsamsung\b/i' => 'Samsung',
            '/\bintel corporate\b|\bintel\b/i' => 'Intel',
            '/\brealtek\b/i' => 'Realtek',
            '/\bdell\b/i' => 'Dell',
            '/\blenovo\b/i' => 'Lenovo',
            '/\bmicrosoft\b/i' => 'Microsoft',
            '/\bgoogle\b/i' => 'Google',
            '/\bamazon technologies\b|\bamazon\b/i' => 'Amazon',
            '/\blg electronics\b|\blg innotek\b/i' => 'LG',
            '/\bsony\b/i' => 'Sony',
            '/\bproxmox\b/i' => 'Proxmox',
            '/\bvmware\b/i' => 'VMware',
            '/\bqemu\b/i' => 'QEMU / KVM',
        ];

        foreach ($patterns as $pattern => $brand) {
            if (preg_match($pattern, $lower) === 1) {
                return $brand;
            }
        }

        // Strip common legal entity suffixes for concise display while keeping original meaning.
        $concise = preg_replace(
            '/,?\s*\b(?:co\.?,?\s*ltd\.?|inc\.?|incorporated|corp\.?|corporation|ltd\.?|limited|gmbh|ab|ag|b\.?v\.?|s\.?a\.?|llc|plc|pty)\b\.?$/i',
            '',
            $cleaned,
        ) ?? $cleaned;
        $concise = trim($concise, " \t\n\r\0\x0B,.-");

        return $concise !== '' ? substr($concise, 0, 100) : substr($cleaned, 0, 100);
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
