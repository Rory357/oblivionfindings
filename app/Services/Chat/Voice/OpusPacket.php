<?php

namespace App\Services\Chat\Voice;

/**
 * Opus packet framing (RFC 6716 section 3) and the Opus identification header (RFC 7845 section 5.1).
 * Nothing is decoded: the checker only needs each packet's length in time and proof that its framing is valid.
 */
final class OpusPacket
{
    /** 48 kHz samples per frame for each TOC configuration number (RFC 6716 section 3.1, table 2). */
    private const FRAME_SAMPLES = [
        480, 960, 1920, 2880, // 0–3 SILK-only narrowband: 10, 20, 40, 60 ms
        480, 960, 1920, 2880, // 4–7 SILK-only medium-band
        480, 960, 1920, 2880, // 8–11 SILK-only wideband
        480, 960, // 12–13 hybrid super-wideband: 10, 20 ms
        480, 960, // 14–15 hybrid fullband
        120, 240, 480, 960, // 16–19 CELT-only narrowband: 2.5, 5, 10, 20 ms
        120, 240, 480, 960, // 20–23 CELT-only wideband
        120, 240, 480, 960, // 24–27 CELT-only super-wideband
        120, 240, 480, 960, // 28–31 CELT-only fullband
    ];

    private const MAX_FRAME_BYTES = 1275;

    /** 120 ms at 48 kHz, the longest a packet may be. */
    private const MAX_PACKET_SAMPLES = 5760;

    /** Returns the packet's length in 48 kHz samples after checking requirements R1 to R7 of RFC 6716 section 3.4. */
    public static function samples(string $data, int $offset, int $length): int
    {
        if ($length < 1 || $offset < 0 || $offset + $length > strlen($data)) {
            throw new VoiceFormatException('opus_packet_empty'); // R1
        }
        $end = $offset + $length;
        $toc = ord($data[$offset]);
        $frame = self::FRAME_SAMPLES[$toc >> 3];
        switch ($toc & 0x03) {
            case 0: // one frame
                self::frameSize($length - 1);

                return $frame;
            case 1: // two frames of equal size
                if (($length - 1) % 2 !== 0) {
                    throw new VoiceFormatException('opus_packet_framing'); // R3
                }
                self::frameSize(intdiv($length - 1, 2));

                return 2 * $frame;
            case 2: // two frames, the first with an explicit length
                [$first, $used] = self::frameLength($data, $offset + 1, $end); // R4
                self::frameSize($first);
                self::frameSize($length - 1 - $used - $first);

                return 2 * $frame;
            default: // any number of frames, with a frame count byte
                if ($length < 2) {
                    throw new VoiceFormatException('opus_packet_framing'); // R6
                }
                $countByte = ord($data[$offset + 1]);
                $frames = $countByte & 0x3F;
                if ($frames === 0 || $frames * $frame > self::MAX_PACKET_SAMPLES) {
                    throw new VoiceFormatException('opus_packet_frames'); // R5
                }
                $position = $offset + 2;
                $padding = 0;
                if ($countByte & 0x40) {
                    do {
                        if ($position >= $end) {
                            throw new VoiceFormatException('opus_packet_padding'); // R6, R7
                        }
                        $value = ord($data[$position++]);
                        $padding += $value === 255 ? 254 : $value;
                        // Stop as soon as the padding cannot fit in what is left: each 255 adds 254 bytes,
                        // so a hostile run of them ends after about 1/255 of the packet.
                        if ($padding > $end - $position) {
                            throw new VoiceFormatException('opus_packet_padding'); // R6, R7
                        }
                    } while ($value === 255);
                }
                $frameEnd = $end - $padding;
                if ($frameEnd < $position) {
                    throw new VoiceFormatException('opus_packet_padding'); // R6, R7
                }
                if (($countByte & 0x80) === 0) { // constant bitrate: equal frames fill what is left
                    $available = $frameEnd - $position;
                    if ($available % $frames !== 0) {
                        throw new VoiceFormatException('opus_packet_framing'); // R6
                    }
                    self::frameSize(intdiv($available, $frames));
                } else { // variable bitrate: lengths of the first M-1 frames, the last takes the rest
                    $sum = 0;
                    for ($i = 1; $i < $frames; $i++) {
                        [$size, $used] = self::frameLength($data, $position, $frameEnd);
                        self::frameSize($size);
                        $position += $used;
                        $sum += $size;
                    }
                    $last = $frameEnd - $position - $sum;
                    if ($last < 0) {
                        throw new VoiceFormatException('opus_packet_framing'); // R7
                    }
                    self::frameSize($last);
                }

                return $frames * $frame;
        }
    }

    /**
     * Checks an OpusHead packet (RFC 7845 section 5.1) and returns its pre-skip in 48 kHz samples.
     * Only channel mapping family 0 (mono or stereo, one Opus stream) is accepted: it is what
     * browsers record, and other families change how every packet is framed.
     */
    public static function head(string $packet): int
    {
        if (strlen($packet) < 19 || ! str_starts_with($packet, 'OpusHead')) {
            throw new VoiceFormatException('opus_head');
        }
        $version = ord($packet[8]);
        if ($version === 0 || $version > 15) {
            throw new VoiceFormatException('opus_head_version');
        }
        $channels = ord($packet[9]);
        if (ord($packet[18]) !== 0 || $channels < 1 || $channels > 2) {
            throw new VoiceFormatException('opus_channel_mapping');
        }

        return unpack('v', $packet, 10)[1];
    }

    /** One frame length field: a single byte below 252, otherwise two bytes (RFC 6716 section 3.2.1). */
    private static function frameLength(string $data, int $position, int $end): array
    {
        if ($position >= $end) {
            throw new VoiceFormatException('opus_packet_framing');
        }
        $first = ord($data[$position]);
        if ($first < 252) {
            [$size, $used] = [$first, 1];
        } else {
            if ($position + 1 >= $end) {
                throw new VoiceFormatException('opus_packet_framing');
            }
            [$size, $used] = [ord($data[$position + 1]) * 4 + $first, 2];
        }
        if ($size > $end - $position - $used) {
            throw new VoiceFormatException('opus_packet_framing'); // R4, R7
        }

        return [$size, $used];
    }

    private static function frameSize(int $size): void
    {
        if ($size < 0 || $size > self::MAX_FRAME_BYTES) {
            throw new VoiceFormatException('opus_frame_size'); // R2
        }
    }
}
