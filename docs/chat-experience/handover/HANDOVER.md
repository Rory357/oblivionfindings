# Chat rebuild: hand-over to Rory

First version 30 Oct 2026. **Rewritten 30 Oct 2026 (evening)** after a full audit of every branch, folder, document and the test server.

Stephan's Chat sessions built the first stages of the Chat rebuild between 22 and 23 Oct 2026. He paused them on 23 Oct, and Rory now carries this work on. Nothing from this work is on `main`. Every piece is on a GitHub branch for you to test and merge.

## If you already started from the first version, read this first

The first version left out a lot. What's new or corrected:

1. **The new Chat screens are not built.** Only the engine work and the drawings exist. See "What's built and what isn't".
2. **The drawings now open from the repo.** Their stylesheets and logo were missing. They're added now, along with single-file copies and the older Chat drawings for reference. See "The drawings".
3. **Stephan's answers on 30 Oct:**
   - the old Codex scope is retired;
   - you get your own access to the test server.
   See "Stephan's answers on 30 Oct".
4. **Branch facts were corrected:**
   - the Oblivion Live tests passed *before* its fixture fix, not after;
   - five branches are missing the base branch's last two commits;
   - the removal draft must take branch 6 first;
   - the push branch changes push for the whole app, medication alerts included.
5. **The test server is further along than the plan says.** It already has a virus scanner, ffprobe and a LiveKit voice server, and calls are on. See "The test server today".
6. **New sections:**
   - every setting each piece needs;
   - merge and deploy gotchas;
   - screens that have no drawing yet;
   - the documents to read.

If something you've already done clashes with this, keep your work and tell Stephan.

## What's built and what isn't

**Short version:**
- The sessions built the behind-the-scenes engine work.
- **The new Chat screens were drawn and approved, but not built.** Most of the work left is building those screens, not testing.
- On the base branch the screen code (`resources/js`) differs from `main` by only 12 lines.

**Live on `main` and the test server today**
- The first Chat that Codex built (PR #22, merged 22 Oct). Stephan said it "looks really bad". It is what this rebuild replaces.
- The plan's "What people see today" and "The 30 problems that matter most" sections show what's wrong with it.

**Built on branches; needs testing and sign-off**
- Stage 0 (set-up).
- Stage 2 engine fixes:
  - **done:** C1.2, C1.4, C1.6, C1.7;
  - **partly done:** C1.1, C1.5, C1.8, C1.9 (the plan's Progress table says what's missing);
  - **not built anywhere:** C1.3, the single delivery queue. It was moved to stage 3 but isn't in the Oblivion Live spec or on any branch;
  - **moved:** C1.10 to the stage 4 attach tray;
  - **already exists:** C1.11 (Service health);
  - **waiting:** C1.12. The investigator branch is ready, but the second-approver setting itself isn't built.
  - Stage 2's finish line, a load test with 300 staff, hasn't been run.
- Stage 3, five of eight items:
  - Oblivion Live, its browser sync loop and its ticket signer (C2.1, C2.2, C2.4);
  - the push sender (C2.3);
  - the voice-note checker (C2.5);
  - for C2.8, the web-push removal is done on the push branch. The Reverb and pusher-js removal is an untested draft. php-jwt stays until stage 5 replaces LiveKit.
- The investigator follow-up: grants for several conversations under one case, and a watermarked PDF export.

**Drawn and approved, but not built**
- Six Chat v3 boards, approved by Stephan on 22 Oct: Workspace, Compose, Messages, Oblivion, Calls and Family. See "The drawings".

**Not started**
- **Stage 3, the rest:**
  - C2.6: the new message model (one migration);
  - C2.7: the new screen architecture. `chat-thread.tsx` is 2,937 lines on `main` and needs splitting into small parts.
- **Stage 4, everyday messaging (C3.1–C3.27, the biggest stage):**
  - the conversation list;
  - grouped bubbles and a message area that fills the window;
  - read ticks;
  - an emoji picker and a reaction bar;
  - a hover bar and right-click menu;
  - threads, edit and delete, link previews;
  - formatting, stickers and an attach menu;
  - photos shown as pictures;
  - voice notes recorded in the chat;
  - polls, scheduled send, drafts and keyboard shortcuts.
- **Stage 5, calls (C4.1–C4.11):** Oblivion's own WebRTC calls with video and screen share, a Calls page, then LiveKit removed.
- **Stage 6:** the Oblivion-only features (C5.1–C5.13).
- **Stage 7:** the family portal on the new parts (C6.1–C6.5).
- **Stage 8:** every place Chat appears (C7.1–C7.9): the header button, side sheets, notifications, Settings › Chat.
- **Stage 9, switch over (C8.1–C8.9):**
  - move the old data;
  - turn it on Site by Site;
  - delete the old pages (about 4,700 lines);
  - check accessibility and load;
  - get the clean-room sign-off;
  - rewrite the chat tests with the screens. 68 test files assert today's wording.

**Sizes in the plan:** S is a day or less, M is a few days, L is one to two weeks.

## Stephan's answers on 30 Oct

- **The old Codex scope is retired.** These no longer apply:
  - the 51 refinements in `implementation-coverage.json`;
  - acceptance checks A01–A20;
  - "Remaining approved scope" in `IMPLEMENTATION-STATUS.md`;
  - the 20 Oct spec line "Codex's structure is kept exactly";
  - the M01–M25 mockups in `docs/chat-experience/mockups/`.

  The rebuild plan and the v3 boards are the only scope now. `IMPLEMENTATION-STATUS.md` is a dated Codex log, not a status page.
- **You get your own access to the test server.** Send Stephan your SSH public key. Until then, server steps go to him.
- **The look:** build to the approved v3 boards.
  - Use `DESIGN.md`'s semantic tokens.
  - Reuse the signature parts already on `main`: `resources/js/components/signature/` and `resources/css/signature-blocks.css`, approved for Health & safety on 23 Oct.
  - If a board needs a colour or part that `main` doesn't have, ask Stephan before adding it to the shared tokens.
  - The rest of the signature (C5.10: on-shift rings, delivery rings, horizon line on panels) ships only once Stephan approves the signature app-wide (D12).

## The drawings

Stephan checked these on 30 Oct: they are the right ones.

| Folder | What's in it | How to open |
|---|---|---|
| `docs/chat-experience/v3/standalone/` | The six approved boards as single files, with every stylesheet and logo built in. | Double-click any file. It works anywhere and can be shared or attached as it is. Needs internet only for the font. |
| `docs/chat-experience/v3/boards/` | The same six boards, now with the `oc.css`, `oc-chat.css` and `brand/` files they need beside them. | Open a board in a browser from your checkout. Add `#d` to the address for dark mode. |
| `docs/chat-experience/v3/reference/` | Older Chat drawings, "Codex revision 2 in the Slate skin": Chat settings, calls kit, family portal and inbox, staff parts kit, page scene, family portal page and the signature board, each in light and dark. | Open from your checkout. **Superseded look.** Use them only to see which settings, states and screens exist. |

**Screens with no v3 drawing yet.** Draw these and get Stephan's approval before building them:
- Settings › Chat (C7.8). Follow the Fleet settings pattern. The reference `ChatSettings` board lists the settings.
- The investigator page and export pop-up. Branch 3 uses the old chat-governance screens.
- The Chat notification row (C7.7).
- The single-conversation side sheet (C7.3).
- Every pop-up and state on the pop-up kit (C1.0e).

**Other places the drawings live**
- Stephan's design viewer is a private claude.ai page (`S2FWVebvqtxrVL5LHahzHy`). Its "Chat v3 — proposed rebuild" page holds the same boards. Ask Stephan if you want it shared.
- The plan also exists as Stephan's private page (`Y2qcgDnD8ZKA3cjTCTYLGm`). The repo copy is the same document.
- `v3/source/` holds the scripts that drew the boards. They import helpers from a temporary folder that no longer exists, so treat them as reference only.

## Start here

1. `git fetch origin`, then check out **`chat/handover-rory`**. It holds the base branch plus this folder and the drawings.
2. Open `docs/chat-experience/2026-10-22-rebuild-plan.html` in a browser. It has:
   - the stages and every task ID;
   - the "Progress so far" table;
   - all the decisions.
3. Open the boards in `docs/chat-experience/v3/standalone/`.
4. Read the clean-room rules:
   - `docs/chat-experience/clean-room/README.md`;
   - `REGISTER.md`;
   - `specs/*.md`;
   - each branch's `clean-room/provenance/*.md` (build decisions, open questions, checks to run).
5. Read the notes in `notes/` for whichever piece you pick up first.

## The branches (all on GitHub, none merged)

| # | Branch | What it is | State | What's left |
|---|---|---|---|---|
| 1 | `claude/chat-module-audit-plan-704fd7` | **The base.** Stage 0, the stage 2 engine fixes, and the docs: plan, v3 boards, clean-room rules, specs, and `DESIGN.md` chat rules. Composer renamed to `oblivion/care`. | 12 commits ahead of `main`, 331 behind. Its last commit (`1e7193a65`) records 431 Chat tests passed; tsc clean. | Merge `main` in. Re-run the Chat tests, then merge. Conflicts: `eslint.config.js` and `routes/console.php`. |
| 2 | `claude/reverent-wu-0bdbe2` | **C2.3 push sender.** Oblivion's own Web Push sender in `app/Services/Push/`. Removes minishlink/web-push, web-token/jwt-library, spomky base64url and pki-framework. **This changes push for the whole app**: the notification `WebPushProvider` and `webpush:vapid` use the new sender (`d06f0f123`). Edge is allowed (`5de20c477`). | Signed off by the checker (`39048778d`). 287 push and Chat tests passed at `5de20c477`. | <ul><li>Run the server key check first (`provenance/push-sender.md`).</li><li>Do the manual browser test on the test server (Chrome, Edge, Firefox, Safari 16.4+), including a **medication alert** (`specs/push-sender.md`).</li><li>Switching Chat push on needs more than keys: device binding, the service-worker version check and failure metrics (`browser-push.md`, the "before switching on" lists).</li><li>Fix the stale line in `IMPLEMENTATION-STATUS.md` (around line 470).</li></ul> |
| 3 | `claude/chat-investigator-grants-export-b6e2cf` | **Investigator follow-up.** One case can grant several conversations, each logged separately. A watermarked, logged PDF export of granted conversations, blocked after expiry. Stephan's M22 decisions are recorded in this branch's spec. | `e800571ff`, built on `main` at `d5d279a79`, without branch 1. It merges with `main` cleanly. Its tests ran on 22 Oct, but the results were lost. | <ul><li>Re-run `ChatInvestigationCaseExportTest.php`, `ChatInvestigationGovernanceTest.php`, vitest `investigation-case-export.test.tsx` and `chat-governance.test.tsx`, and tsc.</li><li>`chat.investigate` is granted to no role on purpose (migration `2026_10_20_101000`). To test it, grant it to a test user in Settings › Roles.</li><li>Its screens are the old chat-governance ones, with no v3 drawing.</li><li>C1.12 (the second-approver setting) is still to build.</li></ul> |
| 4 | `claude/eager-mahavira-c57cd4` | **C2.5 voice-note checker (builder).** Oblivion decides every voice note itself. ffprobe, when configured, is only a second opinion. | Checker findings F1–F4 fixed (`98ed39a95`). The F5 + F6 fix is a **WIP commit, `68f707db4`, not yet tested with Pest**. | <ul><li>Run the voice tests twice: without ffprobe, and with `CHAT_VOICE_PROBE_BINARY` pointing at ffprobe 8.1.1 (`notes/voice-checker-c2.5-handover.md`).</li><li>Then hand it to a checker session.</li><li>The WIP clashes with branch 5 in `provenance/voice-checker.md`.</li></ul> |
| 5 | `claude/voice-checker-signoff-49310d` | **C2.5 checker's record**, from a different session than the builder. | Sign-off **held** for F5 and F6 (MP4 time and memory). Record `2847eb6cb`. | When the F5/F6 fix passes: re-run the probes in `probes/voice-checker-c2.5/` and `token-similarity.mjs` on the changed files, then sign `REGISTER.md`. The session that signs must not be the one that wrote the fix. |
| 6 | `claude/eloquent-shamir-b83b60` | **C2.1 / C2.2 / C2.4 Oblivion Live.** Chat's own WebSocket gateway (`live/`, Node built-ins only), publisher, signer, targeted events and browser client. | **Corrected:** the Pest batch ran *before* the fixture fix: 232 passed, 3 failed. The fix is `f46c5c7d5`. tsc 0 errors. 68 gateway tests (`npm run test:live`). | In order: <ol><li>re-run `tests/Feature/Chat/OblivionLiveTest.php`;</li><li>production build;</li><li>two-browser end-to-end test;</li><li>bring in branch 7;</li><li>clean-room checker. The similarity check hasn't been run, and the checker must be a different session.</li></ol> The end-to-end set-up: `live/tools/dev-proxy.mjs` on 127.0.0.1:8899 and 127.0.0.2:8900, the gateway, and an e2e database (`notes/oblivion-live-c2.1-*.md`). Stephan's launch configs for this aren't in the repo. Open question: presence is only correct with one gateway node. |
| 7 | `claude/oblivion-live-removal-wip` | Draft that removes Reverb and pusher-js once Oblivion Live is in. | `80022d227`, **untested**. It was cut from `d12caba6e`, *before* branch 6's fixture fix, and it edits `OblivionLiveTest.php`. | Merge branch 6 into it first. It clashes with branch 2 in `composer.lock` and the clean-room README/REGISTER: regenerate the lock file, don't hand-merge it. |
| 8 | `chat/merge-attempt-2026-10-23` | Reference only: an old attempt that merged 1 and 2 onto `main` at `3ceefff70`. | Pre-merge tests never ran. | Don't merge it. Use it only to see how conflicts were resolved then. |
| 9 | `chat/handover-rory` | Branch 1 plus this `handover/` folder and the drawing files. | — | Start here. |

**How the branches relate**
- Branches 2, 4, 5, 6 and 7 each contain branch 1, but **not its last two commits**:
  - `1e7193a65`: private groups keep a member who moves Site; additions need people the adder can see;
  - `016e3e484`: plan progress.
- So their test counts predate that rule. Merge branch 1 to `main` first, then merge `main` into each branch.
- They also contain some referral commits that are already on `main`.

## Suggested order

1. **Base (branch 1):** merge `main` in, re-run the Chat tests, then merge to `main`. Every new Chat flag defaults to off.
2. **Push sender (branch 2):** merge, run the push tests, then the manual browser test, including a medication alert.
3. **Investigator (branch 3):** re-run its tests, then merge.
4. **Voice checker (branches 4 + 5):** test the F5/F6 fix, then get checker sign-off, then merge.
5. **Oblivion Live (branches 6 + 7):** finish the steps above, then checker sign-off, then the Reverb/pusher-js removal, then merge.
6. **Then the new screens, stage by stage**, built view for view from the boards.

## Merging into main and deploying

- **Conflicts.** A dry-run merge on 30 Oct found only `eslint.config.js` and `routes/console.php` clash with `main`, on every branch except 3.
- **Libraries.** `main` has not started using web-push, pusher-js, php-jwt or LiveKit anywhere new since 23 Oct.
- **Permissions.** No branch adds a permission key, so no grant migration is needed. Deploys skip seeders, so any key you add later needs one.
- **Migrations:**
  - branch 1: `2026_10_22_120000_make_chat_reactions_exact` (reactions use `utf8mb4_bin`) and `2026_10_22_121000_add_chat_pin_details`. They are identical on every branch that carries them.
  - branch 3: `2026_10_22_100000_add_case_batch_to_chat_investigation_grants`.
- **Lint.** `main` is at 0 lint errors and 0 warnings (`npm run lint`). Keep it there. Full tsc and production builds need a 6 GB Node heap (see `package.json`).
- **DESIGN.md.** `main`'s `DESIGN.md` (1,233 lines) is newer than the base branch's copy (1,075 lines). Keep both the newer `main` content and the branch's Chat rules when merging.
- **Every push to `main` deploys to oblivionfindings.com within about a minute, and Chat is on there.** A merge changes what people see straight away. The data there is fictional demo data.

## The test server today (checked 30 Oct)

- **Chat:** on (`CHAT_SHARED_EXPERIENCE=true`).
- **Files:** the ClamAV daemon is running. `CHAT_SCANNER_BINARY=/usr/bin/clamdscan` and `CHAT_SCANNER_FD_PASS=true`.
- **Voice notes:** ffprobe at `/usr/bin/ffprobe` (`CHAT_VOICE_PROBE_BINARY`).
- **Calls:**
  - a LiveKit server runs as the `oblivion-voice` service (since 21 Oct, bound to 127.0.0.1, config `/etc/livekit.yaml`);
  - `CHAT_CALLING_VERIFIED` and `CHAT_CALLING_AUTH_GATEWAY_VERIFIED` are on;
  - when stage 5 replaces LiveKit, that service and its config go too.
- **Off:**
  - live updates (`CHAT_REALTIME_ENABLED`, and `BROADCAST_CONNECTION=log`);
  - browser push (no VAPID keys set);
  - draft recovery (`CHAT_DRAFT_RECOVERY`). It stays off until its recovery checks pass.
- **Not installed:** coturn. D4 says it runs on a separate server.
- **Machine:**
  - PHP 8.5-fpm. Stephan tuned it on 30 Oct: 12 workers, a larger MySQL cache and opcache.
  - Swap is already in use, so watch memory when adding the Live gateway.
- The plan's 22 Oct "What people see today" describes the server *before* this set-up, so it is out of date.

**Server rules**
- Never leave files in `/var/www/oblivionfindings`. A dirty checkout makes the deploy refuse, and it then dumps the database every minute. Keep backups in `~/env-backups`.
- Never open an MQTT port, and never send device traffic over the public internet. That rule is for the Security & devices module, not Chat, but it's the same server.

## Settings each piece needs

`.env.example` has no `CHAT_` lines yet. Add them as each piece merges.

- **Chat:**
  - `CHAT_SHARED_EXPERIENCE`;
  - `CHAT_DRAFT_RECOVERY`;
  - `CHAT_REALTIME_ENABLED`;
  - `CHAT_HEALTH_MESSAGING_OWNER`, `CHAT_HEALTH_REALTIME_OWNER`, `CHAT_HEALTH_PUSH_OWNER`, `CHAT_HEALTH_UPLOADS_OWNER`, `CHAT_HEALTH_CALLING_OWNER`.
- **Files:** `CHAT_SCANNER_BINARY`, `CHAT_SCANNER_DATABASE_PATH`, `CHAT_SCANNER_FD_PASS`, `CHAT_SCANNER_TIMEOUT_SECONDS`.
- **Voice:** `CHAT_VOICE_PROBE_BINARY` (optional once branch 4 lands).
- **Push:**
  - `CHAT_BROWSER_PUSH_ENABLED`;
  - `CHAT_BROWSER_PUSH_HOSTS`. Any server that sets it must include `.notify.windows.com` for Edge;
  - `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` (in `config/services.php`).
- **Oblivion Live:**
  - on the app (branch 6): `CHAT_LIVE_ENABLED`, `CHAT_LIVE_INTERNAL_URL`, `CHAT_LIVE_SECRET`, `CHAT_LIVE_KEY_ID`, `CHAT_LIVE_PREVIOUS_SECRET`, `CHAT_LIVE_PREVIOUS_KEY_ID`, `CHAT_LIVE_ALLOWED_ORIGINS`;
  - on the gateway: the `LIVE_*` values in `deploy/chat-calling/oblivion-live.env.example`;
  - Node 22 or later;
  - a systemd unit and the nginx `location = /chat-live` block (both as `.example` files in `deploy/chat-calling/` on branch 6);
  - port 8091 is reached only through nginx; internal port 8092 must never be exposed.
- **Calls today:** `CHAT_LIVEKIT_URL`, `CHAT_LIVEKIT_API_URL`, `CHAT_LIVEKIT_API_KEY`, `CHAT_LIVEKIT_API_SECRET`, `CHAT_CALLING_VERIFIED`, `CHAT_CALLING_AUTH_GATEWAY_VERIFIED`.

## Documents to read

- **The plan:** `docs/chat-experience/2026-10-22-rebuild-plan.html`. This is the source of truth.
- **Design:**
  - `DESIGN.md` (the copy on `main`; the base branch adds the Chat rules);
  - `design_styles/PAGE_KIT_STYLE_GUIDE.md`, `POPUP_STYLE_GUIDE.md` and `WRITING_STYLE_GUIDE.md`;
  - `docs/design-refresh-2026-10-19/SIGNATURE.md`.
- **Clean room:**
  - `docs/chat-experience/clean-room/` (README, REGISTER, specs);
  - each branch's `clean-room/provenance/*.md`.
- **Push:** `docs/chat-experience/browser-push.md`, which lists what must be ready before push is switched on.
- **Calls and server:**
  - `docs/chat-experience/self-hosted-calling.md`;
  - `deploy/chat-calling/`;
  - `docs/chat-experience/qa/`.
- **Where Chat appears today:** `docs/chat-experience/2026-10-15-surface-inventory.md`.
- **History only:** `IMPLEMENTATION-STATUS.md` (the Codex log) and the old spec `2026-10-15-specification.md`. Both are replaced by the plan wherever they differ.

## Rules that must hold

### Clean room (Stephan's decision D1)

- Oblivion's chat engines are written from the specs, never from outside code. Live updates, calls, push and the voice checker are all replaced; the platform and ClamAV stay.
- The **builder** and the **checker** must be different sessions.
- An engine merges only after the checker signs it off in `REGISTER.md`.
- Checkers may read the library source to compare against, but they keep it **out of the repo**. Findings are written in words only, never as quoted code.
- The token-similarity tools are:
  - `docs/chat-experience/clean-room/tools/token-similarity.mjs` (on branch 5);
  - `tools/push-sender-similarity.php` (in this folder).
- Who owns AI-written code needs a lawyer's view before commercial use (`clean-room/README.md`). That is Stephan's to arrange.

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
- **D7:** outside link previews are on by default and an admin can switch them off. Previews are fetched server-side, with a guard against internal addresses.
- **D8:** staff see per-person read status; families see "Seen by care team".
- **D9:** a message can be edited for 15 minutes; delete-for-everyone works for 1 hour.

**Governance and features**
- **D10:** AI helpers are planned, and off by default.
- **D11:** a second approver for investigator access is a setting. The default is self-grant plus an audit log entry.
- **D12:** Chat is drawn with the signature. The signature details ship only once Stephan approves the signature app-wide.

**Layout**
- **D14:** `/chat` is full height with no header band. This is a documented exception in `DESIGN.md`.

**Later decisions**
- **Private groups:** when a member moves Site, the group keeps them and shows their name to everyone. Nobody may add someone they can't see.
- **Push:** Edge is allowed. TTL is 12 h, urgency high.
- **Voice:** refuse Vorbis voice notes.
- **Investigator:**
  - investigators get their own page;
  - grants are per conversation and time-limited;
  - several conversations can be granted under one case;
  - export is a watermarked PDF.

### General

- **Desktop web app only.** No phone layouts, but nothing may break when the window is narrow or zoomed.
- **Never loosen safety, privacy or permission rules.** Ask Stephan if a change would.
- **Build only what an approved drawing shows.**
  - Build each screen view for view from the boards.
  - Compare board and build side by side at 1440 px, in light and dark.
  - Anything the boards don't show gets drawn and approved first.
- **Design:** read `DESIGN.md` before any UI work. Use semantic tokens only, and reuse the primitives in `resources/js/components/ui/`.
- **Brand is never hard-coded.** The logo and the one brand colour come from Settings › Branding (`--primary`).
- **Feature flags default to off:** `CHAT_SHARED_EXPERIENCE`, `CHAT_DRAFT_RECOVERY`, `CHAT_REALTIME_ENABLED`, `CHAT_BROWSER_PUSH_ENABLED`, `CHAT_LIVE_ENABLED` and the calling switches.
  - With `CHAT_SHARED_EXPERIENCE` off, the sidebar's Chat opens the old `/operations/messages` page.
  - Your local site follows your own `.env`.

## Known issue on main (found 30 Oct)

- **Chat's availability save locks the person's whole user record.**
  - `PATCH /chat/api/availability` (`ChatCallController::preference`) takes `lockForUpdate()` on the person's `users` row inside a transaction.
  - Chat saves this regularly while it is open.
  - Many other modules read the viewer's `users` row with `FOR SHARE NOWAIT`. When the two overlap, those pages fail with SQLSTATE 3572.
  - On 30 Oct this showed up as error pages on 3 H&S pages for the admin.
- **The other modules are being patched on `main`.** They try the read again a moment later, which only hides the clash.
- **The real fix is in Chat:** lock a Chat-owned row instead of the `users` row, for example the person's `chat_preferences` row.
  - The call-start path must lock the same row, so an off-duty save still can't race a call invitation.
  - Please include this when you take the Chat branches forward.

## Gotchas found so far

- **Windows:**
  - Anything that makes EC keys for push needs `OPENSSL_CONF` pointing at PHP's `extras/ssl/openssl.cnf`. That includes the tests and also local queue workers that send push.
  - Artisan output ends in `\r\n`, so `$` in `/m` regexes misses it.
  - The Wayfinder build fails creating `routes/api/medications/prn` (`PRN` is a reserved device name). `main` fixed this (`3ceefff70`). It only bites on the unmerged branches.
- **TrimStrings** turns `" 👍"` into a valid `"👍"`. When testing invalid input, use inner spaces.
- **Reactions** need `utf8mb4_bin`. Under `utf8mb4_unicode_ci`, different emoji collide in the unique key. Branch 1's migration fixes this.
- **Voice fixtures** have no real Safari recording yet. It needs a Mac or an iPhone.
- **Notes written on Stephan's PC:**
  - They mention a shared test queue (`heavy-lock`), worktree folders, scratchpads and launch configs. None of that exists on your machine.
  - What matters is the **branch names and commit hashes**.
- **Local calling:**
  - The recipe is in `docs/chat-experience/self-hosted-calling.md` and `docs/chat-experience/qa/`.
  - Calls only record "connected" when the webhook is set.
  - Browser panes that block the microphone need the Playwright script.

## This folder

- `notes/`: the working notes the sessions shared with each other. They lived only on Stephan's PC.
  - `oblivion-live-c2.1-notes.md`, `oblivion-live-c2.1-resume.md`
  - `push-sender-handover.md`, `push-sender-checker-result.md`
  - `voice-checker-c2.5-handover.md`, `voice-checker-c2.5-checker-findings.md`
- `probes/voice-checker-c2.5/`: the checker's scripts that build hostile test files (README inside).
- `tools/push-sender-similarity.php`: the push checker's comparison script. It contains no library code.
