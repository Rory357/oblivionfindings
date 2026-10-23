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

**Clean: no sign of copied code.** Checked by the checker session on 23 October 2026 with [../tools/token-similarity.mjs](../tools/token-similarity.mjs), a cross-language token comparison written for this check (greedy string tiling, as JPlag does).

- **Compared:** the 8 files in `app/Services/Chat/Voice/` and `ChatVoiceInspector.php` against 38 outside source files (78,511 lines), fetched on 23 October 2026 into a scratch folder outside the repository:
  - FFmpeg `e914b1cb0c51`: ffprobe, the Matroska, Ogg (Opus, Vorbis) and MOV/MP4 demuxers, `isom.c`, the Opus and Vorbis parsers, `mpeg4audio.c`, `crc.c`;
  - libogg `06a5e0262cdc` (`framing.c`), libopus `084bb7327ce9` (`opus.c`, `opus_decoder.c`), opusfile `6dfd29e7adb8`, libvorbis `1b75110b5a27` (`info.c`);
  - libwebm `a7c6662c68a9` (`mkvparser.cc`);
  - GPAC/mp4box `7cda8f465456` (the ISO box readers and `odf_code.c`);
  - music-metadata `bbd6e943c887` (its EBML, Matroska, Ogg, Opus, Vorbis and MP4 parsers).
- **Thresholds, every match reviewed by hand:**
  - structure (names and numbers abstracted): runs of 12 tokens or more, 306 runs;
  - literal names and numbers: runs of 6 tokens or more, 199 runs;
  - comment words: runs of 6 words or more, 1 run.
- **Result:**
  - The 126 longest structure runs (up to 104 tokens) are lists: constant declarations, number tables and string arrays.
  - The other 180 are idioms of 12 to 20 tokens, such as counted loops and "if this, throw". Each turns up in several unrelated outside files.
  - The longest literal run is 12 tokens (`for (i = 1; i < count; i++)`).
  - The only shared comment words are the standard's own name, "the ISO base media file format".
  - Shared names are the standards' element and box names, ffprobe's documented output fields and the RFCs' EBML IDs.
- **Score:** the best coverage of any new file by one outside file, lists left out, is 1.0–5.9 %. The one exception is `VoiceFacts.php` at 15.9 %: one 14-token idiom in an 88-token file.
  - Unrelated Chat services (`ChatUploads`, `ChatFileSafety`, `ChatSettings`, `ChatServiceHealth`, `SelfHostedCalling`) score 0–5.5 %.
  - A deliberate PHP port of libopus's packet parser, kept outside the repository and deleted afterwards, scores 41.6 % against `opus.c`, with 31-token runs.
  - The test code (`tests/Support/ChatVoice/`, `tests/Unit/Chat/Voice/`) scores 0–8.3 %. Its longest run that is not a list is 19 tokens.
- **Compared by eye:** the closest pairs, because token tools can miss a reordered port. They follow the same standard-defined steps with different structure:
  - Opus framing against libopus and FFmpeg;
  - the MPEG-4 descriptor length and ES descriptor flags against FFmpeg's `isom.c`, both written the way ISO/IEC 14496-1's syntax defines them;
  - EBML VINT reading and signed lacing against FFmpeg and libwebm (the offset 2^(7n−1)−1 is RFC 9559 §10.3.3's own formula);
  - `stsc` run checks against music-metadata's 22 October chapter-track code;
  - the Ogg CRC against libogg and FFmpeg. Oblivion's zero-run correction of PHP's `crc32` hash appears in neither.

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

## Checker review, first pass (23 October 2026)

By the checker session on branch `claude/voice-checker-signoff-49310d`. Outside source was opened only for the comparison above; none of it is in Oblivion, and the findings below are described in words.

**Verdict: not signed off yet.** No copying was found, and most of spec section 3 holds. Three findings must be fixed first, and F1 needs Stephan's decision.

### Spec section 3, item by item

| Item | Result |
|---|---|
| Recordings from Chrome, Edge, Firefox and Safari, 5 s and 119 s | **Chrome, Edge and Firefox: met.** The 12 files are distinct, made for Oblivion, and match the README; track numbers, Duration and the MP4 layout were read from the bytes. **Safari: missing** (known gap). The 119 s MP4 is generated, not recorded. |
| A hand-built file for each rule | **Met.** Every rule the spec lists has a case in each format it applies to: two tracks, video, wrong codec, too long, too short, cut inside an element, box or page, a size past the end, a bad CRC, unknown sizes, an empty file. |
| 10,000 mutations: never throw, never over 2 s, never ready when broken | **The test passes** (re-run: 44 s). **Not met for every input:** F2 makes `inspect()` throw, and F3 takes over 2 s. The random mutations reach neither. |
| The same results as ffprobe within 20 ms | **Met.** Re-run with ffprobe 8.1.1 configured: all 25 good files agree and both refuse Opus inside MP4, with none skipped. |
| Voice notes available without ffprobe; with it, both run and a disagreement blocks the file and is reported | **Met, except F2.** `configured()` is always true. The container injects Laravel's logger, so disagreements are logged (checked with `artisan tinker`). |

Re-run by the checker, through the machine-wide heavy-lock:
- `tests/Unit/Chat/Voice` and `ChatVoiceInspectorTest`, with `CHAT_VOICE_PROBE_BINARY` set to ffprobe 8.1.1: 178 passed (490 assertions), 71 s;
- `tests/Feature/Chat/ChatUploadsTest.php`: 13 passed (142 assertions), 551 s.

### Findings

- **F1: the Vorbis length comes from the page positions, not the audio. Blocks sign-off; Stephan decides.**
  - Decision 15 raises the granule length to a floor of (packets − 2) × half the short block. Real packets can each be half a long block: 8 times more at libvorbis's 256/2048, up to 128 times within the Vorbis I limits.
  - The probe was a real libvorbis file with its page positions scaled down to that floor and its CRCs recomputed, so it is still well formed:
    - 200 s of audio: the checker says ready, 25,000 ms;
    - 600 s of audio: ready, 75,000 ms;
    - ffmpeg decodes 3 min 15 s and 9 min 46 s.
  - ffprobe reads the real block sizes and calls the 200 s file too long. So with the second opinion the file is blocked; without it the 2-minute limit is bypassed.
  - Spec section 2 does say "last granule position / sample rate", but section 1 asks for the length from the audio itself, never guessed.
  - Options:
    - refuse Vorbis voice notes (no browser records Vorbis, and Oblivion's recorder never asks for it);
    - decode the setup header's mode table and count each packet's real block size;
    - accept Vorbis only when the second opinion is configured.
  - Ogg Opus and WebM are not affected. For MP4, the checker trusts container timing, as ffprobe does: an MP4 with every timing value divided by 8 fools both alike.
- **F2: `inspect()` can throw when ffprobe is configured. Must fix.**
  - In `secondOpinion()`, the output callback throws once ffprobe's output passes 2 MiB.
  - The `catch` block's stop reads the pipes again, so the callback throws a second time from inside the `catch`, and the exception leaves `inspect()`.
  - Any file with roughly 19,000 or more packets does this; the honest 600 s Vorbis file is one. `ChatUploads::scan()` then fails the request, and the upload stays "scanning" until its 180-second retry, which fails again.
  - The pattern was already in `faeb9435c`, so the builder did not introduce it.
- **F3: the 2-second limit can be passed. Must fix.**
  - In `OpusPacket::samples()`, the padding-length loop of a code-3 packet never checks the time budget. It also does not stop once the declared padding can no longer fit.
  - The probe was a 20 MiB WebM: 350,000–450,000 valid one-byte Opus blocks, then one packet whose padding-length bytes are all 0xFF to the end. It was blocked correctly, but after 2.07–2.27 s on this PC (measured twice).
  - One bad 20 MiB padding packet alone takes 1.1–1.2 s.
- **F4: minor.** In fragmented MP4, the sample-description indexes in `tfhd` and `trex` are skipped without being required to be 1, although `stsd` holds one entry. It does not change the length.

### Builder decisions

All 25 were reviewed against the RFCs, the fixture bytes and the tests. They are sound, except that decision 15 leads to F1 and decision 22's budget is not enforced inside the padding loop (F3). The ones asked about:

- **1, track number:** RFC 9559 section 10 says blocks name the TrackEntry's TrackNumber, and Firefox writes 2 (confirmed). The spec's "track 1" was a shorthand.
- **2, Duration 0 counts as absent:** RFC 9559 says Duration > 0, so this is a deliberate leniency for Firefox, which writes 0.0 (confirmed). It can only leave the block-based length in charge, never hide audio.
- **17, fragmented MP4:** the spec refuses only files without `moov`. Chrome writes `moov` with `mvex`, then 19 `moof`/`mdat` pairs (confirmed). Reading both layouts is right while Safari's layout is unknown.
- **14, Ogg Opus leaves out the pre-skip:** RFC 7845 section 4.3 defines the position as granule − pre-skip, so the checker is right, and ffprobe's 6.5 ms more is its own convention. With the second opinion, a pre-skip over about 960 samples would be blocked. Browsers write 312.
- **22, the 1.5-second budget:** a sensible margin under 2 s, but see F3.
- **The mutation test's "undecided" rule:** sound and narrow.
  - It excuses only a changed size whose new end lands exactly on an existing boundary or the file end, or that becomes unknown-size or 64-bit. Such files can really be valid.
  - Every other critical-byte change, every cut inside a declared extent and every Ogg change must still block.
- **Stricter than the RFCs, which is safe:**
  - decision 13 refuses a stream that starts at a later position (RFC 7845 section 4.5 allows one);
  - it caps end trimming at one packet, which the RFC makes a SHOULD.

### Spec section 4

- `voice_probe_binary` is optional, Service health names the checker, and the register is updated.
- `docs/chat-experience/IMPLEMENTATION-STATUS.md` still describes ffprobe as the voice inspector; it should be updated when C2.5 merges.

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
- **Vorbis (F1).** Stephan decides between refusing Vorbis voice notes, decoding the Vorbis mode table, and accepting Vorbis only with the ffprobe second opinion.
- **Sign-off.** Once F1 to F3 are fixed, a checker session re-runs the voice tests (with and without ffprobe) and the three probe files from the review: the understated Vorbis file, the long file with ffprobe configured, and the padding file. It then signs off REGISTER.md. The similarity check needs re-running only on the files that change.
