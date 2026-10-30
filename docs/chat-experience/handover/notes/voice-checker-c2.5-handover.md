# C2.5 voice-note checker: hand-over (builder session, 23 Oct 2026)

- **Where:** branch `claude/eager-mahavira-c57cd4`, worktree `.claude/worktrees/eager-mahavira-c57cd4`. The branch is a merge of `claude/chat-module-audit-plan-704fd7` at its tip `016e3e484`.
  - The only conflict was `routes/console.php`. I kept main's referral schedules and the branch's gated chat sweeps.
  - Nothing is pushed or merged.
- **What it does:** `App\Services\Chat\Voice\ChatVoiceChecker` decides every voice note itself. `ChatVoiceInspector::inspect()` keeps its signature and results.
  - `configured()` is now always true, so `voice_notes_available` is true without ffprobe.
  - ffprobe, when configured, is a second opinion (`secondOpinionConfigured()`, `secondOpinion()`, `agree()`). A disagreement blocks the file and logs a warning that holds verdicts only.
- **Files outside the voice folder that I touched:**
  - `ChatServiceHealth::uploads()`: the voice sentence only.
  - `config/chat.php`: the `voice_probe_binary` comment only.
  - `ChatServiceHealthTest`: one assertion.
  - `ChatUploadsTest`: one new test; the "unavailable" test now mocks the inspector.
  - I did not touch `ChatRealtime`, push files or their tests.
- **Provenance and decisions:** `docs/chat-experience/clean-room/provenance/voice-checker.md` lists 25 decisions and the test evidence. I updated the register and the README status.
  - I did not edit `2026-10-22-rebuild-plan.html` or `IMPLEMENTATION-STATUS.md`. Update the plan's C2.5 row there if you keep it.
- **Fixtures:** I recorded fresh fixtures in headless Chrome 155, Edge 155 and Firefox 143. They are in `tests/fixtures/chat-voice/` (about 930 KB) with a README.
  - There is no Safari recording, because this machine can't run Safari. That is an open item for a Mac or an iPhone.
- **Results with no ffprobe needed:**
  - 124 checker tests;
  - 10,000 mutations in about 50 s, slowest file 0.03 s;
  - 27 inspector tests.
- **ffprobe agreement:** 25 good files agreed within 20 ms. The test needs `CHAT_VOICE_PROBE_BINARY` set to ffprobe 8.1.1:
  - `C:\Users\steph\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-8.1.1-full_build\bin\ffprobe.exe`
- **Checker session (needed for sign-off):** the README's step 3. It may read FFmpeg/ffprobe source to compare with `app/Services/Chat/Voice/*.php`, then sign off REGISTER.md. It should also re-run `tests/Unit/Chat/Voice` and check the spec's section 3 against the provenance note.

## Update, 23 Oct (builder): checker findings F1–F4 addressed

- **F1:** Stephan chose to refuse Vorbis. `OggVoiceParser` now refuses it with `ogg_vorbis`, and `ChatVoiceInspector::analyse()` refuses ffprobe's Vorbis verdicts too. The spec's section 1 carries a dated note.
- **F2:** the `secondOpinion()` output callback never throws. Output past 2 MiB is dropped and the file is refused; the stop is guarded.
- **F3:** the Opus padding loop stops once the padding can't fit. The hostile 20 MiB packet took 1.1–1.2 s and now takes 0.01 s; the checker's file is a new worst-case test.
- **F4:** `trex` and `tfhd` must name sample entry 1.
- **Not ours:** `tests/Unit/Chat/ChatBrowserPushTransportTest` fails 24 tests on this PC. PHP can't create an EC key (`openssl_pkey_new` fails because `OPENSSL_CONF` is unset). This branch doesn't touch those files; for the push-sender session.
