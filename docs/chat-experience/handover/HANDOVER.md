# Chat rebuild: hand-over to Rory (30 Oct 2026)

Stephan's Chat sessions built the first stages of the Chat rebuild between 22 and 23 Oct 2026. He paused them on 23 Oct. Rory now carries this work on.

**Where things stand:**
- Nothing from this work is on `main`. Stephan chose to hand everything over as GitHub branches; Rory tests each one and merges it when ready.
- `main` deploys to the oblivionfindings.com test server within a few minutes of a push. The data there is fictional demo data.
- The plan, the drawings and all decisions are in the repo. Every working note that lived only on Stephan's PC is in this folder.

## Start here

1. `git fetch origin`, then check out **`chat/handover-rory`**.
   - It holds the base Chat branch (`claude/chat-module-audit-plan-704fd7`) plus this folder.
2. Open the plan in a browser: `docs/chat-experience/2026-10-22-rebuild-plan.html`. It has the stages, every task ID (C0.x, C1.x, C2.x…), a Progress section and all decisions.
3. Read the specs:
   - `docs/chat-experience/clean-room/README.md`: how the clean room works.
   - `docs/chat-experience/clean-room/REGISTER.md`: which outside engines are being replaced, and their sign-off state.
   - `docs/chat-experience/clean-room/specs/*.md`: one spec per engine.
4. Read the notes in `notes/` (below) for whichever piece you pick up first.

## The branches (all on GitHub, none merged)

| # | Branch | What it is | State | What's left |
|---|---|---|---|---|
| 1 | `claude/chat-module-audit-plan-704fd7` | **The base.** Stage 0 and stage 1 engine fixes, plus the docs (plan, v3 boards, clean-room rules, specs, DESIGN.md chat rules). Composer renamed to `oblivion/care`. | Tested 23 Oct: full Chat suite 430 passed, tsc clean. | About 330 commits behind `main`. Merge `main` in, re-run the Chat tests, then merge. |
| 2 | `claude/reverent-wu-0bdbe2` | **C2.3 push sender.** Oblivion's own Web Push sender in `app/Services/Push/`. Removes minishlink/web-push, web-token/jwt-library, spomky base64url and pki-framework. | **Signed off** by the checker (`39048778d`). 287 push/chat tests passed at `5de20c477`. | Manual browser test on the test server (Chrome, Edge, Firefox, Safari 16.4+). Server needs VAPID keys and `CHAT_BROWSER_PUSH_ENABLED`. One stale line in `IMPLEMENTATION-STATUS.md` (around line 470). See `notes/push-sender-*.md`. |
| 3 | `claude/chat-investigator-grants-export-b6e2cf` | **Investigator follow-up.** One case can grant several conversations (each logged separately), plus a watermarked, logged PDF export of granted conversations that is blocked after expiry. Built on `main` at `d5d279a79`. | Committed `e800571ff`. Its tests ran on 22 Oct, but the session hit its usage limit before recording the results. | Re-run `tests/Feature/Chat/ChatInvestigationCaseExportTest.php`, `ChatInvestigationGovernanceTest.php`, vitest `resources/js/components/chat-governance/investigation-case-export.test.tsx` + `chat-governance.test.tsx`, and tsc. Plan item C1.12 waits for this. |
| 4 | `claude/eager-mahavira-c57cd4` | **C2.5 voice-note checker (builder).** Oblivion decides every voice note itself; ffprobe, when configured, is only a second opinion. | Checker findings F1–F4 fixed (`98ed39a95`). The fix for F5 + F6 is a **WIP commit `68f707db4`, not yet tested with Pest**. | Run the voice tests, both with and without ffprobe, then hand to a checker. See `notes/voice-checker-c2.5-*.md`. |
| 5 | `claude/voice-checker-signoff-49310d` | **C2.5 checker's record** (a different session from the builder). | Sign-off **held** for F5 and F6 (MP4 time and memory). Record `2847eb6cb`. | When the F5/F6 fix passes: re-run the probes in `probes/voice-checker-c2.5/`, then sign REGISTER.md. |
| 6 | `claude/eloquent-shamir-b83b60` | **C2.1 / C2.2 / C2.4 Oblivion Live.** Chat's own WebSocket gateway (`live/`, Node built-ins only), publisher, signer, targeted events and browser client. | Pest batch 232 passed after the fixture fix `f46c5c7d5`. tsc 0 errors. 68 gateway tests (`npm run test:live`). | In order: re-run `tests/Feature/Chat/OblivionLiveTest.php`, production build, two-browser end-to-end test, bring in branch 7, then the clean-room checker. See `notes/oblivion-live-c2.1-*.md`. |
| 7 | `claude/oblivion-live-removal-wip` | Draft that removes Reverb and pusher-js once Oblivion Live is in. | `80022d227`. **Untested.** | Step 4 of the Oblivion Live resume note. |
| 8 | `chat/merge-attempt-2026-10-23` | Reference only: an old local attempt that merged 1 and 2 onto `main` at `3ceefff70`. | Pre-merge tests never ran. | Don't merge it. Use it only to see how the conflicts were resolved then (for example `routes/console.php` and composer files). |
| 9 | `chat/handover-rory` | Branch 1 plus this `handover/` folder. | — | Start here. |

Branches 2, 4, 5 and 6 each include branch 1 (merged in at an earlier tip), plus some referral commits that are already on `main`.

## Suggested order

1. **Base (branch 1):** merge `main` in, re-run the Chat tests, merge to `main`. Every Chat flag in `config/chat.php` defaults to OFF.
2. **Push sender (branch 2):** merge, run the push tests, then do the manual browser test.
3. **Investigator (branch 3):** re-run its tests, then merge.
4. **Voice checker (branches 4 + 5):** test the F5/F6 fix, then get checker sign-off, then merge.
5. **Oblivion Live (branches 6 + 7):** finish the resume steps, then checker sign-off, then the Reverb/pusher-js removal, then merge.
6. **After that, the plan's later stages:** messaging, calls, Oblivion-only features, family, surfaces, switch-over.

## Rules that must hold

### Clean room (Stephan's decision D1)

- Oblivion's chat engines are written from the specs, never from outside code. Live updates, calls, push and the voice checker are all replaced; the platform and ClamAV stay.
- The **builder** and the **checker** must be different sessions.
- An engine merges only after the checker signs it off in `REGISTER.md`.
- Checkers may read the library source to compare against, but they keep it **out of the repo**. Findings are written in words only, never as quoted code.
- The token-similarity tools are:
  - `docs/chat-experience/clean-room/tools/token-similarity.mjs` (on branch 5, `claude/voice-checker-signoff-49310d`);
  - `tools/push-sender-similarity.php` (in this folder).

### Stephan's decisions (22–23 Oct)

Also recorded in the spec and the plan.

**Engines**
- **D1:** replace the chat engines clean-room.
- **D2:** Oblivion Live, Oblivion's own gateway.

**Calls**
- **D3 / D5:** Oblivion's own WebRTC calls with video:
  - voice calls up to 8 people;
  - video calls up to 4 people;
  - screen share;
  - family 1:1 video is off by default for each Site.
- **D4:** coturn runs as a separate server.
- **D13:** calls start with one click.
- **D15:** Answer is the primary button; Decline is destructive red.

**Messaging**
- **D6:** Oblivion stickers plus an organisation library. No outside GIF service.
- **D7:** outside link previews are ON by default and an admin can switch them off. Previews are fetched server-side, with an SSRF guard.
- **D8:** staff see per-person read status; families see "Seen by care team".
- **D9:** a message can be edited for 15 minutes; delete-for-everyone works for 1 hour.

**Governance and features**
- **D10:** AI helpers are planned, and off by default.
- **D11:** a second approver is a setting. The default is self-grant plus an audit log entry.
- **D12:** drawing ships with signature, and with app-wide approval.

**Layout**
- **D14:** `/chat` is full height with no header band. This is a documented exception in DESIGN.md.

**Later decisions**
- **Private groups:** when a member moves Site, the group keeps them and shows their name to everyone. Nobody may add someone they can't see.
- **Push:** Edge is allowed (`.notify.windows.com` is in the default host list). Any server that sets `CHAT_BROWSER_PUSH_HOSTS` must add it. TTL is 12 h, urgency high.
- **Voice:** refuse Vorbis voice notes.
- **Investigator:**
  - investigators get their own page;
  - grants are per conversation and time-limited;
  - several conversations can be granted under one case;
  - export is a watermarked PDF.

### General

- **Desktop web app only.** No phone layouts, but nothing may break when the window is narrow or zoomed.
- **Never loosen safety or privacy rules.** Ask Stephan if a change would.
- **Design:** read `DESIGN.md` before any UI work. Use semantic tokens only, and reuse the primitives in `resources/js/components/ui/`.
- **Feature flags** (`CHAT_SHARED_EXPERIENCE`, realtime, browser push, calling) default to OFF.
  - The .com test server has `CHAT_SHARED_EXPERIENCE` ON.
  - Local `.test` has it OFF, so the sidebar's Chat opens the old `/operations/messages` page.

## Known issue on main (found 30 Oct, after the hand-over)

- **Chat's availability save locks the person's whole user record.**
  - `PATCH /chat/api/availability` (`ChatCallController::preference`) takes `lockForUpdate()` on the person's `users` row inside a transaction.
  - Chat saves this regularly while it is open.
  - Many other modules read the viewer's `users` row with `FOR SHARE NOWAIT`. When the two overlap, those pages fail with SQLSTATE 3572.
  - On 30 Oct this showed up as error pages on 3 H&S pages for the admin.
- **The other modules are being patched on main.** They will try the read again a moment later, which only hides the clash.
- **The real fix is in Chat:** lock a Chat-owned row instead of the `users` row, for example the person's `chat_preferences` row.
  - The call-start path ("Call starts lock the same person before checking availability") must lock the same row, so an off-duty save still can't race a call invitation.
  - Please include this when you take the Chat branches forward.

## Gotchas found so far

- **Windows unit tests:**
  - Tests that make EC keys (push) need `OPENSSL_CONF` pointing at PHP's `extras/ssl/openssl.cnf`.
  - Artisan output on Windows ends in `\r\n`, so `$` in `/m` regexes misses it.
- **TrimStrings** turns `" 👍"` into a valid `"👍"`. When testing invalid input, use inner spaces.
- **Reactions** need `utf8mb4_bin`. Under `utf8mb4_unicode_ci`, different emoji collide in the unique key.
- **Wayfinder build on Windows** fails creating `routes/api/medications/prn` (`PRN` is a reserved device name). Turn the plugin off for that build.
- **Voice fixtures** have no real Safari recording yet. It needs a Mac or an iPhone.
- **Notes written on Stephan's PC:**
  - They mention a shared test queue (`heavy-lock`), worktree folders and scratchpads. None of that exists on your machine.
  - What matters is the **branch names and commit hashes**.
- **Local calling recipe:**
  - `docs/chat-experience/self-hosted-calling.md` and `docs/chat-experience/qa/`.
  - Calls only record "connected" with the webhook set.
  - Browser panes that block the microphone need the Playwright script.

## This folder

- `notes/`: the working notes the sessions shared with each other. They lived only on Stephan's PC.
  - `oblivion-live-c2.1-notes.md`, `oblivion-live-c2.1-resume.md`
  - `push-sender-handover.md`, `push-sender-checker-result.md`
  - `voice-checker-c2.5-handover.md`, `voice-checker-c2.5-checker-findings.md`
- `probes/voice-checker-c2.5/`: the checker's scripts that build hostile test files (README inside).
- `tools/push-sender-similarity.php`: the push checker's comparison script. It contains no library code.
