<?php

namespace Tests\Unit\Chat\Voice;

use App\Services\Chat\Voice\ChatVoiceChecker;
use App\Services\Chat\Voice\OggVoiceParser;
use App\Services\Chat\Voice\OpusPacket;
use App\Services\Chat\Voice\VoiceBytes;
use App\Services\Chat\Voice\VoiceFacts;
use App\Services\Chat\Voice\VoiceFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use Tests\Support\ChatVoice\Mp4Fixtures;
use Tests\Support\ChatVoice\OggFixtures;
use Tests\Support\ChatVoice\OpusFrames;
use Tests\Support\ChatVoice\WebmFixtures;

/** Oblivion's own voice-note checker (Chat plan item C2.5), against docs/chat-experience/clean-room/specs/voice-checker.md. */
final class ChatVoiceCheckerTest extends TestCase
{
    public const FIXTURES = __DIR__.'/../../../fixtures/chat-voice';

    /**
     * Recordings made for Oblivion in headless browsers on 23 October 2026 (tests/fixtures/chat-voice/README.md),
     * with the length the checker finds and the length the earlier ffprobe-based check found.
     */
    public static function recordings(): array
    {
        return [
            'Chrome WebM 5 s' => ['chrome-webm-opus-5s.webm', 'audio/webm', 4860, 4860],
            'Chrome WebM 119 s' => ['chrome-webm-opus-119s.webm', 'audio/webm', 118863, 118863],
            'Chrome WebM paused' => ['chrome-webm-opus-paused.webm', 'audio/webm', 4860, 4860],
            'Edge WebM 5 s' => ['edge-webm-opus-5s.webm', 'audio/webm', 4860, 4860],
            'Edge WebM 119 s' => ['edge-webm-opus-119s.webm', 'audio/webm', 118862, 118863],
            'Firefox WebM 5 s' => ['firefox-webm-opus-5s.webm', 'audio/webm', 5620, 5620],
            'Firefox WebM 119 s' => ['firefox-webm-opus-119s.webm', 'audio/webm', 120180, 120180],
            'Firefox WebM paused' => ['firefox-webm-opus-paused.webm', 'audio/webm', 5020, 5020],
            'Firefox Ogg 5 s' => ['firefox-ogg-opus-5s.ogg', 'audio/ogg', 5160, 5167],
            'Firefox Ogg 119 s' => ['firefox-ogg-opus-119s.ogg', 'audio/ogg', 119040, 119047],
            'Chrome MP4 AAC 5 s' => ['chrome-mp4-aac-5s.m4a', 'audio/mp4', 4757, 4757],
        ];
    }

    #[DataProvider('recordings')]
    public function test_reads_browser_recordings_like_ffprobe_did(string $file, string $mime, int $ms, int $ffprobeMs): void
    {
        $result = (new ChatVoiceChecker)->check(file_get_contents(self::FIXTURES.'/'.$file));
        self::assertSame(['ready', $mime, $ms, null], [$result->state, $result->mime, $result->durationMs, $result->errorCode]);
        self::assertLessThanOrEqual(20, abs($ms - $ffprobeMs));
    }

    public function test_refuses_opus_in_mp4_which_chrome_records_for_plain_audio_mp4(): void
    {
        $this->assertBlocked(file_get_contents(self::FIXTURES.'/chrome-mp4-opus-5s.m4a'), 'voice_file_invalid', 'mp4_codec');
    }

    public static function generated(): array
    {
        $cases = [];
        foreach ([5.0, 119.0] as $seconds) {
            foreach (['chrome', 'firefox', 'known'] as $style) {
                $cases["WebM $style $seconds s"] = [fn () => WebmFixtures::file(['style' => $style, 'seconds' => $seconds])];
            }
            $cases["Ogg Opus $seconds s"] = [fn () => OggFixtures::file(['seconds' => $seconds])];
            $cases["MP4 moov first $seconds s"] = [fn () => Mp4Fixtures::file(['seconds' => $seconds])];
            $cases["MP4 mdat first $seconds s"] = [fn () => Mp4Fixtures::file(['seconds' => $seconds, 'movieFirst' => false])];
            $cases["MP4 fragmented $seconds s"] = [fn () => Mp4Fixtures::file(['seconds' => $seconds, 'fragmented' => true])];
        }

        return $cases;
    }

    #[DataProvider('generated')]
    public function test_reads_generated_files_of_every_supported_shape(\Closure $make): void
    {
        $file = $make();
        $result = (new ChatVoiceChecker)->check($file->bytes);
        self::assertSame(['ready', $file->mime, $file->durationMs], [$result->state, $result->mime, $result->durationMs], $file->name);
    }

    /** One hand-built file per rule: [bytes maker, error code, failed rule or null when the length decides]. */
    public static function badFiles(): array
    {
        $webm = fn (array $o) => fn () => WebmFixtures::file($o)->bytes;
        $ogg = fn (array $o) => fn () => OggFixtures::file($o)->bytes;
        $mp4 = fn (array $o) => fn () => Mp4Fixtures::file($o)->bytes;
        $cut = fn (\Closure $make, float $at) => fn () => substr($bytes = $make(), 0, (int) (strlen($bytes) * $at));
        $tracksAt = fn (array $segment) => array_search(WebmFixtures::TRACKS, array_column($segment, 'id'), true);

        return [
            'zero-length file' => [fn () => '', 'voice_file_invalid', null],
            'not audio at all' => [fn () => file_get_contents(__FILE__), 'voice_file_invalid', 'unknown_format'],
            'over 20 MiB' => [fn () => str_repeat("\0", 20 * 1024 * 1024 + 1), 'voice_file_invalid', null],

            'WebM two tracks' => [$webm(['entries' => 2]), 'voice_file_invalid', 'webm_two_tracks'],
            'WebM video track' => [$webm(['trackType' => 1, 'video' => true]), 'voice_file_invalid', 'webm_video'],
            'WebM not an audio track' => [$webm(['trackType' => 17]), 'voice_file_invalid', 'webm_not_audio'],
            'WebM wrong codec' => [$webm(['codec' => 'A_VORBIS']), 'voice_file_invalid', 'webm_codec'],
            'WebM wrong document type' => [$webm(['docType' => 'mkv3d']), 'voice_file_invalid', 'webm_doctype'],
            'WebM too long' => [$webm(['seconds' => 121.0]), 'voice_too_long', null],
            'WebM declares too long' => [$webm(['duration' => 121_000.0]), 'voice_too_long', null],
            'WebM too short' => [$webm(['style' => 'firefox', 'seconds' => 0.02]), 'voice_file_invalid', null],
            'WebM cut inside Tracks' => [fn () => substr($bytes = WebmFixtures::file()->bytes, 0, strpos($bytes, "\x16\x54\xAE\x6B") + 20), 'voice_file_invalid', 'webm_element_past_end'],
            'WebM cut inside a block' => [fn () => substr(WebmFixtures::file()->bytes, 0, -7), 'voice_file_invalid', 'webm_element_past_end'],
            'WebM size runs past the end' => [$webm(['segment' => function ($segment) use ($tracksAt) {
                $segment[$tracksAt($segment)]['declaredSize'] = 1_000_000;

                return $segment;
            }]), 'voice_file_invalid', 'webm_element_past_end'],
            'WebM unknown-size Info' => [$webm(['segment' => function ($segment) {
                $segment[0]['unknown'] = true;

                return $segment;
            }]), 'voice_file_invalid', 'webm_unknown_size'],
            'WebM unknown-size block' => [$webm(['segment' => function ($segment) {
                $segment[3]['payload'][1]['unknown'] = true;

                return $segment;
            }]), 'voice_file_invalid', 'webm_unknown_size'],
            'WebM ID not in shortest form' => [$webm(['entry' => fn ($entry) => [...$entry, "\x40\x01\x81\x00"]]), 'voice_file_invalid', 'webm_id'],
            'WebM unknown element in a Cluster' => [$webm(['segment' => function ($segment) {
                $segment[3]['payload'][] = WebmFixtures::el(0xC4, "\x00");

                return $segment;
            }]), 'voice_file_invalid', 'webm_cluster_element'],
            'WebM unknown Segment element' => [$webm(['style' => 'known', 'segment' => fn ($segment) => [...$segment, WebmFixtures::el(0x1A45DFA2, '')]]), 'voice_file_invalid', 'webm_segment_element'],
            'WebM unknown EBML header element' => [$webm(['header' => fn ($header) => [...$header, WebmFixtures::el(0x4299, "\x00")]]), 'voice_file_invalid', 'webm_header_element'],
            'WebM attachments' => [$webm(['segment' => fn ($segment) => [...$segment, WebmFixtures::el(0x1941A469, [])]]), 'voice_file_invalid', 'webm_attachments'],
            'WebM encoded content' => [$webm(['entry' => fn ($entry) => [...$entry, WebmFixtures::el(0x6D80, [])]]), 'voice_file_invalid', 'webm_content_encoding'],
            'WebM encrypted block' => [$webm(['segment' => function ($segment) {
                $segment[3]['payload'][] = WebmFixtures::el(0xAF, "\x81\x00\x00\x80\xFC");

                return $segment;
            }]), 'voice_file_invalid', 'webm_encrypted'],
            'WebM block for another track' => [$webm(['blocks' => function ($blocks) {
                $blocks[3]['track'] = 3;

                return $blocks;
            }]), 'voice_file_invalid', 'webm_block_track'],
            'WebM block back in time' => [$webm(['style' => 'firefox', 'blocks' => function ($blocks) {
                $blocks[10]['time'] = $blocks[9]['time'] - 5;

                return $blocks;
            }]), 'voice_file_invalid', 'webm_block_order'],
            'WebM cluster without timestamp' => [$webm(['segment' => function ($segment) {
                array_shift($segment[3]['payload']);

                return $segment;
            }]), 'voice_file_invalid', 'webm_cluster_timestamp'],
            'WebM bytes after a known-size Segment' => [fn () => WebmFixtures::file(['style' => 'known'])->bytes."\xEC\x80", 'voice_file_invalid', 'webm_data_after_segment'],
            'WebM no audio blocks' => [$webm(['segment' => fn ($segment) => array_slice($segment, 0, 2)]), 'voice_file_invalid', 'webm_no_audio'],
            'WebM multichannel Opus' => [$webm(['opusHead' => OpusFrames::head(6, 312, 1, 1).chr(4).chr(2).'012345']), 'voice_file_invalid', 'opus_channel_mapping'],
            'WebM bad Opus framing' => [$webm(['blocks' => function ($blocks) {
                $blocks[2]['frames'] = [chr((31 << 3) | 1).'abc'];

                return $blocks;
            }]), 'voice_file_invalid', 'opus_packet_framing'],
            'WebM lacing with one frame' => [$webm(['style' => 'firefox', 'blocks' => function ($blocks) {
                $blocks[4]['lacing'] = 1;
                $blocks[4]['frames'] = [$blocks[4]['frames'][0]];

                return $blocks;
            }]), 'voice_file_invalid', 'webm_lacing'],

            'Ogg two streams' => [$ogg(['secondStream' => true]), 'voice_file_invalid', 'ogg_two_streams'],
            'Ogg wrong codec' => [$ogg(['codec' => 'speex']), 'voice_file_invalid', 'ogg_codec'],
            'Ogg too long' => [$ogg(['seconds' => 121.0]), 'voice_too_long', null],
            // Vorbis is refused (Stephan, 23 October 2026): its length can't be read without its setup header.
            'Ogg Vorbis' => [$ogg(['codec' => 'vorbis']), 'voice_file_invalid', 'ogg_vorbis'],
            'Ogg too short' => [$ogg(['seconds' => 0.02]), 'voice_file_invalid', null],
            'Ogg cut inside a page' => [$cut(fn () => OggFixtures::file()->bytes, 0.5), 'voice_file_invalid', 'truncated'],
            'Ogg bad CRC' => [function () {
                $bytes = OggFixtures::file()->bytes;
                $bytes[3000] = chr(ord($bytes[3000]) ^ 0x10);

                return $bytes;
            }, 'voice_file_invalid', 'ogg_crc'],
            'Ogg no end-of-stream page' => [$ogg(['noEnd' => true, 'trim' => 0]), 'voice_file_invalid', 'ogg_missing_end'],
            'Ogg page position disagrees with its packets' => [$ogg(['granuleSkew' => [3, 960]]), 'voice_file_invalid', 'ogg_granule_mismatch'],
            'Ogg end trimmed by more than its last packet' => [$ogg(['trim' => 3000]), 'voice_file_invalid', 'ogg_granule_mismatch'],
            'Ogg position on a page where no packet ends' => [$ogg(['pages' => function ($pages) {
                $pages[4]['granule'] = -1;

                return $pages;
            }]), 'voice_file_invalid', 'ogg_granule'],
            'Ogg identification header not alone' => [$ogg(['tagsOnFirstPage' => true]), 'voice_file_invalid', 'ogg_first_page'],
            'Ogg page missing from the sequence' => [$ogg(['sequenceGap' => 5]), 'voice_file_invalid', 'ogg_page_sequence'],
            'Ogg continued flag without a continued packet' => [$ogg(['pageFlags' => [4 => 0x01]]), 'voice_file_invalid', 'ogg_continuation'],

            'MP4 two tracks' => [$mp4(['tracks' => 2]), 'voice_file_invalid', 'mp4_two_tracks'],
            'MP4 video track' => [$mp4(['handler' => 'vide']), 'voice_file_invalid', 'mp4_not_audio'],
            'MP4 wrong codec' => [$mp4(['entry' => 'Opus']), 'voice_file_invalid', 'mp4_codec'],
            'MP4 encrypted audio' => [$mp4(['entry' => 'enca']), 'voice_file_invalid', 'mp4_codec'],
            'MP4 MP3 inside mp4a' => [$mp4(['objectType' => 0x6B]), 'voice_file_invalid', 'mp4_codec'],
            'MP4 AAC Main profile' => [$mp4(['audioObjectType' => 1]), 'voice_file_invalid', 'mp4_codec'],
            'MP4 too long' => [$mp4(['seconds' => 121.0]), 'voice_too_long', null],
            'MP4 declares too long' => [$mp4(['mediaDuration' => 121 * 48_000]), 'voice_too_long', null],
            'MP4 fragmented too long' => [$mp4(['fragmented' => true, 'seconds' => 121.0]), 'voice_too_long', null],
            'MP4 too short' => [$mp4(['seconds' => 0.02]), 'voice_file_invalid', null],
            'MP4 cut inside a box' => [$cut(fn () => Mp4Fixtures::file()->bytes, 0.5), 'voice_file_invalid', 'mp4_box_past_end'],
            'MP4 size runs past the end' => [$mp4(['boxes' => function ($boxes) {
                $boxes[1]['size'] = 1_000_000;

                return $boxes;
            }]), 'voice_file_invalid', 'mp4_box_past_end'],
            'MP4 size zero inside a box' => [$mp4(['trak' => fn ($children) => [...$children, Mp4Fixtures::box('udta', '', ['size' => 0])]]), 'voice_file_invalid', 'mp4_box_size'],
            'MP4 without moov' => [$mp4(['noMovie' => true]), 'voice_file_invalid', 'mp4_no_movie'],
            'MP4 fragments without moov' => [$mp4(['fragmented' => true, 'noMovie' => true]), 'voice_file_invalid', 'mp4_fragment_without_movie'],
            'MP4 audio data outside mdat' => [$mp4(['chunkShift' => -4]), 'voice_file_invalid', 'mp4_sample_data'],
            'MP4 sample counts disagree' => [$mp4(['countShift' => 1]), 'voice_file_invalid', 'mp4_sample_count'],
            'MP4 table longer than its box says' => [$mp4(['trak' => function ($children) {
                $stsz = &$children[1]['content'][2]['content'][2]['content'][3]['content'][0];
                $stsz = substr($stsz, 0, 8).pack('N', unpack('N', $stsz, 8)[1] + 1).substr($stsz, 12);

                return $children;
            }]), 'voice_file_invalid', 'truncated'],
            'MP4 second ftyp' => [$mp4(['boxes' => fn ($boxes) => [...$boxes, $boxes[0]]]), 'voice_file_invalid', 'mp4_ftyp'],
            'MP4 fragments out of order' => [$mp4(['fragmented' => true, 'boxes' => function ($boxes) {
                [$boxes[2], $boxes[3], $boxes[4], $boxes[5]] = [$boxes[4], $boxes[5], $boxes[2], $boxes[3]];

                return $boxes;
            }]), 'voice_file_invalid', 'mp4_fragment_order'],
            'MP4 fragments describe a sample entry that does not exist' => [$mp4(['fragmented' => true, 'sampleDescription' => 2]), 'voice_file_invalid', 'mp4_sample_entries'],
            'MP4 missing sample table' => [$mp4(['trak' => function ($children) {
                $children[1]['content'][2]['content'][2]['content'] = array_slice($children[1]['content'][2]['content'][2]['content'], 0, 4);

                return $children;
            }]), 'voice_file_invalid', 'mp4_sample_table'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_blocks_each_broken_rule(\Closure $make, string $code, ?string $rule): void
    {
        $this->assertBlocked($make(), $code, $rule);
    }

    public function test_reads_an_ogg_packet_that_spans_two_pages(): void
    {
        $random = new Randomizer(new Mt19937(7));
        $vendor = 'Oblivion tests';
        $packets = [];
        for ($i = 0; $i < 20; $i++) {
            $packets[] = OpusFrames::single($random, 31, false, $i === 9 ? 700 : 100);
        }
        // The 701-byte packet starts on page 2 (lacing 255, 255) and ends on page 3 (191), which is continued.
        [$lacing, $body] = OggFixtures::lace(array_slice($packets, 0, 10));
        [$restLacing, $restBody] = OggFixtures::lace(array_slice($packets, 10));
        $tail = end($lacing);
        $pages = [
            OggFixtures::page(0x02, 0, 9, 0, [19], OpusFrames::head(1)),
            OggFixtures::page(0, 0, 9, 1, [30], 'OpusTags'.pack('V', strlen($vendor)).$vendor.pack('V', 0)),
            OggFixtures::page(0, 9 * 960, 9, 2, array_slice($lacing, 0, -1), substr($body, 0, -$tail)),
            OggFixtures::page(0x05, 20 * 960, 9, 3, [$tail, ...$restLacing], substr($body, -$tail).$restBody),
        ];
        $result = (new ChatVoiceChecker)->check(implode('', $pages));
        self::assertSame(['ready', 'audio/ogg', 394], [$result->state, $result->mime, $result->durationMs]);
    }

    public function test_stops_after_one_million_parts(): void
    {
        $header = substr(WebmFixtures::file()->bytes, 0, 200);
        $start = strpos($header, "\x1F\x43\xB6\x75");
        $bytes = substr($header, 0, $start)."\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xE7\x81\x00".str_repeat("\xEC\x80", 1_000_001);
        // A generous time budget, so the parts limit is what stops it.
        $checker = new ChatVoiceChecker(60.0);
        self::assertSame('blocked', $checker->check($bytes)->state);
        try {
            $checker->facts($bytes);
            self::fail('A million and one elements were read.');
        } catch (VoiceFormatException $e) {
            self::assertSame('too_many_parts', $e->reason);
        }
    }

    public function test_stops_when_the_time_budget_is_spent(): void
    {
        $bytes = WebmFixtures::file(['style' => 'firefox', 'seconds' => 119.0])->bytes;
        try {
            (new ChatVoiceChecker(0.000001))->facts($bytes);
            self::fail('The checker kept working past its time budget.');
        } catch (VoiceFormatException $e) {
            self::assertSame('too_slow', $e->reason);
        }
        self::assertSame('blocked', (new ChatVoiceChecker(0.000001))->check($bytes)->state);
    }

    /** Files built to make the most work possible: each must finish well inside two seconds. */
    public static function worstCases(): array
    {
        return [
            'WebM of 20 MiB of one-byte Opus packets' => [function () {
                $head = substr($bytes = WebmFixtures::file()->bytes, 0, strpos($bytes, "\x1F\x43\xB6\x75"));
                $block = "\xA3\x85\x81\x00\x00\x80\xFC";

                return $head."\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xE7\x81\x00".str_repeat($block, intdiv(20 * 1024 * 1024 - strlen($head) - 15, 7));
            }],
            'WebM of one-byte packets ending in a 20 MiB run of Opus padding bytes' => [function () {
                // Found by the checker session: about a second of small blocks, then one packet whose
                // padding-length bytes are 0xFF all the way to the end of the file.
                $head = substr($bytes = WebmFixtures::file()->bytes, 0, strpos($bytes, "\x1F\x43\xB6\x75"));
                $cluster = "\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xE7\x81\x00".str_repeat("\xA3\x85\x81\x00\x00\x80\xFC", 400_000);
                // 15 bytes of block header and packet start (ID, 8-byte size, track, time, flags, TOC, count).
                $padding = 20 * 1024 * 1024 - strlen($head) - strlen($cluster) - 15;
                $packet = "\x81\x00\x00\x80".chr((31 << 3) | 3)."\x41".str_repeat("\xFF", $padding);

                return $head.$cluster."\xA3".WebmFixtures::size(strlen($packet), 8).$packet;
            }],
            'Ogg of 20 MiB of the smallest pages' => [function () {
                $pages = OggFixtures::page(0x02, 0, 5, 0, [19], OpusFrames::head(1)).OggFixtures::page(0, 0, 5, 1, [16], 'OpusTags'.pack('V2', 0, 0));
                for ($sequence = 2; strlen($pages) < 20 * 1024 * 1024 - 30; $sequence++) {
                    $pages .= OggFixtures::page(0, ($sequence - 1) * 120, 5, $sequence, [1], chr(16 << 3));
                }

                return $pages;
            }],
            'MP4 with a five-million-entry size table' => [function () {
                $count = 5_000_000;

                return Mp4Fixtures::nodes([
                    Mp4Fixtures::box('ftyp', 'M4A '.pack('N', 0)),
                    Mp4Fixtures::box('moov', [
                        Mp4Fixtures::box('mvhd', pack('N', 0).pack('N3', 0, 0, 1000).str_repeat("\0", 84)),
                        Mp4Fixtures::box('trak', [
                            Mp4Fixtures::box('tkhd', pack('N', 3).pack('N3', 0, 0, 1).str_repeat("\0", 72)),
                            Mp4Fixtures::box('mdia', [
                                Mp4Fixtures::box('mdhd', pack('N', 0).pack('N4', 0, 0, 48000, 0).pack('n2', 0, 0)),
                                Mp4Fixtures::box('hdlr', pack('N2', 0, 0).'soun'.str_repeat("\0", 13)),
                                Mp4Fixtures::box('minf', [Mp4Fixtures::box('stbl', [
                                    Mp4Fixtures::box('stsd', [pack('N2', 0, 1), self::aacEntry()]),
                                    Mp4Fixtures::box('stts', pack('N4', 0, 1, $count, 1)),
                                    Mp4Fixtures::box('stsc', pack('N5', 0, 1, 1, $count, 1)),
                                    Mp4Fixtures::box('stsz', pack('N3', 0, 0, $count).str_repeat(pack('N', 1), $count)),
                                    Mp4Fixtures::box('stco', pack('N3', 0, 1, 100)),
                                ])]),
                            ]),
                        ]),
                    ]),
                ])[0];
            }],
        ];
    }

    #[DataProvider('worstCases')]
    public function test_bounds_the_work_on_hostile_files(\Closure $make): void
    {
        $bytes = $make();
        self::assertLessThanOrEqual(20 * 1024 * 1024, strlen($bytes));
        $started = hrtime(true);
        $result = (new ChatVoiceChecker)->check($bytes);
        $seconds = (hrtime(true) - $started) / 1e9;
        self::assertSame('blocked', $result->state);
        self::assertLessThan(2.0, $seconds);
    }

    public function test_fast_ogg_crc_matches_the_bit_by_bit_definition(): void
    {
        $crc = new ReflectionMethod(OggVoiceParser::class, 'crc');
        $parser = new OggVoiceParser(new VoiceBytes(''));
        $random = new Randomizer(new Mt19937(3));
        foreach ([0, 1, 2, 27, 255, 4096, 65307] as $length) {
            $bytes = $length === 0 ? '' : $random->getBytes($length);
            self::assertSame(OggFixtures::crcBits($bytes), $crc->invoke($parser, $bytes), "length $length");
            self::assertSame(OggFixtures::crcBits($bytes), OggFixtures::crc($bytes), "table, length $length");
        }
        // The published check value of the POSIX cksum CRC (same polynomial, start at zero) is 0x765E7680
        // for "123456789"; this variant only leaves out its final inversion.
        self::assertSame(0x765E7680 ^ 0xFFFFFFFF, OggFixtures::crcBits('123456789'));
    }

    /** [packet, samples or null when invalid] for RFC 6716 section 3 framing. */
    public static function opusPackets(): array
    {
        $toc = fn (int $config, int $code) => chr(($config << 3) | $code);

        return [
            'R1 empty' => ['', null],
            'code 0, TOC only (DTX)' => [$toc(31, 0), 960],
            'code 0, 60 ms SILK' => [$toc(3, 0).'abc', 2880],
            'code 0, 2.5 ms CELT' => [$toc(16, 0).'abc', 120],
            'R2 frame over 1275 bytes' => [$toc(31, 0).str_repeat('a', 1276), null],
            'code 1, equal halves' => [$toc(31, 1).'abcd', 1920],
            'R3 code 1, odd payload' => [$toc(31, 1).'abc', null],
            'code 2, explicit first length' => [$toc(1, 2)."\x02abcde", 1920],
            'code 2, two-byte length' => [$toc(1, 2)."\xFC\x00".str_repeat('a', 252).'b', 1920],
            'R4 code 2, length past the end' => [$toc(1, 2)."\x09abc", null],
            'R4 code 2, one byte' => [$toc(1, 2), null],
            'code 3, three CBR frames' => [$toc(31, 3)."\x03abcdef", 2880],
            'R5 code 3, no frames' => [$toc(31, 3)."\x00", null],
            'R5 code 3, over 120 ms' => [$toc(3, 3)."\x03abc", null],
            'R6 code 3, CBR not a multiple' => [$toc(31, 3)."\x03abcd", null],
            'code 3, CBR with padding' => [$toc(31, 3)."\x42\x02abcd\0\0", 1920],
            'R6 code 3, padding past the end' => [$toc(31, 3)."\x42\x09ab", null],
            'code 3, VBR' => [$toc(31, 3)."\x83\x01\x02abcdef", 2880],
            'R7 code 3, VBR lengths past the end' => [$toc(31, 3)."\x83\x05\x05abc", null],
            'code 3, padding of 254 + 3' => [$toc(31, 3)."\x41\xFF\x03".'ab'.str_repeat("\0", 257), 960],
            'R6 code 3, padding lengths that cannot fit' => [$toc(31, 3)."\x41".str_repeat("\xFF", 4000), null],
        ];
    }

    #[DataProvider('opusPackets')]
    public function test_checks_opus_framing(string $packet, ?int $samples): void
    {
        try {
            self::assertSame($samples, OpusPacket::samples($packet, 0, strlen($packet)));
        } catch (VoiceFormatException) {
            self::assertNull($samples, 'A valid packet was refused.');
        }
    }

    public function test_opus_head_accepts_mono_and_stereo_family_zero_only(): void
    {
        self::assertSame(312, OpusPacket::head(OpusFrames::head(1)));
        self::assertSame(3840, OpusPacket::head(OpusFrames::head(2, 3840)));
        foreach ([OpusFrames::head(0), OpusFrames::head(3), OpusFrames::head(2, 312, 0), OpusFrames::head(2, 312, 16), OpusFrames::head(2, 312, 1, 1), substr(OpusFrames::head(), 0, 18), 'OpusHeaX'.substr(OpusFrames::head(), 8)] as $head) {
            try {
                OpusPacket::head($head);
                self::fail('Accepted '.bin2hex($head));
            } catch (VoiceFormatException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_rounds_lengths_up_and_caps_absurd_ones(): void
    {
        self::assertSame(20_000_000, VoiceFacts::nanoseconds(960, 48_000));
        self::assertSame(20_834, VoiceFacts::nanoseconds(1, 48_000));
        self::assertSame(0, VoiceFacts::nanoseconds(0, 48_000));
        self::assertSame(VoiceFacts::LONGEST_NS, VoiceFacts::nanoseconds(PHP_INT_MAX, 48_000));
        $limit = (new ChatVoiceChecker);
        self::assertSame('voice_too_long', $limit->check(WebmFixtures::file(['duration' => 120_501.0])->bytes)->errorCode);
        self::assertSame('ready', $limit->check(WebmFixtures::file(['duration' => 120_500.0])->bytes)->state);
    }

    private function assertBlocked(string $bytes, string $code, ?string $rule): void
    {
        $checker = new ChatVoiceChecker;
        $result = $checker->check($bytes);
        self::assertSame(['blocked', null, null, $code], [$result->state, $result->mime, $result->durationMs, $result->errorCode]);
        if ($rule === null) {
            return;
        }
        try {
            $checker->facts($bytes);
            self::fail("The file passed every rule; expected $rule.");
        } catch (VoiceFormatException $e) {
            self::assertSame($rule, $e->reason);
        }
    }

    /** An 'mp4a' sample entry with an AAC LC decoder configuration, for the hand-built worst case. */
    private static function aacEntry(): string
    {
        $file = Mp4Fixtures::file(['seconds' => 0.1]);
        $start = strpos($file->bytes, 'mp4a') - 4;

        return substr($file->bytes, $start, unpack('N', $file->bytes, $start)[1]);
    }
}
