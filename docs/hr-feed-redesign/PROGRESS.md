# HR Community & Recognition Feed — Gold-Standard Redesign

Self-paced `/loop` rebuild of `/hr/feed` to the H&S gold standard, from the
design drop `Downloads/HR Feed Page.zip` (comp `Community & Recognition.dc.html`,
screenshots, and the authoritative handoff `RECOGNITION_WIZARD_REUSE.md`).

Worktree: `.claude/worktrees/nervous-rubin-e1cc1e` · branch `claude/nervous-rubin-e1cc1e`.

## Design intent (from comp + reuse doc)

- **Golden-band hero** (option A of 3 explored) — bespoke gradient band mirroring
  the My HR hero idiom (NOT `PageHero`). Eyebrow `MŌRENA · MONDAY, 22 JUNE`, title
  **Community & Recognition**, subtitle, 4 KPIs (Kudos this month / Participation /
  Celebrations / Posts this week), 4 quick actions (Give recognition · Post update ·
  Make announcement · View insights), and a **This week's celebrations** strip.
- **Feed wall** — composer bar, filter tabs (All / Updates / Kudos / Notices),
  rich cards: pinned **announcements** with Acknowledge + progress (e.g. "41 of 84
  acknowledged · 49%"), **kudos** cards showing from→to + **impact** badge
  (Thank You / Good Job / Impressive / Exceptional) + **value** badge (= category,
  e.g. "Above & Beyond" = `going_above`) + reactions (heart/party/hands) + replies,
  plus plain **update** cards.
- **Right sidebar** — Top recognised leaderboard (this month) + Celebrations list.
- **Search the wall** input in the page top bar.
- **One shared wizard family** (`components/recognition/`) used everywhere:
  `RecognitionWizard` (Give recognition), `ComposeWizard` (Post update),
  `AnnounceWizard` (Make announcement, gated `hr.announcements.manage`) — all on the
  Add-Client-grade `WizardShell` (rail + completeness + per-step validate +
  stepForError + Save & add another + SuccessPane). One backend path, no forks.

## Architecture decisions

- **WizardShell == the Add-Client shell** (it was extracted from
  `add-client-dialog.tsx`): rail, completeness `pct`, progress strip, success pane,
  ReviewCard/ReviewRow. Build the 3 wizards on it + `wizard/primitives` rather than
  forking the 2766-line dialog.
- **Reactions/replies stay keyed to `HrKudos`** (per reuse doc) — kudos cards get
  react+reply; **announcements** get Acknowledge+progress (their native action);
  plain update/milestone posts are display-only in v1. (Polymorphic
  reactions-on-every-post = deferred, see below.)
- **Announcements are surfaced inline** in the wall from the `HrAnnouncement`
  module (NOT forked): active/pinned announcements render as Notice cards with
  Acknowledge. `AnnounceWizard` writes through `AnnouncementController::store`.
- **Multi-recipient kudos** = one `HrKudos` (+ feed post) per recipient in a single
  transaction; single-recipient stays back-compatible.
- **No new permissions** — `hr.recognition.view|give` + `hr.announcements.view|manage`
  are all already seeded. Perms checked via `permission:` middleware (routes) and
  `canDo()` (controllers). NOT Laravel `->can()`.

## Worktree tooling (this is a fresh harness worktree, no junctions by default)

- `node_modules` → **junctioned** from parent (branch-independent) so npm works.
- `resources/js/routes` + `resources/js/wayfinder` (gitignored, generated) →
  **junctioned** from parent so tsc baseline is clean (the ~30 `@/routes` errors are
  environmental, not ours).
- PHP: Herd `C:\Users\steph\.config\herd\bin\php84\php.exe` for `php -l` only.
  ⚠️ Do NOT run artisan/composer/migrate in the worktree (no vendor; would autoload
  parent). PHP tests + Chrome-verify happen post-merge from PARENT / on `.com`.

## Plan & status

### Phase 1 — Backend foundation ✅ (commit pending)
- [x] Migration `2026_06_22_000002_add_impact_to_hr_kudos` — nullable `impact`
      string (default `good_job`).
- [x] `HrKudos`: `impact` fillable.
- [x] `FeedService`: `KUDOS_IMPACTS`/`REACTION_EMOJIS` consts; `sendKudos(... ?impact)`;
      `sendKudosToMany`; `toggleReaction`/`addReply`; `getMetrics`;
      `getFeedAnnouncements`; `getFeed` eager-loads reactions+replies; leaderboard
      scoped to this month; milestones carry `days_away`.
- [x] `FeedController`: fixed tenant leak (`tenantEmployees` via HrEmployeeProfile +
      `tenantSites`); `index` adds `metrics`, `announcements`, reactions/replies
      summary, impact, `currentUserId`, `can.manageAnnouncements`; `sendKudos`
      accepts `to_user_ids[]` + `impact`; feed-scoped `react`/`reply` endpoints.
- [x] `routes/hr.php`: feed `kudos/{kudos}/react` + `/reply` (gated `give`).
- [x] (DRY) `MyHrController` react/reply → call `FeedService` (imports trimmed).
- [x] `php -l` all touched PHP files — clean. tsc baseline 0 (after junctions).
- Note: old feed page still renders (new props additive); PHP tests deferred to P6.

### Phase 2 — Shared wizard family (`resources/js/components/recognition/`) ✅ (commit pending)
- [x] `recognition-wizard.tsx` — Recipients (multi) → Recognition (value TilePicker +
      impact Segmented + message) → Review & send. `{open,onClose,onSuccess?,
      employees,kudosCategories,kudosImpacts,defaults?}`. Save & add another +
      SuccessPane. POSTs `to_user_ids[]`+impact to `/hr/feed/kudos`.
- [x] `compose-wizard.tsx` — Type (Update/Question/Win) → Compose → Review & post.
      POSTs `/hr/feed`.
- [x] `announce-wizard.tsx` — Title → Body & options (audience/sites Segmented,
      priority, require-ack + pin toggles) → Review & publish. POSTs `/hr/announcements`
      (store now `redirect()->back()` so it works from the feed too).
- [x] `index.ts` barrel. tsc 0 · eslint 0 on the new dir.

### Phase 3 — Feed page redesign ✅ (commit pending)
- [x] `components/hr/feed-hero.tsx` (golden brand-gradient band) — te-reo eyebrow,
      4 KPIs, 4 quick actions, this-week celebrations strip (congratulate → wizard).
- [x] `pages/hr/feed/parts.tsx` — KudosCard (value+impact badges, reactions toggle,
      reply thread), AnnouncementCard (acknowledge + progress), UpdateCard,
      TopRecognised, CelebrationsCard, FeedEmpty + shared types/helpers.
- [x] Rewrite `pages/hr/feed/index.tsx` — hero + composer bar + filter tabs
      (All/Updates/Kudos/Notices) + wall (announcements + posts) + search + sidebar.
- [x] Wired all 3 wizards (hero actions, composer icons, congratulate pre-fill).
- [x] tsc 0 · eslint 0 · vite build 0 (verified by temporarily disabling the
      wayfinder codegen plugin — needs php/vendor — then reverting; routes present
      via junction). "View insights" → `/hr/analytics` for now (P5 may add a modal).

### Phase 4 — Cross-module reuse
- [ ] `/hr/my` hero "Send kudos" + `/hr/my/shoutouts` → `RecognitionWizard`
      (retire `my-hr-kudos-wizard.tsx`; update `useSendKudos`).
- [ ] Retire `recognition-dialog.tsx` → `RecognitionWizard` (feed call site).

### Phase 5 — Insights
- [ ] "View insights" → modal (participation, top values, kudos trend, leaderboard).

### Phase 6 — Verify
- [ ] tsc 0 (vs baseline) · eslint 0 · vite build · pint/`php -l`.
- [ ] PHP tests (FeedController multi+impact, react/reply, tenant-scope) — run from
      PARENT post-merge.
- [ ] Adversarial review pass.

### Phase 7 — Ship
- [ ] Merge → origin/main · deploy webhook · Chrome-verify on oblivionfindings.com.

## Deferred / follow-ups
- Polymorphic reactions on every post type (currently kudos-only per reuse doc).
- Compose attachments + per-post audience scoping (reuse doc marks these optional).

## Log
- 2026-06-22: Recon complete. Mapped backend (FeedController/FeedService/HrKudos*/
  HrAnnouncement) + frontend (feed page, recognition-dialog, my-hr-kudos-wizard,
  WizardShell, my-hr-hero). Tooling set up. Starting Phase 1.
