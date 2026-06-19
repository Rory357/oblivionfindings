# `/hr/my` Employee Self-Service Redesign — Progress

Design drop: **"HR dashboard interactive prototype.zip"** → `design_handoff_hr_my_redesign/` (My HR.dc.html + README).
Persona: *Aroha Ngata, Support Worker, Kauri House (Auckland)*. NZD / en-NZ, **web-only, desktop, light-mode**.

Goal: rebuild `/hr/my` to the H&S gold standard (parity with `/meds/today`, `/my-day`, `/health-safety`)
using the **existing kit** (PageHero, MyHrTabs/TabStrip, MedsWizardDialog + wizard primitives,
ShiftContextMenu, StatusBadge, ClockWidget engine, sonner toasts, offline-queue). No new bespoke
primitives, no raw hex (ESLint blocks it). Every create/edit = wizard dialog; every row = right-click +
hover; every list = real empty state + skeleton. Self-scoped (`user_id = me`), `ResolvesHrTenant` + `hr.*` gates.

## Kit reference (verified file paths)
- `PageHero` + `PageLayout` `@/components/page` (brand-purple via `brandColour="var(--primary)"`, NOT category=hr coral).
- `MyHrTabs` `@/components/hr/my-hr-tabs` → `HrTabs`/`useHrTab` `@/components/hr/hr-tabs` → `TabStrip` `@/components/rostering/tab-strip`.
- `MedsWizardDialog` + `SummaryRow` `@/components/meds/wizard-shell`; primitives `@/components/wizard/primitives` (Field/StepHead/TilePicker/Ring/InfoCard/WIZARD_*_CLASS).
- `ShiftContextMenu` (`ShiftCtxItem`/`ShiftCtxState`) `@/components/rostering/shift-context-menu`.
- `StatusBadge` `@/components/ui/status-badge` (`variant` OR `status`; tones via `lib/status-colors`).
- `ClockWidget` `@/components/dashboard/clock-widget` — engine posts `/hr/time/clock-in|out` (`hr.my.time.*`). Do NOT add a new clock path (AttendanceService is the single source).
- offline-queue `@/lib/offline-queue` (`submitOffline`, idempotent `client_request_uuid`).

## Backend map
- Controller `app/Http/Controllers/Hr/MyHrController.php` (+ `ResolvesHrTenant`). Routes `routes/hr.php` `my.*` group.
- 1:1s ⇐ `HrSupervisionNote` (employee read where `is_visible_to_employee`; `employee_comments`/`employee_acknowledged`; aggregate `actions_agreed`). Phase 2 first-class agenda/action tables = **spec→confirm→build** (do NOT migrate without user OK).
- Kudos/feed ⇐ `HrKudos`+`FeedController`/`FeedService` (`/hr/feed`). Announcements ⇐ `HrAnnouncement`+`AnnouncementController@acknowledge`. Docs/e-sign ⇐ `HrDocument` + `ESignatureController` (`hr.signatures.*`, `HrDocumentSignature` scopePending/forSigner). iCal ⇐ `HrICalToken`/`ICalController`.
- This-week roster ⇐ extend `time()` with `Shift::visibleToFrontline` whereBetween week.

## Steps
- [x] **Step 0 — Recon** (prototype + kit + backend mapped; this tracker).
- [x] **Step 1 — Shared chrome** ✅ commit pending. MyHrTabs reordered + tones + dynamic count badges; new **1:1s** tab+route (`hr.my.one`/`one.acknowledge`)+page (Phase-1 over HrSupervisionNote); `BuildsMyHrShell` concern (`myHr` prop: profile/clock/weekly/nextShift/counts); `MyHrShell`+`MyHrHero`(brand-purple)+`MyHrClockCard`(break toggle, shared endpoints)+`lib/confetti`; `index.tsx` converted to shell. tsc/eslint clean; php -l clean. ⚠️worktree has no vendor → backend (artisan/phpunit) validated at merge in parent.
- [x] **Step 2 — Hero + Clock card** ✅ (delivered in Step 1: brand-purple gradient, te-reo greeting, live/docs/attestation badges, 4 stats + sparkline, quick actions, white clock card w/ break toggle on shared endpoints).
- [x] **Step 3 — Overview body** ✅ commit pending. `BuildsMyHrOverview` concern (latestKudos/celebrations[bday+anniv+new-starter]/whosOut/clock-streak/attention worklist) + `index.tsx` rebuilt: warm welcome strip, Needs-your-attention (StatusBadge + CTA + ctx menu View/Mark-done[D]/Snooze/Dismiss + client dismiss → inbox-zero confetti), This-week ring + next shift, delight 2×2 (Kudos+reactions+send, Celebrations+congratulate→real kudos, Who's-out StatusBadge, Announcements acknowledge real). tsc/eslint/php-l clean.
- [x] **Step 4 — Leave tab + Request Leave wizard** ✅ commit pending. `MyHrLeaveWizard` (4-step Type[TilePicker]→Dates[date Fields+half-day+working-days calc+leaves-Xh InfoCard]→Notes→Review[SummaryRow] on MedsWizardDialog; sends `hours_requested=days×8`; flash.error-gated success per [[reference_inertia_flash_error]]; reopens prefilled for Duplicate). `leave.tsx` rebuilt onto MyHrShell: balance ring cards, who's-out Mon–Fri team calendar, my-requests rows w/ StatusBadge + ctx menu (View/Duplicate/Cancel-critical w/ confirm). Backend: `leave()` adds `myHr`+`whosOutWeek` (`myHrWhosOutByDay` concern). tsc/eslint/php-l clean.
- [x] **Step 5 — Send Kudos wizard** ✅ commit pending (built early — needed by hero quick-action + overview). `MyHrKudosWizard` (3 steps Who→Value→Message; teammate search; value tiles; live feed preview) on MedsWizardDialog, hosted in `MyHrShell` via `useSendKudos()` context + hero handler. New self-service `POST /hr/my/kudos`→`MyHrController@sendKudos` (reuses `FeedService::sendKudos`, open to authenticated since `hr.feed.kudos` is gated `hr.recognition.give`). `teammates` added to shell payload. **Dropped boost/visibility** (not in kudos schema → hide-unbuilt). `lib` helpers `hueFromId`/`timeAgo`/`initialsOf` in `my-hr-utils`.
- [x] **Step 6 — Time & Shifts tab** ✅ commit pending. `time.tsx`→MyHrShell: Today punch timeline (from todayEntries + live "Now — on shift"), Hours-this-week bars (Mon–Sun, weekend muted, /target), This-week roster (7 cols, today-highlighted, site-coloured shift cards, ctx menu View/Add-to-calendar). Backend: `time()` adds `myHr`+`weekRoster` (`Shift::visibleToFrontline` whereBetween week); new per-shift `.ics` endpoint `GET /hr/my/time/shifts/{shift}/calendar`→`shiftCalendar` (existing iCal feed excludes shifts, so a focused per-shift download is the honest reuse). tsc/eslint/php-l clean.
- [x] **Step 7 — 1:1s tab + detail modal** ✅ commit pending. `MyHrOneOnOneModal` (wizard-shell chrome: 248px rail w/ context card + section-nav scroll, body Talking-points/Action-items/Notes, footer comment+Acknowledge) — Phase-1 review of `HrSupervisionNote`; acknowledge persists `employee_comments`. `one.tsx` upgraded: Next-1:1 gradient card (cadence chip, days-until, open-actions-to-wrap, Review-last CTA), progress strip (1:1s logged / open actions / last 1:1), history rows click→modal + ctx Open/Acknowledge. **Editable shared-agenda/talking-points (Phase 2) stays deferred** — labels are "Review", not "Prepare-and-save". tsc/eslint clean.
- [x] **Step 8 — Documents tab + e-sign dialog** ✅ commit pending. `documents.tsx`→MyHrShell: awaiting-signature amber banner (Review&sign→`MyHrEsignDialog`: doc summary + download-original link + type-name-to-sign live cursive + Sign&submit), Your-folders grid (grouped by folder/category w/ tinted chips + counts), All-documents list w/ Signed + expiry StatusBadge (Expired/Expires/Valid-to) + ctx menu (View details/Download). Backend: `documents()` adds `myHr`+`pendingSignatures` (`HrDocumentSignature::forSigner->pending`); new self-service `POST /hr/my/documents/sign/{signature}`→`signDocument` (reuses `ESignatureService::sign`, owner-gated, stays on page — existing `hr.signatures.sign` redirects away). tsc/eslint/php-l clean.
- [ ] **Step 9 — Placeholder tabs consistency** (Profile/Expenses/Payslips/Training/Policies/Reviews/Goals/Surveys: shared chrome + StatusBadge + ctx menu + wizards/empty states/skeletons).
- [ ] **Step 10 — Finalise**: tsc/eslint/build + scoped tests; Chrome-verify on .com; merge `--no-ff` → main; deploy.

## Notes / decisions
- Hero (greeting + clock + tab strip) is shared chrome on **every** `my/*` page (above the tab strip), via `MyHrShell`.
- Tab count badges are **dynamic** (pending leave, docs-to-sign, policies-due, 1:1s-needing-ack) not the prototype's hardcoded 1/3/2/1.
- Break toggle = client-side pause of displayed timer; `break_minutes` submitted at clock-out (backend already accepts it). No new endpoint.
- Phase-2 1:1 tables deferred pending user confirmation.
