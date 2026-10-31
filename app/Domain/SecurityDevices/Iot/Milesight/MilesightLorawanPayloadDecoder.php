<?php

namespace App\Domain\SecurityDevices\Iot\Milesight;

use InvalidArgumentException;

/**
 * Clean-room binary TLV uplink payload decoder for Milesight LoRaWAN sensors.
 *
 * Implemented strictly from Milesight's published product user guide byte-format tables
 * (AM103/AM307/AM319 IAQ, EM300-TH/SLD/MCS, WS101 Smart Button, WS202 PIR, WS301 Contact, VS121 People Counter).
 * No third-party or GPL decoder code is used.
 *
 * Official Milesight payload documentation guides:
 * - https://support.milesight-iot.com/kb/guide-1282.html
 * - https://support.milesight-iot.com/kb/guide-1065.html
 * - https://support.milesight-iot.com/kb/guide-1262.html
 */
final class MilesightLorawanPayloadDecoder
{
    /**
     * Decode a hex-encoded or base64-encoded Milesight LoRaWAN uplink payload into normalised sensor fields.
     *
     * @return array<string, int|float|string|bool>
     */
    public function decodeEncoded(string $encoded, ?string $modelHint = null): array
    {
        $trimmed = trim($encoded);
        if ($trimmed === '') {
            return [];
        }

        if (preg_match('/^(?:[0-9a-fA-F]{2})+$/', $trimmed) === 1) {
            $bytes = hex2bin($trimmed);
            if ($bytes === false) {
                throw new InvalidArgumentException('Invalid hexadecimal Milesight payload.');
            }

            return $this->decodeBytes($bytes, $modelHint);
        }

        $decoded = base64_decode($trimmed, true);
        if (! is_string($decoded) || $decoded === '') {
            throw new InvalidArgumentException('Milesight payload must be valid base64 or hexadecimal.');
        }

        return $this->decodeBytes($decoded, $modelHint);
    }

    /**
     * Decode raw binary TLV bytes `[channel_id, channel_type, value...]`.
     *
     * @return array<string, int|float|string|bool>
     */
    public function decodeBytes(string $bytes, ?string $modelHint = null): array
    {
        $len = strlen($bytes);
        if ($len < 3 || $len > 512) {
            return [];
        }

        $model = strtolower(trim((string) $modelHint));
        $result = [];
        $offset = 0;

        while ($offset + 2 <= $len) {
            $channelId = ord($bytes[$offset]);
            $channelType = ord($bytes[$offset + 1]);
            $offset += 2;

            // Battery percentage: 01 75 <uint8>
            if ($channelId === 0x01 && $channelType === 0x75) {
                if ($offset + 1 > $len) {
                    break;
                }
                $result['battery'] = min(100, ord($bytes[$offset]));
                $offset += 1;

                continue;
            }

            // Temperature (0.1 deg C signed int16 LE): ch 0x03 or 0x01, type 0x67
            if (in_array($channelId, [0x01, 0x03], true) && $channelType === 0x67) {
                if ($offset + 2 > $len) {
                    break;
                }
                $raw = ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
                if (($raw & 0x8000) !== 0) {
                    $raw -= 0x10000;
                }
                $result['temperature'] = round($raw / 10.0, 1);
                $offset += 2;

                continue;
            }

            // Relative humidity (0.5 % uint8): ch 0x04 or 0x02, type 0x68
            if (in_array($channelId, [0x02, 0x04], true) && $channelType === 0x68) {
                if ($offset + 1 > $len) {
                    break;
                }
                $result['humidity'] = round(ord($bytes[$offset]) / 2.0, 1);
                $offset += 1;

                continue;
            }

            // WS301 / EM300-MCS magnetic door switch: 03 00 <uint8> (0x00 = closed, 0x01 = open)
            if ($channelId === 0x03 && $channelType === 0x00) {
                if ($offset + 1 > $len) {
                    break;
                }
                $result['door_contact'] = ord($bytes[$offset]) === 0x00 ? 'closed' : 'open';
                $offset += 1;

                continue;
            }

            // WS301 install / tamper switch: 04 00 <uint8> (0x00 = installed/normal, 0x01 = tamper)
            if ($channelId === 0x04 && $channelType === 0x00) {
                if ($offset + 1 > $len) {
                    break;
                }
                $result['tamper'] = ord($bytes[$offset]) !== 0x00;
                $offset += 1;

                continue;
            }

            // EM300-SLD leak status OR AM307/WS202 PIR motion: 05 00 <uint8>
            if ($channelId === 0x05 && $channelType === 0x00) {
                if ($offset + 1 > $len) {
                    break;
                }
                $active = ord($bytes[$offset]) !== 0x00;
                if (str_contains($model, 'sld') || str_contains($model, 'leak') || str_contains($model, 'zld')) {
                    $result['water_leak'] = $active;
                } else {
                    $result['motion'] = $active;
                }
                $offset += 1;

                continue;
            }

            // Illuminance lux (uint16 LE): ch 0x04 or 0x06, type 0x65
            if (in_array($channelId, [0x04, 0x06], true) && $channelType === 0x65) {
                if ($offset + 2 > $len) {
                    break;
                }
                $result['illuminance'] = ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
                $offset += 2;

                continue;
            }

            // Light level index (uint8 0..5): 06 CB <uint8>
            if ($channelId === 0x06 && $channelType === 0xCB) {
                if ($offset + 1 > $len) {
                    break;
                }
                $result['light_level_index'] = ord($bytes[$offset]);
                $offset += 1;

                continue;
            }

            // CO2 concentration ppm (uint16 LE): 07 7D <uint16_le>
            if ($channelId === 0x07 && $channelType === 0x7D) {
                if ($offset + 2 > $len) {
                    break;
                }
                $result['co2'] = ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
                $offset += 2;

                continue;
            }

            // TVOC ppb / index (uint16 LE): 08 7D <uint16_le>
            if ($channelId === 0x08 && $channelType === 0x7D) {
                if ($offset + 2 > $len) {
                    break;
                }
                $result['tvoc'] = ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
                $offset += 2;

                continue;
            }

            // Barometric pressure (0.1 hPa uint16 LE): 09 73 <uint16_le>
            if ($channelId === 0x09 && $channelType === 0x73) {
                if ($offset + 2 > $len) {
                    break;
                }
                $raw = ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
                $result['pressure'] = round($raw / 10.0, 1);
                $offset += 2;

                continue;
            }

            // PM2.5 ug/m3 (uint16 LE): 0B 7D <uint16_le>
            if ($channelId === 0x0B && $channelType === 0x7D) {
                if ($offset + 2 > $len) {
                    break;
                }
                $result['pm25'] = ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
                $offset += 2;

                continue;
            }

            // VS121 people counter total: 04 C9 <total uint8> <regions uint8>
            if ($channelId === 0x04 && $channelType === 0xC9) {
                if ($offset + 2 > $len) {
                    break;
                }
                $result['people_count'] = ord($bytes[$offset]);
                $offset += 2;

                continue;
            }

            // WS101 Smart Button press: FF 2E <uint8>
            if ($channelId === 0xFF && $channelType === 0x2E) {
                if ($offset + 1 > $len) {
                    break;
                }
                $code = ord($bytes[$offset]);
                $result['button_action'] = match ($code) {
                    0x01 => 'single_press',
                    0x02 => 'double_press',
                    0x03 => 'long_press',
                    default => 'pressed',
                };
                $offset += 1;

                continue;
            }

            // Unknown TLV type cannot be safely skipped without knowing its byte width.
            break;
        }

        return $result;
    }
}
