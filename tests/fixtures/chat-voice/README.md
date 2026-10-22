# Chat voice-note recordings

Fixtures for Oblivion's own voice-note checker (`app/Services/Chat/Voice/`, Chat plan item C2.5). They were recorded fresh for Oblivion on 23 October 2026. None comes from another project's test data.

## How they were made

- Each browser ran headless with a new, empty profile on Windows 11.
- A local page fed a synthetic voice into `MediaRecorder` through Web Audio: a sawtooth near 170 Hz with slowly wandering pitch and loudness. No microphone was opened and no person's voice is in any file.
- The page used Oblivion's own recorder settings from `resources/js/components/chat/chat-voice-recording.ts`:
  - the same format preference (`audio/webm;codecs=opus`, then `audio/ogg;codecs=opus`, then `audio/mp4`);
  - `start(250)`.
- The 5-second files use the recorder's 64 kbps. The 119-second files use 12 kbps to keep the repository small; the structure is the same.
- The "paused" files pause for 1.5 seconds after 2 seconds of recording.

| File | Browser | Asked for | Checker result | Earlier ffprobe-based check |
|---|---|---|---|---|
| `chrome-webm-opus-5s.webm` | Chrome 155 | default | ready, 4,860 ms | ready, 4,860 ms |
| `chrome-webm-opus-119s.webm` | Chrome 155 | default, 12 kbps | ready, 118,863 ms | ready, 118,863 ms |
| `chrome-webm-opus-paused.webm` | Chrome 155 | default, paused | ready, 4,860 ms | ready, 4,860 ms |
| `edge-webm-opus-5s.webm` | Edge 155 | default | ready, 4,860 ms | ready, 4,860 ms |
| `edge-webm-opus-119s.webm` | Edge 155 | default, 12 kbps | ready, 118,862 ms | ready, 118,863 ms |
| `firefox-webm-opus-5s.webm` | Firefox 143 | default | ready, 5,620 ms | ready, 5,620 ms |
| `firefox-webm-opus-119s.webm` | Firefox 143 | default, 12 kbps | ready, 120,180 ms | ready, 120,180 ms |
| `firefox-webm-opus-paused.webm` | Firefox 143 | default, paused | ready, 5,020 ms | ready, 5,020 ms |
| `firefox-ogg-opus-5s.ogg` | Firefox 143 | `audio/ogg;codecs=opus` | ready, 5,160 ms | ready, 5,167 ms |
| `firefox-ogg-opus-119s.ogg` | Firefox 143 | `audio/ogg;codecs=opus`, 12 kbps | ready, 119,040 ms | ready, 119,047 ms |
| `chrome-mp4-aac-5s.m4a` | Chrome 155 | `audio/mp4;codecs=mp4a.40.2` | ready, 4,757 ms | ready, 4,757 ms |
| `chrome-mp4-opus-5s.m4a` | Chrome 155 | `audio/mp4` (Chrome picks Opus) | blocked: Opus inside MP4 | blocked |

Notes on the table:

- The ffprobe column is the earlier `ChatVoiceInspector` run against ffprobe 8.1.1.
- Ogg differs by 6.5 ms: the checker leaves out the 312-sample pre-skip, as RFC 7845 section 4.3 says; ffprobe counts it.

## What is not covered by a recording

- **Safari** cannot run on this Windows machine, so there is no Safari recording. MP4 is covered by:
  - Chrome's real MP4 (fragmented);
  - plain and fragmented MP4 files that the tests build (`tests/Support/ChatVoice/Mp4Fixtures.php`).

  A 5-second and a 119-second Safari recording should be added from a Mac or an iPhone when one is available.
- The 119-second Chrome MP4 recording (1.5 MB) was checked locally (checker 118,912 ms, ffprobe 118,912 ms) but not committed. The tests build their own 119-second MP4 files instead.
