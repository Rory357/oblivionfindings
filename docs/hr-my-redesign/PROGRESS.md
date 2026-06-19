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
- [ ] **Step 2 — Hero + Clock card** to fidelity (brand-purple gradient, greeting Mōrena/Kia ora/Pō mārie, live badge + docs-to-sign + attestation badges, 4 stats + sparkline, quick actions, white clock card w/ break toggle on shared endpoints).
- [ ] **Step 3 — Overview body**: warm welcome strip, Needs-your-attention (ctx menu + dismiss + confetti + inbox-zero), This-week ring, delight strip 2×2 (Kudos/Celebrations/Who's-out/Announcements) + backends.
- [ ] **Step 4 — Leave tab + Request Leave wizard** (4 steps, MedsWizardDialog, TilePicker, working-days calc, InfoCard, SummaryRow).
- [ ] **Step 5 — Send Kudos wizard** (3 steps; directory search; value tiles; visibility; boost; live feed preview) shared from hero + overview.
- [ ] **Step 6 — Time & Shifts tab** (Today punch timeline, Hours-this-week bars, This-week roster w/ ctx menu + add-to-calendar) + `time()` extension.
- [ ] **Step 7 — 1:1s tab + detail/prep modal** (Phase 1 over HrSupervisionNote: next card, progress strip, open actions, history; modal on MedsWizardDialog shell).
- [ ] **Step 8 — Documents tab + e-sign dialog** (awaiting-signature banner, folders, all-docs list w/ expiry StatusBadge + ctx menu; wire ESignatureController).
- [ ] **Step 9 — Placeholder tabs consistency** (Profile/Expenses/Payslips/Training/Policies/Reviews/Goals/Surveys: shared chrome + StatusBadge + ctx menu + wizards/empty states/skeletons).
- [ ] **Step 10 — Finalise**: tsc/eslint/build + scoped tests; Chrome-verify on .com; merge `--no-ff` → main; deploy.

## Notes / decisions
- Hero (greeting + clock + tab strip) is shared chrome on **every** `my/*` page (above the tab strip), via `MyHrShell`.
- Tab count badges are **dynamic** (pending leave, docs-to-sign, policies-due, 1:1s-needing-ack) not the prototype's hardcoded 1/3/2/1.
- Break toggle = client-side pause of displayed timer; `break_minutes` submitted at clock-out (backend already accepts it). No new endpoint.
- Phase-2 1:1 tables deferred pending user confirmation.
