<?php

namespace App\Services\Chat\Voice;

/**
 * Reads Ogg voice recordings: pages as RFC 3533 defines them (every CRC must match), carrying one
 * logical stream of Opus (RFC 7845). The stream must end with its end-of-stream page, so a file cut
 * short between pages is refused rather than guessed at. Ogg Vorbis is refused (see identify()).
 */
final class OggVoiceParser
{
    private const CONTINUED = 0x01;

    private const FIRST = 0x02;

    private const LAST = 0x04;

    private const NO_GRANULE = -1;

    private int $page = 0;

    private bool $headersDone = false;

    private int $preSkip = 0;

    private int $samples = 0;

    private int $lastPacketSamples = 0;

    private int $audioPackets = 0;

    private int $granule = 0;

    private string $packet = '';

    private bool $open = false;

    /** CRCs of runs of zero bytes, by length, for the CRC shortcut below. */
    private array $zeroCrc = [];

    public function __construct(private readonly VoiceBytes $b) {}

    public function parse(): VoiceFacts
    {
        $b = $this->b;
        $data = $b->data;
        $length = $b->length;
        $position = 0;
        $serial = $sequence = 0;
        $ended = false;
        while ($position < $length) {
            $b->count();
            $b->need($position, 27, $length);
            if (substr_compare($data, 'OggS', $position, 4) !== 0) {
                throw new VoiceFormatException('ogg_capture');
            }
            if (ord($data[$position + 4]) !== 0) {
                throw new VoiceFormatException('ogg_version');
            }
            $flags = ord($data[$position + 5]);
            if ($flags & ~(self::CONTINUED | self::FIRST | self::LAST)) {
                throw new VoiceFormatException('ogg_flags');
            }
            ['low' => $low, 'high' => $high, 'serial' => $pageSerial, 'sequence' => $pageSequence, 'crc' => $crc]
                = unpack('Vlow/Vhigh/Vserial/Vsequence/Vcrc', $data, $position + 6);
            if ($low === 0xFFFFFFFF && $high === 0xFFFFFFFF) {
                $granule = self::NO_GRANULE;
            } elseif ($high & 0x80000000) {
                throw new VoiceFormatException('ogg_granule');
            } else {
                $granule = ($high << 32) | $low;
            }
            $segments = ord($data[$position + 26]);
            $b->need($position + 27, $segments, $length);
            $lacing = $segments ? array_values(unpack('C'.$segments, $data, $position + 27)) : [];
            $size = 27 + $segments + array_sum($lacing);
            $b->need($position, $size, $length);
            if ($this->crc(substr_replace(substr($data, $position, $size), "\0\0\0\0", 22, 4)) !== $crc) {
                throw new VoiceFormatException('ogg_crc');
            }
            if ($ended) {
                throw new VoiceFormatException('ogg_after_end');
            }
            if ($this->page === 0) {
                if (! ($flags & self::FIRST)) {
                    throw new VoiceFormatException('ogg_first_page');
                }
                [$serial, $sequence] = [$pageSerial, $pageSequence];
            } elseif ($flags & self::FIRST || $pageSerial !== $serial) {
                throw new VoiceFormatException('ogg_two_streams');
            } elseif ($pageSequence !== (($sequence + $this->page) & 0xFFFFFFFF)) {
                throw new VoiceFormatException('ogg_page_sequence');
            }
            if ((bool) ($flags & self::CONTINUED) !== $this->open) {
                throw new VoiceFormatException('ogg_continuation');
            }
            $offset = $position + 27 + $segments;
            $completed = [];
            foreach ($lacing as $value) {
                $this->packet .= substr($data, $offset, $value);
                $offset += $value;
                if ($value < 255) {
                    $completed[] = $this->packet;
                    $this->packet = '';
                    $this->open = false;
                } else {
                    $this->open = true;
                }
            }
            // RFC 3533: -1 marks a page on which no packet ends; any page where a packet ends carries a real position.
            if (($completed === []) !== ($granule === self::NO_GRANULE)) {
                throw new VoiceFormatException('ogg_granule');
            }
            $ended = (bool) ($flags & self::LAST);
            $this->packets($completed, $granule, $ended);
            $position += $size;
            $this->page++;
        }
        if (! $ended) {
            throw new VoiceFormatException('ogg_missing_end');
        }
        if ($this->open) {
            throw new VoiceFormatException('ogg_truncated_packet');
        }
        if (! $this->headersDone || $this->audioPackets === 0) {
            throw new VoiceFormatException('ogg_no_audio');
        }

        // The final position less the pre-skip (RFC 7845 section 4.3). Every earlier page's position was
        // checked against the packets, so the final one can hide at most one packet's end trimming.
        return new VoiceFacts('audio/ogg', VoiceFacts::nanoseconds($this->granule - $this->preSkip, 48_000));
    }

    /** @param  list<string>  $packets  the packets that end on this page */
    private function packets(array $packets, int $granule, bool $last): void
    {
        if ($this->page === 0) {
            // The identification header is alone on the first page, at position 0 (RFC 7845 section 3).
            if (count($packets) !== 1 || $this->open || $granule !== 0) {
                throw new VoiceFormatException('ogg_first_page');
            }
            $this->identify($packets[0]);

            return;
        }
        $audio = 0;
        foreach ($packets as $index => $packet) {
            $this->b->count();
            if (! $this->headersDone) {
                $this->header($packet);
                // The last header packet finishes its page and audio starts on a fresh one, at position 0.
                if ($this->headersDone && ($index !== count($packets) - 1 || $this->open || $granule !== 0)) {
                    throw new VoiceFormatException('ogg_header_page');
                }

                continue;
            }
            $audio++;
            $this->audioPackets++;
            $this->lastPacketSamples = OpusPacket::samples($packet, 0, strlen($packet));
            $this->samples += $this->lastPacketSamples;
        }
        if ($packets !== [] && $audio === 0 && $granule !== 0) {
            throw new VoiceFormatException('ogg_header_page');
        }
        if ($audio === 0) {
            return;
        }
        // RFC 7845 section 4: each page's position is the running sample count. Only the last page may
        // end earlier (end trimming), by no more than its last packet, and never before the pre-skip.
        if (! $last && $granule !== $this->samples) {
            throw new VoiceFormatException('ogg_granule_mismatch');
        }
        if ($last && ($granule > $this->samples || $granule < $this->samples - $this->lastPacketSamples || $granule < $this->preSkip)) {
            throw new VoiceFormatException('ogg_granule_mismatch');
        }
        $this->granule = $granule;
    }

    /**
     * The first packet names the codec. Only Opus is accepted. Vorbis is refused (Stephan, 23 October
     * 2026): a Vorbis packet's length depends on block sizes held in its setup header, which the checker
     * does not decode, so a Vorbis file could claim to be far shorter than its audio. No browser records it.
     */
    private function identify(string $packet): void
    {
        if (str_starts_with($packet, "\x01vorbis")) {
            throw new VoiceFormatException('ogg_vorbis');
        }
        if (! str_starts_with($packet, 'OpusHead')) {
            throw new VoiceFormatException('ogg_codec');
        }
        $this->preSkip = OpusPacket::head($packet);
    }

    /** OpusTags (RFC 7845 section 5.2): magic, vendor string length and the comment count. */
    private function header(string $packet): void
    {
        if (strlen($packet) < 16 || ! str_starts_with($packet, 'OpusTags') || unpack('V', $packet, 8)[1] > strlen($packet) - 16) {
            throw new VoiceFormatException('opus_tags');
        }
        $this->headersDone = true;
    }

    /**
     * The Ogg CRC (RFC 3533 section 6): polynomial 0x04C11DB7, starting at zero, no reflection and no
     * final inversion, over the page with its CRC field zeroed. PHP's built-in "crc32" hash uses the same
     * polynomial but starts from all ones and inverts the result; XOR-ing the hash of an equal run of zero
     * bytes cancels both, leaving the plain CRC. A bit-by-bit version in the tests confirms this.
     */
    private function crc(string $page): int
    {
        $length = strlen($page);
        $zero = $this->zeroCrc[$length] ??= unpack('V', hash('crc32', str_repeat("\0", $length), true))[1];

        return unpack('V', hash('crc32', $page, true))[1] ^ $zero;
    }
}
