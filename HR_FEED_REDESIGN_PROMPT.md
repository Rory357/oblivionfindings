# HR "Feed" (Community & Recognition) Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/feed` (and `?tab=` for each tab + each modal open), and diff against the gold‑standard pages/components before continuing. Start with the audit in §A, then build §B–§K.

**Page:** `https://oblivionfindings.com/hr/feed`
**Frontend:** `resources/js/pages/hr/feed/index.tsx` (Inertia + React + Tailwind v4)
**Backend:** `app/Http/Controllers/Hr/FeedController.php` · `app/Domain/Hr/Services/FeedService.php` · routes in `routes/hr.php` (feed group ~`:811`)
**Models:** `HrFeedPost`, `HrKudos`, `HrKudosReaction`, `HrKudosReply` (all `app/Domain/Hr/Models/`)
**Current modal:** `resources/js/components/hr/recognition-dialog.tsx` (kudos) · **Clone target:** `resources/js/components/clients/add-client-dialog.tsx`

---

## 0. Mission

Turn `/hr/feed` into a **premium, end‑to‑end Community & Recognition surface** that feels identical in quality to our gold‑standard pages — **`/meds/today`**, **`/my-day`**, **`/health-safety`** — and reuses their exact components and tokens. This is the **organisation‑wide HR + manager view** of recognition and team life (the employee‑scoped social bits live on `/hr/my` + `/hr/my/shoutouts` — don't duplicate that; this page is the wall, the leaderboard, the celebrations, the announcements **and** the manager/HR insight layer).

Today `/hr/feed` is functional but dated: a **generic flat `PageHero`** (not the golden band), an **inline post composer** (not a modal), **filter pills masquerading as "tabs"** (the "old views"), **no reactions or comments on the wall** (even though the backend already supports both), **a kudos modal that doesn't match the Add‑Client shell**, **no right‑click menus anywhere**, **no pin/moderate**, and **no manager analytics**. Bring it to parity, give it the **golden HR hero band (no clock)**, swap the create flows to the **exact Add‑Client wizard pattern**, add **real premium tabs with right‑click menus (rows and tabs)**, wire **reactions + comments + pinning** through to the existing backend, and add a gated **Insights** tab so managers/HR get the recognition picture. Result should feel warm and a little fun (recognition, celebrations, live micro‑interactions) — not a grey notice board.

## 1. Non‑negotiables

1. **Introduce a real tab model.** The current five **filter pills** (`all · update · kudos · announcement · milestone`) are the "old views" Chane means — replace them with a proper **`FeedTabs`** shell (same tab language as `/hr/people`'s `HrTabs` and `/hr/my`'s `MyHrTabs`), reflected in a `?tab=` query param. You may name/reorder/merge tabs and add a "Saved views" affordance, but the page gets a **standardised tab system**, not button pills.
2. **Reuse the kit — never hand‑roll a primitive we already have** (§2). This page must be *standardised with the rest of the app*: every hero, modal, badge, status colour, context menu, empty state and toast comes from the shared kit. **No new bespoke widgets, no raw hex** (ESLint blocks it — colours come from design tokens).
3. **Web‑only desktop app.** No phone frames. Design for mouse + keyboard: hover states, **right‑click menus**, keyboard shortcuts. Responsive down to a small laptop is fine. (A dedicated mobile app comes later — not now.)
4. **Information‑gathering = modals.** Every create/compose/recognise/announce/nominate flow becomes a **wizard dialog** cloning the Add‑Client shell (§2.2 / §D), **not** an inline composer and **not** a full‑page route. Reading long content (an announcement body, a kudos thread) may use a dialog/sheet.
5. **Single source of truth — don't fork the social backend.** Kudos, reactions and replies already exist and are shared via `FeedService` + `MyHrController`. The wall must **reuse** `HrKudos` / `HrKudosReaction` / `HrKudosReply` and the existing react/reply endpoints — don't invent a parallel reaction store. Announcements are owned by the **Announcements module** (`HrAnnouncement`, `hr.announcements.manage`) — surface them, don't re‑implement them.
6. **Locale stays NZ.** NZD / `en-NZ` formatting and `en-NZ` dates (the My HR hero already does `toLocaleDateString('en-NZ', …)`). Keep it. Do **not** switch to GBP/US.
7. **Respect scoping & permissions.** Everything is tenant‑scoped via `ResolvesHrTenant`. View gated by **`hr.recognition.view`**, give/post by **`hr.recognition.give`**; the new manager/admin actions (pin, moderate, analytics) need a **new `hr.recognition.manage`** gate (§H) — mirror how `hr.announcements.manage` works. Hide manager‑only UI when the user lacks the gate.
8. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface; confirm it matches the reference page's hero/modal/menu. Don't move on with a broken pass.

---

## A. Audit & benchmark first (do this before building)

Study `/meds/today`, `/my-day`, `/health-safety` and **interact** with them — they are the parity bar. Then study the three patterns you must clone:

- **Golden hero** → `resources/js/components/hr/my-hr-hero.tsx` (`HERO_STYLE` brand‑gradient band, `HeroStat`, `QuickAction`, te‑reo greeting, on‑gradient "needs you" chips). This is the look Chane wants — **but `/hr/feed` must drop the clock** (`MyHrClockCard`) and re‑purpose the right column (§B).
- **Gold‑standard modal** → `resources/js/components/clients/add-client-dialog.tsx` (full‑height bespoke shell: **stepper rail + completeness meter + per‑step validation + server‑error→step mapping + Save & add another + `SuccessPane`**), built on `@/components/wizard/primitives`. This is the modal to replicate for every create flow (§D). Note the markers: `Dialog`+`DialogContent` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, `validateStep()` (~`:710`), `stepForError()` (~`:595`), completeness meter (~`:1013`), "Save & add another" (~`:1094`), `SuccessPane` (~`:2708`), `forceFormData` for uploads.
- **The richer social surface that already exists** → `/hr/my/shoutouts` (`MyHrController::shoutouts/reactKudos/replyKudos`). It already has **emoji reactions** (`heart · party · hands`, toggled, unique per user/emoji) and a **two‑way reply thread** (giver ↔ receiver). The feed wall is **missing both** — surface them here too.

Then audit `/hr/feed` against this **best‑in‑class recognition‑feed + manager checklist** (mark each **Present / Partial / Missing**, then close gaps in §B–§K). Benchmarks: **HiBob Shoutouts**, **Workvivo** (social activity feed, spaces, awards), **Bonusly** (peer‑to‑peer, hashtags/likes/comments), **Kudos** (values‑tagged messages + impact levels + Spaces), **Achievers / Reward Gateway** (recognition analytics, participation, "recognition gaps"), **Workhuman**, **Lattice Praise**.

**Checklist (fill this in as the first pass and paste back the results):**

- **Hero:** golden brand band • recognition stats that matter (kudos this month, participation %, celebrations this week, posts this week) • quick actions (Give recognition / Post update / Make announcement / View insights) • live alert badges (birthdays today, awaiting your acknowledgement, pending nominations) w/ drill‑down • **no clock**.
- **Tabs:** real `FeedTabs` (not pills) • per‑tab counts • **right‑click tab menu** (set default, open, pin) • `?tab=` deep‑link.
- **The wall (posts/kudos):** **reactions** (reuse heart/party/hands) • **comment/reply thread** • **pin** to top • author avatar + `StatusBadge`‑style type chip • **right‑click row menu** • real empty state + skeleton • compose via **modal** not inline.
- **Recognition / Kudos:** values‑/category‑tagged kudos • **impact level** (Thank You / Good Job / Impressive / Exceptional — new) • multi‑recipient • send via Add‑Client‑grade wizard • reactions + replies.
- **Celebrations:** birthdays • work anniversaries • new hires • **one‑tap "Congratulate"** that posts a kudos/shout.
- **Announcements:** surface real `HrAnnouncement`s with **inline acknowledge** + acknowledged count • managers post + pin via wizard.
- **Leaderboard:** top **receivers** *and* top **givers** • time window (week/month/quarter) • by **value/category** • by team/site.
- **Insights (manager/HR, gated):** participation rate (giving + receiving) • recognition reach/coverage • **"who hasn't been recognised"** • trend over time • breakdown by team/site/value.
- **End‑to‑end:** every visible action has a wired route + toast; no dead buttons; employee picker is **tenant‑scoped**.

> **Known gaps the audit already surfaced** (confirm, then fix):
> - **Hero** is the generic `PageHero category="hr"` with the `Rss` icon + 4 plain stats — not the golden band.
> - **"Tabs" are filter pills** (`all/update/kudos/announcement/milestone`) — replace with a real tab shell.
> - **Composer is inline** (`Textarea` + a 2‑option `Select` + Post button) — promote to a compose **modal**.
> - **No reactions, no comments on the wall** even though `HrKudosReaction` (heart/party/hands) + `HrKudosReply` exist and are already used on `/hr/my/shoutouts`.
> - **Kudos modal (`recognition-dialog.tsx`)** uses the *simpler* `@/components/hr/wizard` `WizardShell` — **not** the Add‑Client shell (no completeness meter, no Save & add another, no `SuccessPane`). Upgrade it.
> - **No `is_pinned` toggle** UI/route (the column + `scopePinned` exist but nothing sets it).
> - **No edit / delete / moderate** post path.
> - **No manager analytics** at all (leaderboard is recipients‑only, no time window, no givers, no values split).
> - **`employees` query is NOT tenant‑scoped** in `FeedController::index` (`\App\Models\User::query()->select('id','name')->get()`) — leaks the whole user table into the picker. Fix to tenant scope.
> - **Zero context menus**, no keyboard shortcuts, no skeletons; empty states are bare one‑liners.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — copy the gradient treatment from `resources/js/components/hr/my-hr-hero.tsx`: `HERO_STYLE` (the `linear-gradient` over `--primary` + `boxShadow`; re‑themes per tenant), `HeroStat` (label + big tabular value, clickable), `QuickAction` (icon + label), and the on‑gradient **"needs you" chip** pattern. **Refactor `HERO_STYLE` / `HeroStat` / `QuickAction` into a tiny shared `resources/js/components/hr/hero-kit.tsx`** so My HR, People and Feed share one hero spine (the standardisation win), then build `FeedHero` on top (§B). Tokens: `--primary`, `--primary-foreground`, `--category-hr`, `--hr-amber`. The generic `PageHero` from `@/components/page` stays available as a fallback only.

**2.2 Modals / wizards** — `@/components/wizard/primitives`: `Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `TilePicker`, `Ring`, `IconType`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`. **Reference implementation to clone: `resources/js/components/clients/add-client-dialog.tsx`** (shell shape, stepper rail w/ completeness, `validateStep`, `stepForError`, Save & add another, `SuccessPane`). For the people picker reuse `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`) — already used by `recognition-dialog.tsx`. Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right‑click menus + hover actions** — the app already ships **five** context‑menu implementations; reuse the pattern, don't invent one. Closest references: `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState` — portal‑rendered, viewport‑flipping, Esc/outside‑click close, icon+label+`kbd`+tone) and `@/components/emar/mar/dose-context-menu` (`DoseContextMenu`, wired via `onContextMenu={(e) => onCtx(e, row)}`). Also `@/components/operations/dashboard/context-menu`, `@/components/checklists/context-menu`. Build a `FeedContextMenu` in the same mould.

**2.4 Cards / states / badges** — **`@/components/ui/status-badge` (`StatusBadge`) everywhere** instead of re‑mapping status colours by hand (the page currently hand‑maps `postTypeBadge` — replace). `@/components/ui/card`, `avatar`, `badge`, `empty-state` (`EmptyState`), `error-state`, `loading-state`, `skeleton-card`, `@/components/ui/laravel-pagination` (already used). Reactions/threads should feel like first‑class card chrome, not bolted‑on.

**2.5 Tokens & flourishes** — tokens only in `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`, `--live` (teal). Use Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Toasts: sonner** (`<Toaster>` is mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action, with personality (`Kudos sent! 🎉`). Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`, `animate-ping`) with `motion-reduce:*` guards. Tasteful confetti on sending recognition is welcome.

---

## B. Hero rethink — the golden band (NO clock)

**Current:** generic `PageHero category="hr"` (`Rss` icon, title "Community Feed", description, 4 plain stat pills: Posts/Birthdays/Anniversaries/New hires, and a single "Send Kudos" button). Flat; doesn't match the My HR golden band.

**Do:** build a **`FeedHero`** (in `resources/js/components/hr/feed/feed-hero.tsx`) using the **same gradient + `HeroStat` + `QuickAction` language as `my-hr-hero.tsx`** (via the shared `hero-kit.tsx`, §2.1), sized to this page's content. **No clock card.** Compose:

- **Left column:** title **"Community & Recognition"** + one‑line context ("Celebrate wins, share updates and keep {tenant} connected"). Small icon medallion (`Sparkles` / `Heart`). Optionally the te‑reo time‑aware greeting like My HR, since this is a warm/social surface.
- **Glanceable `HeroStat`s** (recognition, not personal): **Kudos this month**, **Participation** (% of people who gave or received this month — `--hr-amber` accent), **Celebrations this week**, **Posts this week** — each click‑filters the wall or deep‑links the relevant tab.
- **`QuickAction`s:** **Give recognition** (opens the kudos wizard, §D), **Post update** (compose wizard), **Make announcement** (gated `hr.announcements.manage`), **View insights** (gated `hr.recognition.manage`, jumps to the Insights tab).
- **Live alert badges** (drill‑down popover, like `my-hr-hero` "needs you" chips): "{n} birthdays today 🎂", "{n} awaiting your acknowledgement" (announcements), "{n} work anniversaries this week", "{n} pending nominations" (if §K awards ship). Reuse the chip + `NeedsDot` pattern from `my-hr-hero.tsx`.
- **Right column (where My HR puts the clock):** since there's **no clock**, fill it with a page‑appropriate cluster — **"This week's celebrations"**: a compact avatar stack of upcoming birthdays/anniversaries/new hires with a one‑tap **Congratulate** (posts a kudos, §F), or a **participation `Ring`** (from wizard primitives) showing recognition reach this month. This keeps the band balanced without a clock.

---

## C. Tabs — replace the pills with a real `FeedTabs` shell (and go tab‑by‑tab)

Replace the filter‑pill row with a standardised tab strip (mould of `HrTabs` / `MyHrTabs`), `?tab=` deep‑linked, per‑tab counts as badges, **right‑click menu on the tab strip** (§E). Proposed tabs:

1. **Feed** (default) — the unified wall: pinned posts first, then updates + kudos + milestones + announcements interleaved by time. Compose via the **Post update** modal. Each card: author avatar, type chip via `StatusBadge`, timestamp, **reactions** (heart/party/hands w/ counts), **comment thread** toggle, pin indicator, **right‑click menu**. Keep a lightweight in‑tab filter (type/category/author/date) in a clean toolbar — but as a **toolbar**, not the old pills. Real `EmptyState` + `skeleton-card`.
2. **Recognition** — kudos‑centric: the recognition wall (values/category chips, impact level), **Give recognition** primary CTA, reactions + reply threads, a "recently recognised" rail. This is the Bonusly/Kudos‑style heart of the page.
3. **Celebrations** — birthdays / work anniversaries / new hires (today · this week · this month) as friendly cards with avatars; **Congratulate** one‑tap → posts a kudos and (optionally) drops confetti. Source from `FeedService::getMilestones`.
4. **Announcements** — real `HrAnnouncement`s (not the lightweight `announcement` post type): title, body, posted‑by, **inline Acknowledge** + acknowledged count/percentage, pinned banner. Managers (`hr.announcements.manage`) post + pin via the announcement wizard (§D). Read full body in a dialog/sheet.
5. **Leaderboard** — top **receivers** and top **givers** side‑by‑side, with a **time window** segmented control (week/month/quarter/all) and a **by‑value/category** breakdown; optional team/site filter. Extend `FeedService::getKudosLeaderboard` (§H).
6. **Insights** — *gated `hr.recognition.manage`* — the manager/HR analytics tab (§G).

> Per tab: shared list/card + `StatusBadge` chips; real **empty state** (icon + line + CTA) and **skeleton**; every create/compose flow is a **modal** (§D); every card has a **right‑click menu** (§E) + hover actions; **toast** every result.

---

## D. Modals = exact Add‑Client wizard pattern

Every create/compose flow on this page clones `resources/js/components/clients/add-client-dialog.tsx`: same **full‑height bespoke shell** (`Dialog` + `DialogContent [&>button]:hidden`, `flex h-[min(92vh,860px)]`, left **stepper rail** `w-[248px] bg-sidebar` with per‑step icons + blurbs + check‑on‑complete, a **completeness meter** at the rail foot, header "Step X of N", **top progress bar**, scroll‑contained body, footer with Back / Cancel / **Save & add another** / primary), same **engine** (Inertia `useForm`, client‑side `validateStep`, `stepForError` to jump to the offending step, `SuccessPane` after success, `resetAll()` for Save & add another), built from `@/components/wizard/primitives`.

1. **Give recognition (kudos)** — **upgrade `recognition-dialog.tsx`** from the simpler `@/components/hr/wizard` `WizardShell` to the Add‑Client shell. Steps: **Recipient(s)** (`PeoplePicker`, allow **multi‑recipient**) → **Recognition** (`TilePicker` for value/category, a `Segmented` **impact level** — *Thank You / Good Job / Impressive / Exceptional*, new — and the message) → **Review & send** (`ReviewCard` + **Save & add another** + Send). Keep posting to `FeedController::sendKudos` (`/hr/feed/kudos`); extend it for multi‑recipient + impact level (§H). Add tasteful confetti + sonner on success.
2. **Post update / Compose** — replace the inline composer. Steps: **Type** (`TilePicker`: Update / Announcement / Question) → **Message** (textarea, optional image/attachment via `forceFormData`, optional audience/site scope) → **Review & post**. Posts to `FeedController::store` (`/hr/feed`); extend rules if attachments/audience are collected (§H).
3. **Make announcement** (gated `hr.announcements.manage`) — Steps: **Title** → **Body** (rich text) → **Audience & options** (sites/departments, *require acknowledgement?*, *pin?*) → **Review & publish**. Reuse the **Announcements module** (`HrAnnouncement` + `AnnouncementController`), don't fork it.
4. **Nominate for award** (Phase 2, §K — confirm first) — Steps: **Nominee** → **Award/criteria** → **Why (evidence)** → **Review & submit**.

> Wire each modal from `index.tsx` like today (`open` state + `<RecognitionDialog … />`), opened from the hero `QuickAction`s and the tab CTAs.

---

## E. Right‑click everywhere (cards **and** tabs)

Chane explicitly wants right‑click options "under tabs etc." Build a `FeedContextMenu` (mould of `ShiftContextMenu`) and wire `onContextMenu` on:

- **Feed/recognition cards:** **React** (heart/party/hands) · **Comment** · **Copy link** · **Pin / Unpin** (gated `hr.recognition.manage`) · **Edit** (author, within a grace window) · **Delete / Hide** (author or `hr.recognition.manage`, confirm via `alert-dialog`) · **Give kudos to {author}** · for a milestone card, **Congratulate**. Gate destructive/manager items; show `kbd` hints.
- **Celebration cards:** **Congratulate** · **Send kudos** · **View profile**.
- **Announcement cards:** **Acknowledge** · **Open** · **Pin/Unpin** + **Edit** (manager).
- **Leaderboard rows:** **Give kudos** · **View profile** · **View their recognition**.
- **The tab strip itself:** right‑click a tab → **Set as default view**, **Open**, **Pin**, (and once Saved Views land) **Save current view**. Persist "default tab" + pins to `localStorage` (allowed) so it survives reloads.

Every menu action fires a toast and, where it writes, hits a real route (§H). No dead items.

---

## F. The wall upgrade — reactions, comments, pinning (wire the existing backend)

Make each card a first‑class social object (reuse the shared card chrome; don't hand‑roll):

- **Reactions** — heart/party/hands with live counts and an "I reacted" state; optimistic toggle. **Reuse the existing toggle endpoint/shape** (`HrKudosReaction`, the `react` route already used by `/hr/my/shoutouts`). For non‑kudos posts, see §H (generalise reactions to posts, or keep reactions kudos‑only in Phase 1 and confirm the post‑reaction build).
- **Comments / replies** — inline thread under a card, newest‑collapsed with a "view N comments" toggle; compose box at the foot. **Reuse `HrKudosReply`** for kudos. For non‑kudos posts, see §H. Today replies are gated to giver ↔ receiver — for the public wall, confirm the intended visibility (likely: anyone with `hr.recognition.view` can comment; keep edit/delete to the author) before widening the gate.
- **Pinning** — pinned posts render first with a pin affordance; toggle via the new pin route (gated `hr.recognition.manage`). `is_pinned` + `scopePinned` already exist on `HrFeedPost`.
- **Congratulate** — on a celebration card, one tap opens the kudos wizard pre‑filled (recipient = the celebrant, category = a "Celebrations" value) or posts a quick shout; confetti + toast.
- **Card polish** — real avatars (fall back to coloured initials), `StatusBadge` type chips, relative timestamps (`diffForHumans`, already provided), hover lift, `animate-in` on new cards.

---

## G. Insights tab — the manager/HR view (gated `hr.recognition.manage`)

This is what makes `/hr/feed` "the HR and manager view" rather than just a wall. Add an **Insights** tab (and a hero **View insights** quick action) showing recognition health, benchmarked on Achievers/Reward Gateway/Workhuman analytics:

- **Participation rate** — % of people who **gave** and % who **received** recognition in the window (the clearest program‑health signal); trend vs previous period.
- **Recognition reach / coverage** — how widely recognition spreads; **"who hasn't been recognised"** (people with zero kudos received in the window) so managers can close gaps. Flag teams/sites trending toward disengagement.
- **Volume & cadence** — kudos/posts over time (line), by **value/category** (bar/donut — reuse the `Ring`/chart primitives), by **team/site/department**.
- **Top givers & receivers** — same data as Leaderboard but framed for managers, filterable by team/site.
- **Manager scope** — a manager sees their team by default; HR/admin can switch scope (all sites). Respect `ResolvesHrTenant`.
- Keep it glanceable: KPI cards up top (participation, reach, total kudos, avg per person), then 2–3 charts, then the "needs attention" list. Empty/skeleton states like everywhere else.

> Back it with a real analytics endpoint (§H). Don't fabricate numbers — compute from `HrKudos` / `HrFeedPost` within the window + tenant scope.

---

## H. Backend work summary (end‑to‑end check)

**Exists & wired — keep using:**

- Feed index / store / sendKudos: `GET /hr/feed`, `POST /hr/feed`, `POST /hr/feed/kudos` (`routes/hr.php` feed group ~`:811`; `FeedController`).
- Social engine: `FeedService::{createPost, sendKudos, getFeed, getMilestones, getKudosLeaderboard}`; constants `KUDOS_CATEGORIES`, `POST_TYPES`.
- Reactions + replies: `HrKudosReaction` (emoji `heart|party|hands`, unique per `kudos_id+user_id+emoji`, toggled) and `HrKudosReply` (`body`) — already exercised by `MyHrController::{reactKudos, replyKudos}` on `/hr/my/kudos/{kudos}/react|reply`. **Reuse these** (consider promoting the react/reply routes into the `feed.` group, or expose feed‑scoped aliases that call the same service path).
- Pinning column: `HrFeedPost.is_pinned` + `scopePinned`.
- Announcements: `HrAnnouncement` + `AnnouncementController` + `hr.announcements.manage` (acknowledge already supported).
- Permissions exposed to the client: `can.recognition.{view,give}` via `HandleInertiaRequests`.

**Fix (bugs / scoping):**

1. **Tenant‑scope the employee picker** in `FeedController::index` — the current `\App\Models\User::query()->select('id','name')->get()` is **not** tenant‑scoped (leaks all users). Scope to the resolved HR tenant (mirror how other HR controllers build their people lists).

**Missing — build (spec → confirm → implement; gate manager actions on a new `hr.recognition.manage`, respect `ResolvesHrTenant`):**

2. **`hr.recognition.manage` permission** — new key (migration + seeder), mirroring `hr.recognition.give` / `hr.announcements.manage`. Grants pin, moderate/delete others' posts, and Insights. Expose `can.recognition.manage` via `HandleInertiaRequests`.
3. **Pin toggle** — `POST /hr/feed/{post}/pin` (toggle `is_pinned`), gated `hr.recognition.manage`. Return new state for the toast.
4. **Edit / delete post** — `PUT /hr/feed/{post}` (author, grace window) + `DELETE /hr/feed/{post}` (author or `hr.recognition.manage`). Cascade/soft‑delete the linked kudos sensibly.
5. **Reactions + comments on the wall** — Phase 1: reuse kudos react/reply for kudos cards. Phase 2 (confirm): **generalise reactions + comments to all `HrFeedPost`s** (either polymorphic `reactable`/`commentable` tables, or `hr_feed_post_reactions` + `hr_feed_post_comments` mirroring the kudos tables). Decide visibility: public wall comments for anyone with `hr.recognition.view`, edit/delete by author. Write the short migration spec and confirm before building.
6. **Multi‑recipient + impact level for kudos** — extend `sendKudos` to accept `to_user_ids[]` (loop/create N) and an optional `impact` enum (`thank_you|good_job|impressive|exceptional`); add an `impact` column to `hr_kudos` (nullable, default `good_job`). Update `FeedService::sendKudos` + validation. Keep single‑recipient back‑compatible.
7. **Leaderboard enhancements** — extend `getKudosLeaderboard` for a **time window**, **top givers** (group by `from_user_id`), and **by value/category**; optional team/site filter.
8. **Recognition analytics endpoint** — `GET /hr/feed/insights` (or a controller method feeding the Insights tab), gated `hr.recognition.manage`: participation (givers/receivers %), reach/coverage, "not yet recognised" list, volume over time, by value, by team/site — all tenant‑scoped + window‑parameterised.
9. **Compose extras (only if collected, §D‑2)** — extend `store` + request rules for optional **attachment** (`forceFormData`) and **audience/site scope** (nullable; keep quick‑post working).
10. **Announcements surfacing** — read `HrAnnouncement`s into the Announcements tab + hero acknowledge badge via the existing module (no new writes beyond what the module already exposes).
11. **(Phase 2, confirm) Nominations & awards** — `hr_award_programs` + `hr_award_nominations` (nominee, criteria, evidence, status, approver) with a nominate wizard + manager review. Bigger build — spec and confirm before starting.

> For each missing item: write a short spec + migration (if any) and **confirm before building**. Don't silently invent schema.

---

## I. Premium polish & delight

- **Avatars** with real photos (fall back to coloured initials — reuse the existing `getAvatarColor` helper used elsewhere).
- **Toasts with personality** on every create/react/pin/acknowledge action (sonner). Tasteful **confetti** on sending recognition / a celebration congratulate (`motion-reduce`‑safe).
- **Micro‑interactions** — reaction pop (`zoom-in-95`), `animate-ping` on a fresh celebration, hover lift on cards, progress `Ring` in the hero/insights — all guarded by `motion-reduce:*`.
- **Keyboard:** `/` focuses the wall search, `n` opens Post update, `k` opens Give recognition, arrow/Enter on cards, Esc closes menus/dialogs.
- **Loading/empty/error:** every tab gets a `skeleton-card` while loading and a friendly `EmptyState` (icon + line + primary CTA) when empty — no bare "No posts yet." line.
- **Consistency sweep:** all type/status chips via `StatusBadge`; delete the hand‑rolled `postTypeBadge` colour map; no raw hex anywhere.

---

## J. Definition of done

- `/hr/feed` hero is the **golden HR band** (gradient, `HeroStat`s, `QuickAction`s, live alert badges, celebrations/participation right‑column) — **no clock** — visually on par with `my-hr-hero`; built on the shared `hero-kit.tsx`.
- The filter pills are gone, replaced by a real **`FeedTabs`** shell (`?tab=`, per‑tab counts) with **Feed · Recognition · Celebrations · Announcements · Leaderboard · Insights**.
- Every create/compose/recognise/announce flow is an **Add‑Client‑grade wizard** (stepper rail + completeness + per‑step validation + server‑error→step + **Save & add another** + `SuccessPane`); the kudos modal is upgraded off the simpler `WizardShell`.
- The wall has **reactions + comments + pinning**, wired to the existing/extended backend; **right‑click menus** on cards **and** the tab strip; every item wired + toasted; `kbd` hints shown.
- **Insights** tab (gated `hr.recognition.manage`) shows participation, reach/coverage, "not yet recognised", and trend/by‑value/by‑team — computed from real data.
- **End‑to‑end:** pin, edit/delete, react, comment, congratulate, acknowledge, leaderboard windows and insights all hit real routes; the employee picker is **tenant‑scoped**; **no dead buttons**.
- NZD / `en-NZ` retained; `ResolvesHrTenant` scoping and `hr.recognition.*` / `hr.announcements.*` gates respected; **no regressions** to `/hr/my/shoutouts`, the Announcements module, or the shared `FeedService`.
- Clean `build`, `types`, `lint`; screenshots of each tab + each modal match the reference pages.
- **Adoption signals to watch:** weekly active people on `/hr/feed`, kudos sent/week, **participation rate**, % of staff recognised (coverage), comments/reactions per post, announcements acknowledged.

**Build order:** §A audit (paste results) → **`hero-kit.tsx` + `FeedHero`** (golden band, no clock) → **`FeedTabs`** shell (retire the pills) → wall upgrade: **reactions + comments + pin** + `StatusBadge`/empty/skeleton + **right‑click** (§E/§F) → **upgrade kudos modal** + add **Post update** / **Make announcement** wizards (§D) → backend: tenant‑scope fix, `hr.recognition.manage`, pin/edit/delete, multi‑recipient+impact, leaderboard, insights endpoint (§H) → **Insights** tab (§G) → delight pass (§I). Verify each pass against the reference pages.
