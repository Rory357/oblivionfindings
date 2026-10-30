# C2.5 voice-note checker: checker session findings (23 Oct 2026)

Checker session, worktree `.claude/worktrees/voice-checker-signoff-49310d`, branch `claude/voice-checker-signoff-49310d` (cut from `claude/eager-mahavira-c57cd4` at `39f868e17`). Nothing pushed or merged.

## Verdict

- **Similarity: clean.** No sign of copied code from FFmpeg/ffprobe, libogg, libopus, opusfile, libvorbis, libwebm, GPAC/mp4box or music-metadata (details in the provenance note).
- **Sign-off: held.** Three findings need the builder (one needs Stephan's decision first). REGISTER.md is NOT marked signed off.

All findings below are described in words only. No outside code is quoted.

## Tests re-run by the checker (heavy-lock)

- **Voice tests:** `tests/Unit/Chat/Voice` plus `ChatVoiceInspectorTest`, with ffprobe 8.1.1 configured.
  - 178 passed (490 assertions), none skipped.
  - All 25 agreement cases ran.
  - The mutation test took 44 s.
- **Upload tests:** `ChatUploadsTest`, 13 passed (142 assertions).
- So the findings below are gaps the suite does not reach, not failing tests.

## F1: Vorbis length comes from page positions, not the audio (blocks sign-off; Stephan's decision)

- **Rule:** `OggVoiceParser`, Vorbis length (provenance decision 15). The length is the last granule position divided by the sample rate. It is raised to a floor of (packets − 2) × half the short block.
  - The setup header is not decoded, so which block size each packet uses is unknown.
  - Each packet can really be half a long block. At libvorbis's usual 256/2048 sizes that is 8 times the floor; within the Vorbis I limits (64/8192) it is 128 times.
- **Input:** a real libvorbis 48 kHz mono file (ffmpeg 8.1.1, synthetic tone). I scaled every audio page's granule position down to the checker's floor and recomputed every page CRC.
  - The result is still a well-formed Ogg stream: one serial, consecutive pages, an end-of-stream page, good CRCs and rising positions.
- **What goes wrong:**
  - 200 s of audio: the checker says ready, audio/ogg, 25,000 ms. ffmpeg decodes 3 min 15 s.
  - 600 s of audio: the checker says ready, 75,000 ms. ffmpeg decodes 9 min 46 s.
  - ffprobe reads the block sizes from the setup header and says `voice_too_long` for the 200 s file. With the second opinion configured the file is blocked; without it (the new default) the 2-minute limit is bypassed.
  - The old ffprobe-only check did not have this gap.
- **Spec:** §2 literally says "last granule position / sample rate" for Vorbis, so the builder followed the spec. But §1 asks for the length "worked out from the audio itself", never guessing, and the same answers ffprobe gives.
- **Options (Stephan):**
  1. Refuse Vorbis voice notes. No browser records Vorbis, and Oblivion's recorder asks only for WebM/Opus, Ogg/Opus or MP4 (`chat-voice-recording.ts`). This is the simplest option; I recommend it.
  2. Decode the Vorbis setup header far enough to read its mode table, then count each packet's real block size, as ffprobe does.
  3. Accept Vorbis only when the ffprobe second opinion is configured.
- **Not affected:**
  - Ogg Opus: every page position must equal the running sample count from each packet's TOC byte.
  - WebM: the length includes the sum of every packet.
- **MP4/AAC:** container timing is trusted, as ffprobe does. Dividing every `stts` delta and header duration by 8 fools both, and ffmpeg's own pipeline also plays a 25 s timeline, so this is not a regression.

## F2: `inspect()` can throw when ffprobe is configured (should fix)

- **Rule:** `ChatVoiceInspector::secondOpinion()`.
  - The output callback throws once ffprobe's output passes 2 MiB.
  - The catch block calls `stop(0)` on the process. Stopping reads the pipes again, so the callback runs again and throws again, now from inside the catch.
  - The exception leaves `secondOpinion()` and `inspect()`. The process destructor can raise it once more at shutdown.
- **Input:** any long file with `CHAT_VOICE_PROBE_BINARY` set. The honest 600 s libvorbis file does it. So would roughly 19,000 or more packets, which is a WebM/Opus file over about 6 minutes.
- **What goes wrong:**
  - `inspect()` throws a RuntimeException ("Voice inspection output limit exceeded.") instead of returning blocked `voice_too_long`.
  - `ChatUploads::scan()` calls `inspect()` with no catch, so the request fails. The upload stays "scanning" until the 180 s retry window opens, and then fails the same way.
- **History:** the same callback-and-stop pattern was already in `faeb9435c`, so the builder did not introduce it. It is in the code under review, and spec §3 says `inspect()` never throws. The mutation test runs without ffprobe, so it cannot see this.
- **Direction (builder's choice):**
  - Do not throw from the output callback: note that the limit was hit and stop reading. Or guard the stop call.
  - Then treat "output too large" as blocked or unavailable.
  - Add a test with ffprobe configured on a long file.

## F3: the 2-second limit can be passed through the Opus padding loop (should fix)

- **Rule:** `OpusPacket::samples()` for a code-3 packet with the padding flag set.
  - The padding-length bytes are read one at a time.
  - There is no time-budget check inside the loop.
  - There is no early stop once the declared padding can no longer fit in the bytes that remain.
- **Input:** a 20 MiB WebM file. It has a valid header, then 350,000–450,000 one-byte Opus SimpleBlocks (each a valid TOC-only packet), then one SimpleBlock whose Opus code-3 packet has padding-length bytes of 0xFF all the way to the end.
- **What goes wrong:**
  - `inspect()` takes 2.06–2.26 s on this PC (measured while other sessions ran tests). Spec §2 and §3 say at most 2 s, then stop and block.
  - One bad 20 MiB padding packet alone takes 1.1–1.2 s.
  - The file is blocked correctly, just late. A slower server would overrun by more.
- **Direction:**
  - Stop as soon as the padding already declared is larger than what is left of the packet. Each 0xFF adds 254 bytes, so the loop would end after about 1/255 of the packet.
  - Or count padding bytes against the budget.
  - Add this file to the hostile-file tests.

## F4: minor, not blocking

- In fragmented MP4, the `tfhd` sample-description index (flag 0x02) and the `trex` default sample-description index are skipped without being required to be 1, although `stsd` holds exactly one entry.
- A file that points at a sample entry that does not exist still passes. It does not change the length. It could be tightened at the same time as the other fixes.

## Builder decisions: judged sound

- **1, Firefox track number 2:** RFC 9559 §10 says blocks name the TrackEntry's TrackNumber. Confirmed in the fixture bytes. The spec's "track 1" was a simplification.
- **2, Duration 0 counts as absent:** RFC 9559 says Duration > 0, so this is a deliberate leniency. It can only let the block-based length stand, never hide audio. Firefox writes 0.0, confirmed in the bytes.
- **17, fragmented MP4:** the spec only refuses files without `moov`. Chrome writes `moov` with `mvex` and then 19 `moof`/`mdat` pairs (confirmed). Supporting both layouts is right while Safari's layout is unknown.
- **14, Ogg Opus length leaves out the pre-skip:** RFC 7845 §4.3 says playback position = granule − pre-skip, so this is correct. ffprobe counts the 312-sample pre-skip, a 6.5 ms difference. With the second opinion, a stream whose pre-skip is over about 960 samples would be blocked. Acceptable, because browsers write 312.
- **22, the 1.5 s budget:** a sensible margin, but not enforced inside the padding loop (F3).
- **Mutation test "undecided" rule:** sound and narrow.
  - It excuses only a changed size whose new end lands exactly on an existing boundary or the file end, or that becomes unknown-size or 64-bit. Such files can really be valid.
  - Every other critical-byte change, every cut inside a declared extent and every Ogg change must still block.
  - Recorded WebM and MP4 fixtures carry no structure map, so for them the test checks only that nothing throws, nothing hangs and the type stays the same.
- **Known gap:** no real Safari recording (5 s and 119 s).

## Recheck after `98ed39a95` (23 Oct 2026)

**F1 to F4 are fixed.** Probes re-run on the checker branch with `98ed39a95` merged:

- Every Vorbis file is blocked, with or without ffprobe.
- With ffprobe configured, neither the honest 600 s Vorbis file nor a real 10-minute libopus WebM throws. The WebM comes back `voice_too_long`.
- The padding packet takes 0.01–0.03 s, and the combined file stops at the 1.5 s budget (about 1.51 s).
- Similarity on the changed files is still 1–6 %.

**Sign-off is still held** for two MP4 problems that have been there since `39f868e17`. The first pass missed them.

### F5: an MP4 can still pass the 2-second limit (must fix)

- **Rule:** at the end of `Mp4VoiceParser::parse()`, each sample range is checked against the `mdat` list by scanning the list from the start. The budget is checked only once per 256 ranges.
- **Input:** a 2.67 MiB file with a valid `moov`. Its 100,000 one-byte chunks all sit in the last of 300,000 top-level `mdat` boxes.
- **What goes wrong:** blocked after 4.50 s, then 5.09 s on a second run. At the part limit the budget trips earlier (about 1.6 s), so the worst case is in the middle.
- **Direction:** `mdat` boxes arrive in file order and cannot overlap, so find each range by halving the list; or count the inner scan against the budget.

### F6: an MP4 can make PHP use about 280 MiB (fix, or Stephan confirms the server limit)

- **Rule:** `children()` collects every child box of a container into a list before walking it. The `mdat` and range lists also grow with the file.
- **Input:** a valid 6.9 MiB MP4 whose `moov` holds 900,000 empty `free` boxes.
- **What goes wrong:** it comes back ready and peaks at 280 MiB. The F5 file peaks at 120–240 MiB.
  - Herd's limit is 1024M, but the production `memory_limit` is not in the repository.
  - If it is below about 300 MiB, PHP stops with a fatal out-of-memory error that no `catch` stops. The upload request would fail and the upload would stay "scanning".
  - WebM and Ogg keep no lists that grow with the file.
- **Direction:** walk boxes one at a time and keep the lists compact; or run the check in a child process with its own memory cap, as `HubPdfText` already does for PDFs.
