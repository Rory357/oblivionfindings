# Voice-note checker provenance

- **Replaces:** ffprobe (FFmpeg) for chat voice notes. ffprobe was always a separate program, never linked; version 8.1.1 is installed locally. ffprobe is now an optional second opinion.
- **Spec:** [../specs/voice-checker.md](../specs/voice-checker.md), written by the spec-writer session, 23 October 2026.
- **Built by:** the builder session on branch `claude/eager-mahavira-c57cd4`, 23 October 2026.
  - Code: `app/Services/Chat/Voice/` (`ChatVoiceChecker`, `WebmVoiceParser`, `OggVoiceParser`, `Mp4VoiceParser`, `OpusPacket`, `VoiceBytes`, `VoiceFacts`, `VoiceFormatException`), wired into `App\Services\Chat\ChatVoiceInspector`.
  - Tests: `tests/Unit/Chat/Voice/` and `tests/Support/ChatVoice/`.
  - Fixtures: `tests/fixtures/chat-voice/`, with their own README.

## Sources read while building

- **RFCs from rfc-editor.org.** A fetch helper was told to open those pages and nothing else; it answered specific questions with short quotes:
  - RFC 8794 (EBML);
  - RFC 9559 (Matroska);
  - RFC 3533 (Ogg);
  - RFC 7845 (Ogg Opus);
  - RFC 6716 section 3 only (Opus packet framing).
- **From the builder's own knowledge of the published standards, not re-read in this session:**
  - the ISO base media file format box structure (ISO/IEC 14496-12);
  - the MPEG-4 descriptor syntax in `esds` and the start of the AudioSpecificConfig (ISO/IEC 14496-1 and -3);
  - QuickTime's version 1 and 2 sound descriptions;
  - the Vorbis I identification, comment and setup header types.
- **PHP manual:** `hash('crc32')`, `unpack`, `hrtime`.

## Outside code consulted

None. No source of FFmpeg, ffprobe, mediainfo, music-metadata, mp4box, libwebm, libogg, libopus, opus-tools or any other media parser was opened, searched or fetched, in any language.

## Outside programs run as black boxes

- **Headless Chrome 155, Edge 155 and Firefox 143** recorded the fixtures from a synthetic Web Audio tone. No microphone was used.
- **ffprobe 8.1.1**, through the existing `ChatVoiceInspector` code path, gave the reference results.
- **ffmpeg 8.1.1 with libvorbis** encoded three local-only Vorbis files (5 s, 119 s and 3.3 s) for one comparison.
  - Before Vorbis was refused, the checker read 5,000, 119,000 and 3,300 ms, and ffprobe 5,003, 119,003 and 3,312 ms. These files confirmed the page and CRC handling on a third muxer.
  - Since the decision, all three are blocked (`ogg_vorbis`).
  - The files were not committed.
- **Scratch dump scripts** read their output as raw bytes. They were written in this session from the RFCs and are not committed.

## New outside packages

None.

## Similarity check

Still to do, by the checker session.

## Decisions taken

These are cases where the spec left room or real files differed from it.

**WebM / Matroska**
1. **Track number.** Firefox writes track number 2, not 1. Blocks must use the single TrackEntry's declared number (the spec said "track 1").
2. **Duration 0.** Firefox writes `Duration` 0 as a placeholder, although RFC 9559 says Duration must be above 0. A value of 0 counts as absent. Negative, infinite or NaN values are refused.
3. **Unknown sizes.** Only the Segment and its Cluster children may have an unknown size. An unknown-size Cluster ends at the next Segment-level element (RFC 8794 section 6.2).
4. **Length.** The length is the largest of three numbers: the block span (the spec's rule), the sum of all packets, and the declared Duration. The sum was added because the earlier ffprobe-based check counted it, and it catches many packets stacked at one time.
5. **Block order.** Blocks may not go back in time. All ten real WebM recordings keep their order, including after pause and resume.
6. **Unknown elements.** They are refused in the EBML header, at Segment level and inside Clusters, where an unknown element cannot be told apart from damage. They are skipped inside Info, TrackEntry, Audio and BlockGroup, which is metadata that may grow.
7. **Refused elements.**
   - Attachments: ffprobe lists them as extra streams.
   - ContentEncodings: compressed or encrypted frames.
   - EncryptedBlock.
   - Video.
   - A TrackTimestampScale other than 1.
8. **Order of top-level elements.** Info and Tracks must come before the first Cluster, as every browser writes them.

**Opus**
9. **Channel mapping.** Only family 0 (mono or stereo, one stream) is accepted, which is what browsers record. Other families change how every packet is framed. OpusHead versions 1 to 15 are accepted (RFC 7845 section 5.1).
10. **Packet rules.** Every packet is checked against requirements R1 to R7 of RFC 6716 section 3.4. Its length comes from the TOC byte and the frame count.

**Ogg**
11. **Required end page.** The stream must end with its end-of-stream page. Without that rule, a file cut between pages cannot be told from a whole one.
12. **Page rules.**
    - There must be one logical stream.
    - Page sequence numbers must be consecutive.
    - The continued flag must match the previous page.
    - Granule -1 must appear exactly on pages where no packet ends.
    - Header pages are placed and positioned as RFC 7845 sections 3 and 4 say.
13. **Granule positions.** Each audio page's position must equal the running sample count (RFC 7845 section 4). The last page may trim no more than its last packet and may not end before the pre-skip. A stream that starts at a later position (cut from a live stream) is therefore refused; browsers never write one.
14. **Opus length.** The length is (last position − pre-skip) / 48,000, as the spec says. ffprobe counts the pre-skip, so the Firefox files differ from it by 6.5 ms.

    An encoder with a long pre-skip would differ by more than 20 ms (3,840 samples is 80 ms). With ffprobe configured as a second opinion, such a file would be blocked. No browser writes one.
15. **Vorbis is refused** (Stephan's decision, 23 October 2026, after checker finding F1). A Vorbis packet's length depends on block sizes held in its setup header, which the checker does not decode. A file could therefore set its page positions far below its real audio and pass the 2-minute limit: 3 min 15 s of audio passed as 25 s. No browser records Vorbis, and Oblivion's recorder never asks for it, so Ogg now carries Opus only (reason `ogg_vorbis`). The ffprobe interpretation (`analyse()`) refuses Vorbis too, so the two checks agree. Voice notes already stored still play.
16. **The CRC.** RFC 3533 names the polynomial but not the start value, the bit order or a final XOR.
    - Chosen: start at 0, most significant bit first, no final XOR.
    - Confirmed: every page CRC matches in the fresh Firefox recordings and in the libvorbis files.
    - Computing it: the checker uses PHP's built-in `crc32` hash with a zero-run correction. The tests compare that against a bit-by-bit version.

**MP4**
17. **Fragmented files.** Chrome's MediaRecorder writes fragmented MP4: a `moov` with `mvex`, then `moof` and `mdat` pairs. These are read through `trex`, `tfhd`, `tfdt` and `trun`. A file without `moov` is refused, as the spec says.
18. **Length.** The length is the largest of:
    - the `mdhd` duration;
    - the `stts` total plus the fragment totals;
    - the fragment decode span;
    - the `mehd` duration.
19. **Sample data.**
    - The bytes of every chunk and every track run must lie inside an `mdat`.
    - Sample tables and track runs must fill their boxes exactly.
    - The counts in `stts`, `stsz`, `stsc` and `stco` must agree.
20. **Mandatory boxes.** `minf` must contain `smhd` and `dinf`/`dref`, which ISO requires, and their sizes must nest exactly. The mutation test found that without this rule, changed sizes inside these unchecked boxes could pass.
21. **Codec.**
    - `mp4a` with `esds`;
    - object type 0x40 and stream type 5;
    - AudioSpecificConfig object type 2, 5 or 29 (AAC LC, HE-AAC, HE-AAC v2);
    - frequency index 12 or below, or an explicit rate;
    - channel configuration 1 to 7.

    QuickTime sound descriptions v1 and v2, and `esds` inside `wave`, are accepted for Safari's sake but untested on real Safari files. `stz2`, `sinf`, `senc` and `enca` are refused.

**Limits and the inspector**
22. **Limits.**
    - 1,000,000 parts per file. This counts elements, boxes, pages, packets and table entries, so it is stricter than the spec, which counts only elements, boxes and pages.
    - A 1.5-second work budget, leaving room under the spec's 2 seconds for reading the file.
    - 20 MiB per file.
    - Every read is bounds-checked.
23. **Results of `inspect()`.** A file that cannot be read from disk is `unavailable` with `voice_check_failed`; a missing file was `blocked` before. An empty file, or one over 20 MiB, is `blocked`.
24. **The ffprobe second opinion.**
    - Both checks must agree on the result and the MIME type, with lengths within 20 ms.
    - A disagreement blocks the file (`voice_too_long` if ffprobe found it too long, otherwise `voice_file_invalid`). It is logged as a warning that holds verdicts only: no path, name or content.
    - If ffprobe cannot run, the upload is left `unavailable` for a retry.
    - If ffprobe writes more than 2 MiB (far more packets than a browser's two-minute recording has), the output past the limit is dropped and the second opinion refuses the file. The output callback never throws, and stopping the process is guarded: before checker finding F2, a long file made `inspect()` throw.
    - When both agree, the longer length is kept.
25. **Availability.** `configured()` now always answers yes, so `voice_notes_available` is true without ffprobe. `secondOpinionConfigured()` reports ffprobe.

## Test evidence (23 October 2026, after the checker's findings were fixed)

The last run, through the heavy-lock with ffprobe 8.1.1 configured, passed 203 tests (680 assertions): the voice unit tests, `ChatVoiceInspectorTest`, `ChatServiceHealthTest` and `ChatUploadsTest`.

- **Checker tests:** `ChatVoiceCheckerTest` has 124 cases, all passing:
  - 11 browser recordings plus the Opus-in-MP4 refusal;
  - 14 generated shapes at 5 s and 119 s (WebM in three styles, Ogg Opus, and MP4 in three layouts);
  - 67 hand-built bad files, one per rule, each asserting the rule that failed (Vorbis included);
  - Opus R1 to R7 cases;
  - the parts and time limits;
  - four hostile 20 MiB files, each blocked in under 2 s (one is the checker's padding file from F3);
  - CRC equality.
- **Mutation test:** `ChatVoiceMutationTest` runs 10,000 cases with a fixed seed, in about 50 s. None threw, the slowest file took 0.03 s, and no provably broken file passed. A file counts as provably broken when:
  - any Ogg file is changed (CRCs plus the end page);
  - it is cut inside a declared-size element, box or page;
  - a critical byte changes (sizes, required IDs and types, fixed values).

  A changed size that lands exactly on another element boundary is left undecided. One such case was a valid file: a SimpleBlock grew to swallow its neighbour and still held a valid Opus packet.
- **Agreement test:** `ChatVoiceAgreementTest` has 27 tests.
  - It compared the checker with ffprobe 8.1.1 on 25 good files, recordings and generated; every file agreed within 20 ms.
  - Both checks refused Opus in MP4.
  - A 10-minute file was blocked as `voice_too_long` without throwing.
  - Without `CHAT_VOICE_PROBE_BINARY` the test is skipped.
- **Inspector and feature tests:**
  - `ChatVoiceInspectorTest` has 28 tests: every agreement case, too much ffprobe output, and a failing stop.
  - `ChatUploadsTest` now uploads a real Chrome recording with no ffprobe (ready, 4,860 ms, ignoring the browser's claimed length) and a cut copy (blocked).

## Checker findings and fixes (23 October 2026)

The checker session (`claude/voice-checker-signoff-49310d`) found the code clean of copied outside code and reported four findings in words only. The builder did not read its source comparisons.

- **F1, Vorbis length could be understated.** Decided by Stephan: Vorbis is refused (decision 15). Hand-built and libvorbis Vorbis files are blocked with `ogg_vorbis`.
- **F2, `inspect()` could throw with ffprobe configured.** Fixed in `secondOpinion()` (decision 24).
  - New test: a fake ffprobe writes 3 MiB, and another fails its clean-up stop; both return a verdict.
  - New agreement test, run with ffprobe: a 10-minute WebM is blocked as `voice_too_long` without throwing.
- **F3, the Opus padding loop could pass the 2-second limit.** Fixed in `OpusPacket::samples()`: the loop stops as soon as the declared padding can no longer fit in what is left of the packet.
  - A 20 MiB packet of 0xFF padding bytes took 1.1–1.2 s; it now takes 0.01 s.
  - The checker's hostile file (400,000 small blocks, then that packet) is a new worst-case test.
- **F4, fragment sample-description indexes.** `trex` and `tfhd` must now name sample entry 1, the only one in `stsd`. New test: `mp4_sample_entries`.

## Open questions

- **Safari.** Real Safari recordings (5 s and 119 s) are still needed from a Mac or an iPhone. The MP4 parser has only seen Chrome's fragmented MP4 and generated files.
- **Disagreement reporting.** Should a disagreement with ffprobe also show in Settings › Chat › Service health, or get its own error code? Today it is logged, and the person sees "could not be verified".
- **Unused error code.** `voice_check_unavailable` is no longer produced. The browser still understands it, and it is harmless.
- **Similarity check.** The checker session runs it and signs off REGISTER.md.
