# HR "Announcements" (Company Communications) Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/announcements` (and `?tab=` for **each** hub tab), open **every** modal, and diff against the gold-standard pages/components before continuing. Start with the audit in §A, then build §B–§L. **Anything you discover that needs backend/data work goes into §K "Backend handoff for Claude Code" — append to it as you go, and mirror the final list into a new `ANNOUNCEMENTS_BACKEND_HANDOVER.md` at repo root so Chane has one clean hand-off for Claude Code.**

**Page (canonical):** `/hr/announcements` — being recast as the **manager/HR command-center** for company communications. The Community Feed (`/hr/feed` "Notices") and My HR stay the *staff read* surfaces; this page becomes where notices are **composed, targeted, scheduled, delivered and tracked**. (Decision confirmed with Chane.)
**Frontend:** `resources/js/pages/hr/announcements/index.tsx`, `resources/js/pages/hr/announcements/show.tsx`. New shell wrapper `resources/js/components/hr/announcement-tabs.tsx` (build it, mould of `leave-hub-tabs.tsx`).
**Backend:** `app/Http/Controllers/Hr/AnnouncementController.php` (today: `index`, `create`→redirect, `store`, `show`, `acknowledge`) · routes in `routes/hr.php` (announcements block, ~L874–887). Header-bell inbox lives in `app/Http/Controllers/AnnouncementInboxController.php` + `routes/portal.php` (~L159–170).
**Models:** `app/Domain/Hr/Models/HrAnnouncement.php`, `app/Domain/Hr/Models/HrAnnouncementAcknowledgement.php`. Notification `app/Domain/Hr/Notifications/AnnouncementPublishedNotification.php` (database channel only). **Separate** header-bell system: `app/Models/Announcement.php` (+ `announcements`, `announcement_user_reads` tables) — see §G.
**Gold-standard modal to clone:** `resources/js/components/clients/add-client-dialog.tsx`. **Premium modal reference:** `resources/js/components/hr/leave-request-dialog.tsx` (the "New Request" flow). **Hero reference:** `resources/js/components/hr/my-hr-hero.tsx`. **Existing compose wizard to absorb/upgrade:** `resources/js/components/recognition/announce-wizard.tsx` (`AnnounceWizard`, mounted on the Feed) — see §E.

---

## 0. Mission

Make `/hr/announcements` a **premium, end-to-end company-communications command-center** that feels identical in quality to our gold-standard pages — **`/meds/today`**, **`/my-day`**, **`/health-safety`**, **`/hr/people`** — and reuses their exact components and tokens.

Today the page is **thin and redundant**: a generic flat `PageHero`, a single priority filter, a cards-only feed, and **one thin create dialog** (`Dialog` from `@/components/ui/dialog`, title + content + a few fields — create only, **no edit, no delete/archive, no duplicate, no scheduling UI, no attachments, no targeting beyond one dept/site/role, no acknowledgement roster**). Worse, **the same `HrAnnouncement` rows already render richer on three other surfaces** — the Feed "Notices" wall (reactions + replies + live "X of Y acknowledged" progress, via `FeedService::getFeedAnnouncements`), the My HR Overview card, and the `MyHrAroundModal` — so this page currently **duplicates the Feed while doing less**. And a published announcement **never reaches the global header-bell inbox** (`App\Models\Announcement` is a wholly separate system with zero sync), while **future-dated announcements fire no notification at all** (no scheduler hook).

**Result:** recast this page as the **command-center the other surfaces don't provide** — one **golden hero** (no clock), **five real tabs** (All · Pinned · Tracking · Scheduled · Insights) on the shared tab kit, **one full Add-Client-style compose/edit wizard** (scheduling, recurring, multi-segment targeting, attachments, rich text, review) that **replaces both** the thin index dialog **and** the Feed's `AnnounceWizard` (one composer, used in both places), **acknowledgement tracking + mandatory-read reminders**, **right-click everywhere**, a **publish→header-bell bridge**, and **one shared audience resolver** so the Feed and this page stop computing recipients two different ways. Bring it to parity with the gold-standard pages.

---

## 1. Non-negotiables

1. **Recast, don't duplicate.** This page is the **manage + track** surface; the Feed "Notices" tab stays the **social read** surface. Every feature here must be something the Feed/My HR does *not* already do better (compose, target, schedule, track, analyse). Where they overlap (compose, audience math, reactions), **unify onto one component/endpoint** — never maintain two.
2. **Build the tabs and make them real.** Five tabs: **All · Pinned · Tracking · Scheduled · Insights**. Use the shared tab kit (`HrTabs`/`TabStrip`), not a hand-rolled strip or pills.
3. **Reuse the kit — never hand-roll a primitive we already have.** No new bespoke widgets, no raw hex (ESLint blocks it — colours come from design tokens in `resources/css/app.css`). Everything in §2 is the source of truth.
4. **Information-gathering = full modals.** Every compose / edit / schedule / duplicate / send-reminder / claim flow is a **full wizard dialog** cloned from `add-client-dialog.tsx` — **not** an inline form and **not** a thin one-screen dialog and **not** a full-page route. Reading detail can navigate to `show.tsx` or open a sheet. **Each modal carries the full field set and a review step.**
5. **Web-only desktop app. No phone frames.** Design for mouse + keyboard: hover states, **right-click menus**, keyboard shortcuts. (Mobile app comes later.)
6. **Locale stays NZ.** `en-NZ` dates, NZ wording. Do **not** switch to GBP/US.
7. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface **and every modal** and diff vs the reference pages. Don't move on with a broken pass. **No dead buttons** — every action hits a real route or is appended to §K.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero**
- Golden band: clone the look of `resources/js/components/hr/my-hr-hero.tsx` — its `HERO_STYLE` (the `linear-gradient(120deg, …)` over `--primary` + the `boxShadow`, which re-themes per tenant) and the injected amber accent `--hr-amber` / `--hr-amber-soft`. **Omit the clock** — on My HR the clock is a separate child `<MyHrClockCard>` (`resources/js/components/hr/my-hr-clock-card.tsx`); "no clock" simply means **not rendering that child**. Do **not** import `MyHrHero` directly (it's data-coupled to `MyHrShellData`); lift the gradient/accent idiom into a small static wrapper, or extend `PageHero`.
- Generic base (acceptable fallback): `PageHero` from `@/components/page` with `category="hr"` (what the page uses today) — it already gives the HR gradient, decorative orbs, `stats`, `actions`, and a `footer` slot, no clock. Warm it toward the golden feel via the `--hr-amber` accent.
- Richer KPI-cluster reference (if you add tiles): `resources/js/pages/health-safety/components/hs-hero-kit.tsx` (`HeroShell`, `HeroMedallion`, `HeroCluster`/`HeroClusterTile`, `HeroSummaryStrip`, `HeroSegmented`).

**2.2 Modals / wizards**
- Clone `resources/js/components/clients/add-client-dialog.tsx`. Markers to match exactly: `Dialog`+`DialogContent` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, a **left stepper rail** (`w-[248px]`, `bg-sidebar`) with per-step icon + blurb + check-on-complete, a **completeness meter** at the rail foot, header "Step X of N", a **top progress bar**, scroll-contained body, footer with Back / Cancel / **Save & add another** / primary.
- Engine: Inertia `useForm`; a `STEPS` array (`{key,label,icon,blurb}`); client-side `validateStep(key, data)`; `stepForError(field)` to jump to the step that owns a server error; `SuccessPane` after submit; `resetAll()` for "Save & add another"; `forceFormData: true` whenever a file (attachment) is involved.
- Built from `@/components/wizard/primitives` (`Field`, `FieldErr`, `StepHead`, `SubHead`, `InfoCard`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `Ring`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`) and `@/components/wizard/shell` (`WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`) + the `useWizard(stepCount)` state machine. HR re-exports the whole kit via `@/components/hr/wizard.ts` — **import from there** to stay visually identical.
- Premium idioms to copy from `leave-request-dialog.tsx`: a **live preview** side-panel pinned via `railExtra` fed by a debounced `/preview` fetch (here: live recipient count + delivery summary), per-type accent tinting (per-priority here), review-step warning banners, optional confetti + `toast` on submit. People-picker for targeting: `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`).
- Base shadcn: `@/components/ui/` — `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`, `file-dropzone` (attachments), `multi-select-combobox` (multi-segment targeting), `checkbox`, `switch`, `radio-group`, `toggle-group`.

**2.3 Right-click menus + hover actions**
- There is **no** shadcn `ui/context-menu.tsx`. Reuse the portal pattern: `resources/js/components/hr/leave-context-menu.tsx` (`useLeaveContextMenu()` → returns `{ open, element }`; `open(items)` works for both `onContextMenu` and a `⋯` button `onClick`). Equivalents: `resources/js/components/rostering/shift-context-menu.tsx` (`ShiftContextMenu`) and `resources/js/pages/operations/handovers/components/handover-context-menu.tsx` (`useHandoverContextMenu`). Build an `AnnouncementContextMenu` on this shape.
- `TabStrip` itself accepts `onItemContextMenu` for right-clicking a tab.

**2.4 Cards / tables / states / badges**
- `@/components/ui/status-badge` (`StatusBadge`) **everywhere** — do not hand-map status colours (priority, draft/scheduled/published/archived, ack status).
- `@/components/ui/card`, `@/components/ui/table` (for Tracking/Scheduled rows), `@/components/ui/empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `skeleton-table`, `@/components/ui/laravel-pagination`, `@/components/ui/checkbox` (multi-select bars).

**2.5 Tabs**
- `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab(defaultTab, { param, syncUrl })`) built on `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, arrow/Home/End keys, `onItemContextMenu`, per-tab icon + count badge + tone). Wrap them in a thin `announcement-tabs.tsx` exactly like `resources/js/components/hr/leave-hub-tabs.tsx` does for Leave. Sync `?tab=` (and filters) via `useHrTab` or `router.get(... , { preserveState:true, replace:true })`.

**2.6 Tokens & flourishes**
- Tokens only, from `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`/`--hr-amber-soft`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`.
- Toasts: **sonner** (`<Toaster>` already mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action.
- Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## A. Audit & benchmark first (do this before building)

Study `/meds/today`, `/my-day`, `/health-safety`, `/hr/people` and **interact** with them — they are the parity bar. Then open `/hr/announcements`, `/hr/announcements/{id}`, **and** `/hr/feed` (Notices tab) and `/hr/my` so you can see the duplication first-hand. Fill in the checklist and paste it back as your first pass.

**Checklist**
- [ ] Screenshot every current surface that renders an announcement: `/hr/announcements` index + `show`, the Feed "Notices" `AnnouncementCard` (`resources/js/pages/hr/feed/parts.tsx`), the My HR Overview card (`resources/js/pages/hr/my/index.tsx`), and `MyHrAroundModal` (`resources/js/components/hr/my-hr-around-modal.tsx`). Note every hand-rolled element that has a kit equivalent.
- [ ] Confirm the **four-surface duplication**: all four read `hr_announcements` and acknowledge through the **same** `POST /hr/announcements/{id}/acknowledge`. The Feed version is strictly richer (reactions via `HrFeedReaction`, replies via `HrFeedReply`, polymorphic `subject_type='announcement'`; live ack %). Document which surface owns what after the redesign (this page = compose/target/schedule/track; Feed = social read).
- [ ] Confirm the **duplicated audience logic**: `AnnouncementController::announcementRecipients()` (~L174–211) and `FeedService::announcementAudienceCount()` (~L484–512) re-implement the same all/department/site/role resolution. They must collapse to one resolver (§H/§K).
- [ ] Confirm the **two-model split**: `HrAnnouncement` (tenant-scoped, ack-tracked, HR) vs `App\Models\Announcement` (role-scoped, **not** tenant-scoped, read-receipt-tracked, header-bell inbox via `AnnouncementInboxController` + `inbox-menus.tsx`). **No sync today.** Document the mapping you'll bridge in §G.
- [ ] Confirm the **notification gaps**: `AnnouncementPublishedNotification` is **database channel only** (no mail/broadcast); it fires **only** when `published_at <= now()` at store time; **nothing re-fires when a future `published_at` arrives** (no scheduler). So scheduled announcements silently appear on screens but never alert. Seeds §F/§K.
- [ ] Confirm **permissions**: `hr.announcements.view` / `hr.announcements.manage` are granted to **admin + hr only** (`database/seeders/SeedHrPermissionsSeeder.php` ~L66–68, attach ~L116–128); frontline staff reach announcements only via the Feed (`hr.recognition.view`) and My HR. The redesigned command-center tabs gate on `hr.announcements.manage`; a read-only "All" view may stay on `hr.announcements.view`.
- [ ] List every announcement route that exists vs every action the new UI needs; the delta seeds §K (note: **no update, no destroy/archive, no schedule-fire, no targets table, no attachments, no reminders, no export** exist today).

> **Known gaps the audit already surfaced** (confirm, then fix):
> - **Compose:** one thin `Dialog` (create only). No edit, no duplicate, no delete/archive, no draft concept, no scheduling UI, no recurring, no attachments, no rich text. Targeting is a **single** `target_audience` + **single** `target_value` string (can't target "Nurses across Site A + Site B").
> - **Two composers, one endpoint:** the Feed mounts a nicer `AnnounceWizard` (`resources/js/components/recognition/announce-wizard.tsx`); the standalone page uses its own thin inline dialog. Both POST `/hr/announcements`. **Unify onto one premium wizard (§E).**
> - **Tracking:** the page shows only a raw `acknowledgements_count`; `AnnouncementController::show` already loads the full `acknowledgements.user` roster but the index has **no** "who's acknowledged / who hasn't / by site/role / %" view. No reminders to non-acknowledgers.
> - **Lifecycle:** `published_at` null is the only "not yet live" state; there's no `draft`/`scheduled`/`archived` status, no edit-after-publish, no expiry management UI.
> - **Delivery:** database notification only; never bridges to the header-bell `Announcement` inbox; future-dated sends nothing.

---

## B. Hero rethink — the golden band (NO clock, fitted to announcements)

Replace the generic `PageHero` with the golden `MyHrHero`-style band (§2.1). One hero spans the hub; the active tab tunes the stats.

**Do:**
- Title: a warm, role-aware line (e.g. "Company communications") + an org/site context meta row (today's date `en-NZ`, your role, tenant/site) like the My HR hero meta. **No clock card.**
- `HeroStat` cluster (each clickable → deep-links into the matching tab/filter): **Live** (published & unexpired), **Pinned**, **Requires ack** (with overall **ack %**), **Scheduled** (future-dated/draft), **Unacknowledged (you owe a reminder)** in **amber** via `--hr-amber`. Use `delta`/tone where you have a trend (e.g. ack-rate vs last notice).
- `QuickAction`s on the hero: **New announcement**, **Schedule**, **Acknowledgement report (export)**, **Send reminders**. Each opens the matching wizard/flow (§E/§F) — no dead actions.
- Footer band ("Needs you" strip): notices **awaiting your ack**, **scheduled in the next 7 days**, and **required notices below target ack %** — each a chip that deep-links.
- Re-theme via `--primary`; amber accent only for "needs attention" numbers.

---

## C. Tabs — the Announcements command-center shell

Build `resources/js/components/hr/announcement-tabs.tsx` (mould of `leave-hub-tabs.tsx`) on `HrTabs` + `TabStrip` (§2.5). Five tabs, each with a count badge and tone, gated on `auth.can.hr.announcements.manage` (the read-only **All** tab may also serve `hr.announcements.view`):

1. **All** — every announcement (any status) with full filters (priority, audience, status, date, search across **title + content**). The browse/manage list. (§D1)
2. **Pinned** — pinned notices, drag-to-reorder priority of pins. (§D1)
3. **Tracking** — the acknowledgement command surface: per-announcement ack roster, %, who hasn't, filter by site/role, bulk **Send reminder**, **export**. (§D2)
4. **Scheduled** — drafts + future-dated, with publish-time, edit, "publish now", recurring series. (§D3)
5. **Insights** — analytics: ack-rate over time, read/ack by site/role, time-to-acknowledge, reach vs the header-bell bridge, top unacknowledged. (§D4)

> Per tab: real loading (`skeleton-*`), empty (`EmptyState`/`EmptySearch`) and error (`error-state`) states; URL-synced filters (`?tab=`, `?search=`, `?priority=`, `?status=`, …); right-click on rows **and** on the tab strip (§I). Detail stays a full page (`show.tsx`) reachable from any tab; upgrade it too (§D5).

---

## D. Tab-by-tab redesign

**D1 — All & Pinned.** Keep a polished **card list** (mirror the Feed `AnnouncementCard` quality — but this is the *manager* card) with a **table/density toggle** (`@/components/ui/table`) for power users, **sort** (published date, priority, ack %, audience size), and **search across title + content** (backend change — today the index filters priority only; §K). Each card/row shows: title, priority (`StatusBadge`), status (draft/scheduled/published/archived), audience summary (e.g. "Nurses · Site A, B — 42 people"), **ack progress bar (N of M · %)**, pinned flag, publish + expiry dates, attachment count, reaction/reply counts (reuse the Feed's polymorphic counts). Row/card actions (buttons + right-click, §I): **Open · Edit · Duplicate · Pin/Unpin · Send reminder · Acknowledgement report · Archive/Restore**. Multi-select + **bulk bar** (pin, archive, export, send reminder). **Pinned** tab is the same list filtered to `is_pinned`, with drag-reorder.

**D2 — Tracking (the headline new surface).** This is what the Feed/My HR can't do. For a selected announcement (or across all "requires ack" notices): a **roster table** of recipients with `StatusBadge` (acknowledged / not yet / reminded), acknowledged-at, site/role columns; a **donut/progress** of ack % overall and **by site and by role**; filters; and **bulk Send reminder** to non-acknowledgers (§F). Reuse `AnnouncementController::show`'s `acknowledgements.user` load, extended with the *expected* audience (from the shared resolver, §H) so "who hasn't" is real, not inferred from acks alone. **Export** the roster (CSV). This tab is the reason the page exists.

**D3 — Scheduled & Drafts.** A table of `status ∈ {draft, scheduled}` notices: title, target publish time (`en-NZ`), audience, recurring badge, last-saved. Actions: **Edit**, **Publish now**, **Reschedule**, **Duplicate**, **Delete draft**, and for series: **Pause/Resume recurrence**, **Skip next**. This tab depends on the lifecycle/scheduler backend (§K) — wire to real routes or append.

**D4 — Insights.** Manager analytics built on the kit (KPI tiles + `StatusBadge` + simple charts already used elsewhere in HR): ack-rate trend, **time-to-acknowledge** distribution, ack/read **by site and role**, **reach** (delivered via notification vs header-bell bridge vs feed), and a **top-unacknowledged** leaderboard with one-click reminder. Every number deep-links into a filtered All/Tracking view. Read-only queries mostly (§K).

**D5 — Detail page (`show.tsx`).** Recast as a premium profile: header with priority/status/pin, rich-text body, **attachments** list (download), the **acknowledgement roster** (reuse §D2 component), the **reaction/reply thread** (reuse the Feed's polymorphic `subject_type='announcement'` data so the page and Feed show the *same* social thread — see §H), and manager actions (Edit · Duplicate · Pin · Send reminder · Archive). A **"View as staff"** toggle renders exactly what a recipient sees.

---

## E. Compose / Edit Announcement wizard (full Add-Client pattern, replaces BOTH thin composers)

Build **one** wizard — `resources/js/components/hr/announcement-wizard.tsx` — cloned from `add-client-dialog.tsx` (§2.2): full-height bespoke shell, left stepper rail with completeness meter, top progress bar, per-step `validateStep`, `stepForError` jump, `SuccessPane`, **Save & add another**, `forceFormData` for attachments, `toast` on success, optional confetti on publish. The **same component runs in create and edit mode** (like Add-Client's `clientId` toggle). **Mount it in both places**: the `/hr/announcements` page **and** the Feed (replace/absorb `resources/js/components/recognition/announce-wizard.tsx` so there is exactly one composer). Proposed steps:

1. **Message** — title*, **rich-text content*** (body), priority (`Segmented`/`TilePicker`: low / normal / high / urgent with per-priority accent tint), **attachments** (`file-dropzone`, multipart).
2. **Audience** — **multi-segment targeting**: All staff, or any combination of **departments / sites / roles** via `PeoplePicker` + `multi-select-combobox` (supersedes the single `target_audience`+`target_value`). Show a **live recipient count + preview** in `railExtra` via a debounced `/hr/announcements/preview` fetch (Leave-modal idiom).
3. **Delivery & schedule** — publish now vs **schedule** (`published_at`), **expiry** (`expires_at`), **requires acknowledgement** (+ optional **ack-by deadline** and **reminder cadence**), **recurring** series (none / weekly / monthly + end), and **channels**: in-app (always), **header-bell inbox** (§G bridge — default on for high/urgent), email/broadcast (if/when added — gate behind §K).
4. **Review & publish** — `ReviewCard`/`ReviewRow` hero summary (audience size, when it sends, who must ack, attachments), warning banners (e.g. "0 recipients match", "scheduled in the past", "no acknowledgement deadline on an urgent notice"), then **Publish** / **Schedule** / **Save draft** + **Save & add another**.

> Wire it from the hero `QuickAction`s, the tab toolbars, and the Feed exactly like Add-Client is wired from `index.tsx`. Destructive actions (archive, delete draft, cancel recurrence) confirm via `alert-dialog`, **never** native `confirm()`. **No thin modal survives** — the old `Dialog` in `index.tsx` and the old `AnnounceWizard` are both retired in favour of this one.

---

## F. Acknowledgement tracking, mandatory-read reminders & delivery

The ack primitive already exists (`hr_announcement_acknowledgements`, `acknowledged_at`, unique per user; `POST /hr/announcements/{id}/acknowledge`). **Don't rebuild it — surface and operationalise it.**
- **Roster truth:** combine the ack rows with the *expected audience* from the shared resolver (§H) to compute "acknowledged / outstanding / %" reliably (today the count has no denominator on the index).
- **Reminders:** a **Send reminder** action (single + bulk, from Tracking/All/detail) re-notifies only non-acknowledgers. Needs a reminder route + a (queued) notification, and respect a cooldown. (§K)
- **Mandatory-read deadlines:** when `requires_acknowledgement` + a deadline is set, surface overdue acks in the hero "Needs you" strip and Insights; optional escalation to the person's manager. (§K)
- **Delivery path today:** `AnnouncementPublishedNotification` (database only). Extend per §G + add the **scheduled-publish fire** so future-dated notices actually notify when they go live. (§K)

---

## G. The header-bell bridge + cross-channel delivery (confirmed: bridge on publish)

Today an `HrAnnouncement` **never** reaches the global header-bell inbox (`App\Models\Announcement` + `announcement_user_reads`, surfaced by `AnnouncementInboxController` and `resources/js/components/inbox-menus.tsx`). Chane's decision: **bridge on publish.**
- On publish (and when a scheduled notice fires), **also** create/update a linked `App\Models\Announcement` row so the notice appears in the header bell + Notification Centre. Map: `title→title`, rich `content→body`, audience → `audience_roles` (best-effort from the role part of the targeting; document the lossy bits since `Announcement` is role-scoped & **not** tenant-scoped), `published_at→starts_at`, `expires_at→ends_at`, `is_active=true`. Make it **idempotent** (link via a new nullable FK, e.g. `hr_announcements.inbox_announcement_id`, or a pivot) so edits/unpublish/expire propagate and don't duplicate. **Confirm schema with Chane before migrating** (§K).
- Keep semantics distinct: `Announcement` tracks **passive read** (`read_at`); `HrAnnouncement` tracks **explicit acknowledgement** (`acknowledged_at`). The bridge mirrors *delivery/visibility*, not ack — acknowledgement stays owned by the HR side.
- Default the header-bell channel **on for high/urgent**, optional for low/normal (the §E step-3 toggle).

---

## H. De-dupe with the Feed + one source of truth

- **Feed stays the social read surface.** Do not remove the Feed "Notices" `AnnouncementCard` — but make this page the place notices are *managed*. Both render the same `hr_announcements` rows.
- **One composer** (§E) used by both the page and the Feed.
- **One audience resolver:** extract the all/department/site/role logic duplicated in `AnnouncementController::announcementRecipients()` and `FeedService::announcementAudienceCount()` into a single `AnnouncementAudienceResolver` (service), used by: recipient notification, the live preview count, the Tracking denominator, and Insights. (§K)
- **One social thread:** reactions/replies on this page's detail (§D5) reuse the **existing** polymorphic `HrFeedReaction`/`HrFeedReply` with `subject_type='announcement'` (the Feed already uses these) — so a reaction shown here is the same row shown on the Feed. **Do not invent a second reaction/reply system.**
- My HR Overview card + `MyHrAroundModal` keep deep-linking to `/hr/announcements` (the "See all" already exists) — verify those links land on the new **All** tab.

---

## I. Right-click everywhere (cards, rows and tabs)

Chane explicitly wants right-click options "under tabs etc." Build an `AnnouncementContextMenu` (mould of `useLeaveContextMenu`/`ShiftContextMenu`, §2.3) and wire `onContextMenu` (+ a `⋯` button using the same handler) on:
- **Announcement cards/rows (All/Pinned):** Open · Edit · Duplicate · Pin/Unpin · Send reminder · Acknowledgement report · Copy link · Archive/Restore. Gate manage items by `can.manage`; show `kbd` hints. Destructive → `alert-dialog`.
- **Tracking rows (recipients):** Open person · Send reminder · Mark acknowledged (manager override, audited) · Copy email.
- **Scheduled rows:** Edit · Publish now · Reschedule · Duplicate · Pause/Resume recurrence · Delete draft.
- **The tab strip itself** (`onItemContextMenu`): right-click a tab → **Set as default view**, **Open**, **Pin**. Persist default-tab/pins to `localStorage` (allowed) so it survives reloads; render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§K). **No dead items.**

---

## J. View-as-staff, exports & bulk

- **View as staff:** a toggle on detail (§D5) and a preview in the compose review step that renders the recipient view (no manage chrome) — so authors see exactly what staff get.
- **Exports:** CSV for the acknowledgement roster (Tracking) and the All list (filtered). New endpoint(s) — none exist (§K).
- **Bulk:** multi-select bars on All (pin/archive/export/remind), Tracking (remind selected), Scheduled (publish/delete selected). Back with bulk endpoints (§K).

---

## K. Backend handoff for Claude Code (append to this as you design)

> Claude design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code — and copy the finished list into a new **`ANNOUNCEMENTS_BACKEND_HANDOVER.md`** at repo root. Gate manager actions on `hr.announcements.manage`, respect `ResolvesHrTenant` tenant scoping, keep `en-NZ`, and **confirm any schema before building**. Seed list from the audit:

**Lifecycle & scheduling**
1. **Status model:** add an explicit `status` (`draft|scheduled|published|archived`) to `hr_announcements` (or derive from `published_at`/new flags) + **`SoftDeletes`** (or `archived_at`). Today only `index/create→redirect/store/show/acknowledge` exist.
2. **Update + destroy/archive endpoints** (`PUT /hr/announcements/{id}`, `DELETE`/archive, restore) — none exist; the wizard's edit mode needs them.
3. **Scheduled-publish fire:** a queued/scheduled job that, when a future `published_at` arrives, flips status to published, fires `AnnouncementPublishedNotification`, and runs the §G bridge. (Fixes the silent-future-dated gap.)
4. **Recurring series:** a recurrence rule (none/weekly/monthly + end) + a job that clones the next occurrence; pause/resume/skip.

**Targeting & audience**
5. **Multi-segment targeting:** replace single `target_audience`+`target_value` with a **`hr_announcement_targets`** table (`announcement_id`, `type` ∈ dept|site|role|user|all, `value`) **or** a JSON `targets` column. Migrate existing rows.
6. **`AnnouncementAudienceResolver` service** — single source for recipient resolution; refactor `AnnouncementController::announcementRecipients()` **and** `FeedService::announcementAudienceCount()` to call it. Add `GET /hr/announcements/preview` returning live recipient count for the wizard.

**Acknowledgement & reminders**
7. **Reminder endpoint(s)** (single + bulk) that re-notify only non-acknowledgers, with a cooldown; a queued reminder notification. Optional **ack-by deadline** + manager escalation.
8. **Tracking/roster endpoint:** expected-audience ∪ ack rows → acknowledged/outstanding/% by site & role; powers §D2 and Insights.
9. **Manager "mark acknowledged" override** (audited) for the Tracking right-click.

**Attachments & content**
10. **Attachments:** `hr_announcement_attachments` table + multipart upload on store/update + a gated download route (mirror the Feed's `downloadAttachment` pattern). Wizard uses `forceFormData`.
11. **Rich-text content:** persist sanitised HTML/markdown in `content`; render safely on detail/feed/inbox.

**Delivery / header-bell bridge (§G)**
12. **Bridge `HrAnnouncement → App\Models\Announcement` on publish** (and on scheduled fire): create/update a **linked** inbox row (add nullable `hr_announcements.inbox_announcement_id` or a pivot for idempotency); map fields per §G; propagate edit/unpublish/expire; document the role-scope vs tenant-scope mismatch. **Confirm before migrating.** Consider adding mail/broadcast channels to `AnnouncementPublishedNotification` later.

**Lists / export / bulk**
13. Widen index **search** to title + content; add **status/audience/date** filters and **sort** params (today: priority only).
14. **Export** endpoints (CSV) for the ack roster and the filtered list.
15. **Bulk** endpoints: pin/archive/remind/publish/delete selected.

**Permissions**
16. Keep `hr.announcements.manage` for all command-center actions; keep `hr.announcements.view` for read. If frontline staff should ever see a personal "my announcements" view here, decide the gate with Chane (today they only see announcements via the Feed/My HR). Reminder/override actions = `manage`.

> For each item: short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

---

## L. Premium polish & delight

- Micro-interactions from the kit: `animate-in fade-in-0 zoom-in-95` on modals/menus, hover lifts (`--shadow-float`), skeletons on load, optimistic toasts. `motion-reduce` guards throughout.
- Tasteful **confetti + celebratory `SuccessPane`** when a required notice hits 100% acknowledged, or on publish to a large audience (mirror the Leave self-service flourish) — not noisy.
- Keyboard: `/` focuses search, `n` opens New announcement, `p` pins the focused card, arrow/Home/End on tabs, Esc closes menus/modals; surface `kbd` hints in menus.
- Live preview where it helps (recipient count/conflicts in the Audience step, schedule summary in Review).
- Everything re-themes via `--primary`; amber (`--hr-amber`) only for attention (unacknowledged/overdue).

---

## Definition of done

- `/hr/announcements` is a **manager command-center**: **one golden hero (no clock)** + **five real tabs** (All · Pinned · Tracking · Scheduled · Insights) on `HrTabs`/`TabStrip`, matching the gold-standard pages — and it no longer merely duplicates the Feed.
- Every compose/edit/schedule/duplicate flow is **one** full Add-Client-style wizard (stepper rail, completeness meter, validation, review, Save & add another, attachments, scheduling, recurring, multi-segment targeting) — **the thin `index.tsx` dialog and the Feed `AnnounceWizard` are both retired in its favour**. No thin modals, no inline forms, no full-page create routes.
- **Tracking** gives a real acknowledgement roster (acknowledged / outstanding / % by site & role) with **bulk reminders** and **CSV export**; **Scheduled** manages drafts/scheduled/recurring; **Insights** shows ack-rate, time-to-ack and reach.
- **Right-click** works on cards, tracking/scheduled rows **and** the tab strip (Set as default / Pin); every action toasts and hits a real route. No dead items.
- **One audience resolver** powers notification, preview, tracking and insights (the controller/feed duplication is gone). **One social thread** (existing polymorphic reactions/replies) shared with the Feed.
- **Publish bridges to the header-bell inbox** (idempotent, linked); **scheduled notices fire on time**; future-dated no longer silently un-notifies.
- `en-NZ` retained; `ResolvesHrTenant` scoping + `hr.announcements.*` gates respected; **no regressions** to `/hr/feed`, `/hr/my`, the header bell, or the existing acknowledge flow.
- Clean `build`, `types`, `lint`; screenshots of each tab **and each modal** match the reference pages. **§K backend handoff list is filled in** and mirrored to `ANNOUNCEMENTS_BACKEND_HANDOVER.md` for Chane → Claude Code.
- **Signals to watch:** ack-rate on required notices, time-to-acknowledge, reminders sent, scheduled notices delivered on time, header-bell reach, % of announcement work done from this page vs the Feed.

**Build order:** §A audit → §B hero → §C tab shell → §D1 All/Pinned → §E compose/edit wizard (retire both thin composers) → §D2 Tracking → §F reminders → §D3 Scheduled → §G header-bell bridge → §H de-dupe/resolver → §D4 Insights → §D5 detail → §I right-click → §J view-as/export/bulk → §L delight. Verify each pass against the reference pages, and keep appending discovered backend work to **§K**.
