# Wellbeing & Engagement Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (**Claude design** — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, **screenshot** `/hr/wellbeing` (and `?tab=overview|surveys|action-plans|signals`), the survey detail page, every modal, and `/hr/my/surveys`, then **diff against the gold-standard pages/components** before continuing. Start with the audit in §A, then build §B–§L.
>
> **Anything you discover that needs backend/data work goes into §K "Backend handoff for Claude Code" — append to it as you go, and mirror the final list into a new `WELLBEING_BACKEND_HANDOVER.md` so Chane has one clean hand-off for Claude Code. Don't silently invent schema — spec → confirm → implement.**

**Page (canonical):** `/hr/wellbeing` (route `hr.wellbeing.index`, permission `hr.wellbeing.view`; manager actions gated on `hr.performance.manage`). Survey detail: `/hr/wellbeing/surveys/{survey}` (`hr.wellbeing.surveys.show`). Employee mirror: `/hr/my/surveys`.
**Frontend:** `resources/js/pages/hr/wellbeing/index.tsx` (1,208 lines — the long-scroll page to break up), `resources/js/pages/hr/wellbeing/survey.tsx` (863 lines — detail/respond/results), `resources/js/pages/hr/my/surveys.tsx` (employee respond).
**Backend:** `app/Http/Controllers/Hr/WellbeingController.php`; services `app/Domain/Hr/Services/EngagementService.php` + `app/Domain/Hr/Services/WellbeingIndicatorService.php`; routes in `routes/hr.php` (search `prefix('wellbeing')`, line ~515).
**Models:** `HrEngagementSurvey`, `HrEngagementSurveyQuestion`, `HrEngagementSurveyResponse`, `HrEngagementActionPlan`, `HrWellbeingIndicator` (all `app/Domain/Hr/Models/`). Jobs: `CalculateWellbeingIndicatorsJob`, `SendEngagementActionPlanRemindersJob`. Notifications (already wired): `EngagementSurveyInvitationNotification` (fires on publish), `EngagementActionPlanDueNotification` (reminder job), `StaffFatigueAlertNotification` (calc job → managers).
**Gold-standard modal to clone:** `resources/js/components/clients/add-client-dialog.tsx` (the full Add-Client wizard) built on `WizardShell` (`resources/js/components/wizard/shell.tsx`) + primitives (`resources/js/components/wizard/primitives.tsx`), via the HR barrel `@/components/hr/wizard`. **Premium modal reference:** `resources/js/components/hr/leave-request-dialog.tsx` (the `/hr/leave` "New request").
**Hero reference:** `resources/js/components/hr/people-hero.tsx` — the **golden band with NO clock** (same `HERO_STYLE` as `my-hr-hero.tsx`, right column is a `Ring` not a clock). **Tabs:** `resources/js/components/hr/hr-tabs.tsx`. **Right-click:** mirror `resources/js/components/hr/leave-context-menu.tsx`.

---

## 0. Mission

Bring `/hr/wellbeing` to full parity with our best HR hubs (`/hr/people`, `/hr/leave`, `/meds/today`, `/my-day`). Today it is **one long scroll** with an old `PageHero`, a stack of KPI cards, an **inline survey builder**, a `Surveys` list, an `Engagement Action Plans` grid, and a `Flagged Wellbeing Indicators` list — every create/edit flow is a **thin inline form** (or a bare `AlertDialog`), there are **no tabs, no wizard modals, no right-click menus, and no golden hero**. Worst of all, the **duty-of-care loop is open**: a manager sees a red-flagged staff member but **cannot act on it from the page** — no check-in, no acknowledgement, no EAP referral, no "make an action plan for this person."

**Result:** a four-tab hub (Overview · Surveys · Action plans · Wellbeing signals) on the shared kit, a golden wellbeing hero (no clock), every workflow a **full Add-Client-grade wizard** (not a thin form), right-click everywhere, and a **closed loop** — every flag, survey and action plan can be acted on end-to-end, and the employee side on `/hr/my` reflects it. Anything needing server work is specced in §K and handed to Claude Code.

## 1. Non-negotiables

1. **Tabs, not scroll.** Use the shared `hr-tabs` shell. Four tabs: **Overview · Surveys · Action plans · Wellbeing signals**. Deep-link with `?tab=`. Preserve scroll/filter per tab.
2. **Reuse the kit — never hand-roll a primitive we already have.** No new bespoke widgets, no raw hex (ESLint blocks it — colours come from design tokens in `resources/css/app.css`). Use `WizardShell`, `Field`, `SelectInput`, `Segmented`, `TilePicker`, `Ring`, `StepHead`, `HeroStat`, `QuickAction`.
3. **No thin modals.** Every create/edit/respond flow is a **full multi-step wizard** matching `add-client-dialog.tsx` (left step rail with tick-on-complete, "Step x of y" header, 3px progress strip, muted footer, success pane). Kill the inline survey builder and the bare `AlertDialog` create/respond forms.
4. **No dead buttons.** Every action hits a real route or is appended to §K. If a flow needs an endpoint we don't have, build the UI against the spec **and** write the spec into §K.
5. **Single source of truth.** Surveys, responses, action plans and wellbeing indicators stay owned by `EngagementService` / `WellbeingIndicatorService`. Don't fork a second store; don't recompute flag rules in the client — render what the server sends.
6. **Web-only desktop app. No phone frames.** Design for mouse + keyboard: hover states, **right-click menus**, keyboard shortcuts. (Mobile app comes later.)
7. **NZ locale.** `en-NZ`, NZD via `formatNzd` where money appears, NZ date formatting. This is a NZ supported-living provider — wellbeing language should be plain-English, mana-enhancing, not clinical jargon.
8. **Permissions/scoping.** Tenant-scoped via `ResolvesHrTenant`; `hr.wellbeing.view` to see, `hr.performance.manage` for manager actions. Hide manager-only UI (builder, publish, action-plan create, flag triage, check-ins, EAP) when the user lacks the gate. Respect survey **anonymity** — never surface respondent identity for `is_anonymous` surveys, even in new UI.
9. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface and diff vs the reference pages.

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — clone the golden band from `resources/js/components/hr/people-hero.tsx` (it already uses `HERO_STYLE` with **no clock**). Do **not** copy `my-hr-hero.tsx`'s right column — that's `<MyHrClockCard>`; **omit the clock entirely** (no clock on this page, per Chane). Reuse `HeroStat` (label + big tabular value, clickable `href`/`router.visit`) and `QuickAction` (icon + label). If you extract a shared spine while you're here, add `resources/js/components/hr/hero-kit.tsx` and refactor people/compensation/calendar heroes onto it (standardisation win — confirm with Chane before the wider refactor).

**2.2 Modals/wizards** — `WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow` from `resources/js/components/wizard/shell.tsx`; field/input primitives (`Field`, `FieldErr`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `StepHead`, `SubHead`, `InfoCard`, `Ring`, `IconType`) from `resources/js/components/wizard/primitives.tsx`; `useWizard(stepCount)` + `WizardStep` type via the barrel `@/components/hr/wizard`. The canonical visual spec (per-step `validateStep`, `stepForError`, completeness `pct`, "Save & add another", success pane with `onAddAnother`) lives in `resources/js/components/clients/add-client-dialog.tsx` — match it. The leaner configuration (live preview rail, `pct={null}`) is `leave-request-dialog.tsx` — match it for the respond flow.

**2.3 Right-click menus + hover actions** — mirror `resources/js/components/hr/leave-context-menu.tsx` (built on `@/components/ui/context-menu`). Add a new `resources/js/components/hr/wellbeing-context-menu.tsx`. Every row (survey, action plan, flagged-staff) gets a right-click menu **and** a hover "⋯" affordance with the same items. Right-clicking a **tab** offers the relevant "new" action (Chane explicitly wants right-click options "under tabs etc.").

**2.4 Cards/tables/states/badges** — shadcn `Card`, `Badge`, `Button`, `Table`. Use status tokens (`bg-status-success-bg text-status-success`, `status-warning`, `status-critical`) for flag levels and SLA states. Every list needs a real **empty state** (icon + line + primary action), **loading** skeletons, and **error** state — no blank `<div>`s.

**2.5 Tabs** — `resources/js/components/hr/hr-tabs.tsx`. Tab labels carry **live count badges** (e.g. Surveys = open count, Action plans = overdue count in amber, Signals = red-flag count).

**2.6 Tokens & flourishes** — `tailwindcss-animate` with `motion-reduce:*` guards; **sonner** `toast` on every action; `fireConfetti()` (`resources/js/lib/confetti.ts`) on celebratory completions (survey published, action plan completed, all-caught-up). Numeric values use `tabular-nums`.

---

## A. Audit & benchmark first (do this before building)

Study `/hr/people`, `/hr/leave`, `/meds/today`, `/my-day` and **interact** with them — they are the parity bar. Open the Add-Client wizard and the Leave "New request" modal and feel how they flow. Then walk the current `/hr/wellbeing` and `/hr/wellbeing/surveys/{id}` and confirm the gaps below.

**Checklist:**

- [ ] Map every current section in `index.tsx` to a destination tab (KPI cards → Overview; inline builder → Surveys tab + Survey Builder wizard; Surveys list → Surveys tab; Action Plans grid → Action plans tab; Flagged Indicators → Wellbeing signals tab).
- [ ] Confirm the props the controller already sends: `wellbeingSummary`, `flaggedStaff` (with `triggered_rules` + `metrics`), `surveys`, `actionPlans`, `slaSummary`, `ownerWorkload`, `actionPlanOwners`, `filters`, `can.manage`. Reuse them — don't refetch.
- [ ] Confirm the 9 real flag rules in `WellbeingIndicatorService::FLAG_RULES` and render them verbatim as the "why flagged" reasons (don't reword thresholds): **RED** — worked 12+ consecutive days · 20+ overtime hrs in period · 10+ sick days in 90d · avg shift > 12h. **AMBER** — 7+ consecutive days · 10+ overtime hrs · 3+ sick days in 30d · no rest day in 7d · avg shift > 10h.
- [ ] Confirm survey types (`pulse`, `enps`, `engagement`), question types (`enps`, `scale`, `text`, `choice`, `boolean`), and lifecycle (`draft → published → closed`); anonymity via `respondent_hash`.
- [ ] Confirm action-plan fields: `owner_user_id`, `title`, `description`, `priority` (low/medium/high), `status` (open/in_progress/completed/cancelled), `progress_percent`, `due_date`, `completed_at`. Note the constraint: **action plans are currently created only under a survey** (`storeActionPlan(survey)`). Creating one from a wellbeing flag needs a standalone path → §K.

> **Known gaps the audit already surfaced (confirm, then fix):**
> 1. **No tabs / no golden hero / no wizard modals / no right-click** — pure kit debt.
> 2. **Open duty-of-care loop** — `flaggedStaff` is read-only. No acknowledge / snooze / dismiss, no check-in, no EAP referral, no "create action plan from this flag." (Biggest miss.)
> 3. **Thin survey builder** — inline card with a hand-rolled question list; needs the full Survey Builder wizard (basics → questions → audience → review/publish) with **templates**.
> 4. **Thin respond flow** — `survey.tsx` and `/hr/my/surveys` use a bare form/`AlertDialog`. Needs a premium guided respond modal (anonymity reassurance, eNPS 0–10 picker, progress).
> 5. **Thin action-plan flows** — create + update are inline; no notes/timeline, no reassign, no reopen, no "create from flag/survey" entry points.
> 6. **No nudge / clone / export** on surveys despite `EngagementSurveyInvitationNotification` already existing.
> 7. **My HR loop incomplete** — `/hr/my/surveys` is on the new `MyHrShell` but the respond is thin, invitations aren't surfaced in the hero "needs you," and staff can't see/acknowledge check-ins logged about them.

---

## B. Hero rethink — the golden band, NO clock, fitted to wellbeing

Replace the old `PageHero` with the `people-hero` golden band (`HERO_STYLE`, `text-primary-foreground`, decorative orb). **No clock.**

- **Eyebrow:** "Wellbeing & engagement". **Title:** warm, plain-English (e.g. "Looking after the team"). **Subtitle:** "{total_staff} people · {flagged_red} need attention · {open_plans} active plans" with lucide icons.
- **`HeroStat` cluster (clickable, deep-link to the relevant tab/filter):** Staff total → Overview · **Red flags** (`amber` value style) → Signals?flag=red · Amber flags → Signals?flag=amber · Open action plans (+ overdue sub-count) → Action plans · Latest eNPS / pulse sentiment → Surveys · Response rate of the live survey → Surveys.
- **Right column (instead of the clock):** a `Ring` gauge — % of staff with **no flags** ("green"), or the latest **eNPS gauge** (-100…+100). Pick the one with live data; show the other as a small stat.
- **`QuickAction` row:** New survey · Log check-in · New action plan (manager-gated).
- **Footer "needs you" strip** (like `my-hr-hero`): overdue action plans · surveys closing in ≤3 days · **unacknowledged red flags** — each a chip with an amber `NeedsDot`, collapsing to "N things need you," or "All caught up ✓" at zero.

## C. Tabs — the shell

Wrap the page body in `hr-tabs` with **Overview · Surveys · Action plans · Wellbeing signals**, count badges per §2.5, `?tab=` deep-linking, and a right-click-on-tab "new …" action (§J). Keep the hero **above** the tab strip (persistent), exactly like `/hr/people`.

## D. Overview tab (new)

The at-a-glance command surface. Build from existing props — no new data needed:

- **At-risk roll-up** — red/amber staff from `flaggedStaff` as compact cards (name, role, top triggered rule, a "Triage" button → Flag triage modal). "View all" → Signals tab.
- **Action SLA band** — from `slaSummary`: On track / Due soon / Overdue counters (status-coloured), click-through to Action plans filtered.
- **Survey sentiment** — latest published survey: response rate progress, eNPS/sentiment `Ring`, "closes in N days," nudge button if manager.
- **Owner workload** — from `ownerWorkload` (manager only): top owners by open plans, mini bars.
- **"Needs you" list** — the same items as the hero footer, expanded with one-click actions.

## E. Surveys tab

- **List** rebuilt as premium rows: title, type pill (Pulse/eNPS/Engagement), status pill (Draft/Published/Closed), window dates, **response rate bar** (`response_count` / recipients), question count. Row hover → "⋯"; right-click → §J.
- **Primary action:** "New survey" → **Survey Builder wizard** (§H-1). Empty state when none.
- **Filters:** status, type, mine/all. Sort by recency / response rate.
- **Survey detail** (`survey.tsx`) rebuilt: keep the golden hero (compact), three sub-sections — **Respond** (if `can.respond`, opens the premium Respond modal), **Results** (manager: sentiment charts from `EngagementService::summary` — eNPS breakdown promoters/passives/detractors, per-question distributions, free-text responses respecting anonymity), and **Action plans** spawned from this survey (with "New action plan" → wizard). Replace the bare publish/close `AlertDialog` with a styled confirm that explains consequences and toasts.

## F. Action plans tab

- **Board or list** of `actionPlans`: title, owner avatar, priority pill, status, **progress ring/bar**, due date with overdue (`is_overdue`) / due-soon (`is_due_soon`) treatment, linked survey or flag.
- **Filters:** status, owner (managers see `actionPlanOwners`), overdue-only.
- **Row actions** (§J): Open · Update progress · Reassign owner · Add note · Complete · Reopen · Cancel.
- **Update Action Plan modal** (§H-4) — not a thin field; a proper modal with status, **progress slider**, a **notes timeline**, and complete/reopen. Owners can update status/progress; managers can edit everything (mirror the controller's `prohibited`-vs-`sometimes` rules so the UI matches permissions).
- **Create** entry points: from a survey, from a flagged staff member (Signals), or standalone "New action plan."

## G. Wellbeing signals tab — close the loop

This is the duty-of-care heart. Render `flaggedStaff` as a triage surface, not a static list:

- **Flag cards/rows:** staff name + role, **flag level** pill (red/amber), the **triggered rules** verbatim, and the supporting **metrics** (overtime hrs, consecutive days, sick days 30/90d, shifts in 7d, avg shift length) as small stat chips. Sort by risk (red first), then severity.
- **Every flag is actionable** (manager-gated) via primary buttons + right-click (§J): **Log check-in** (§H-5) · **Create action plan** (§H-3, pre-linked to this person) · **Refer to EAP** (§H-6) · **Acknowledge** (records "seen, handling it") · **Snooze** (pick a date — hides until then) · **Dismiss** (with reason). Acknowledged/snoozed flags get a quiet treatment so the list shows what still needs attention.
- **Drill-in:** "View time & roster" → the person's `/hr/time` / roster; "View profile" → staff profile. Show last check-in date + open action plans inline so the manager sees history before acting.
- **Trend:** a small sparkline of the staff member's flag history if available (§K — indicators are point-in-time today).

## H. Modals — the exact Add-Client wizard pattern (full, not thin)

Every one of these is a `WizardShell` wizard with a left step rail, tick-on-complete, "Step x of y," 3px strip, muted footer, success pane, sonner toast, and (where it fits) a live preview rail like Leave. **No inline forms.**

1. **Survey Builder wizard** (create/edit) — replaces the inline builder.
   - **Step 1 · Basics:** title, `Segmented` type (Pulse / eNPS / Engagement), anonymity toggle (with a plain-English explainer), open/close window (`starts_at`/`ends_at`).
   - **Step 2 · Questions:** the question builder — add/remove/reorder (drag), per-question type (`enps`/`scale`/`text`/`choice`/`boolean`), required toggle, options editor for `choice`. **Templates picker** to prefill (eNPS, Monthly pulse, Wellbeing pulse — §K seeds). Live count + validation (≥1 question).
   - **Step 3 · Audience:** who receives it — all staff / by site / by team; show a **live recipient count** in the rail (`railExtra`).
   - **Step 4 · Review & publish:** summary `ReviewCard`; footer offers **Save draft** and **Publish now** (publish fires the existing invitation notification + confetti).
   - Submit → `hr.wellbeing.surveys.store` / `…update`. Edit mode reuses the same wizard (like Add-Client's edit mode).
2. **Respond to survey** (premium) — clone the **Leave modal configuration** (rail preview, `pct={null}`). Anonymity reassurance banner when `is_anonymous`; one section per question or grouped; **eNPS as a 0–10 tile picker** with promoter/passive/detractor colour; scale as a segmented 1–5; text as textarea; choice as `ChipMulti`/radio. Progress strip; success pane ("Thanks — your voice matters"). Submit → `hr.wellbeing.surveys.responses.store`. **Used on both `survey.tsx` and `/hr/my/surveys`.**
3. **Action Plan wizard** (create — from survey OR from a flag).
   - **Step 1 · Context:** linked survey or flagged staff member (read-only chip when pre-linked), **owner picker** (`PeoplePicker`), with the owner's current workload in the rail.
   - **Step 2 · Plan:** title, description, `Segmented` priority, due date.
   - **Step 3 · Review.** Submit → `action-plans.store` (standalone variant needed → §K).
4. **Update Action Plan modal** — status `Segmented`, **progress slider** (auto-100 on complete), **notes timeline** (add note → §K), reassign owner (manager), complete/reopen. Respect owner-vs-manager field permissions.
5. **Log wellbeing check-in wizard** — **Step 1 · Who & type** (staff picker, type: 1:1 / welfare / return-to-work); **Step 2 · Notes & mood** (notes, optional mood, follow-up date, **visibility/private** toggle); **Step 3 · Review.** Submit → `checkins.store` (§K). Surfaces to the employee on My HR (§I).
6. **EAP referral wizard** — **Step 1 · Staff & reason** (category); **Step 2 · Provider & consent** (provider, consent captured, notes); **Step 3 · Review.** Submit → `eap.store` (§K). Sensitive — manager-gated, private by default.
7. **Flag triage modal** (quick) — from a flagged row: Acknowledge / Snooze (date) / Dismiss (reason), plus shortcut buttons to launch wizards 3/5/6 for that person. Submit → flag-action endpoints (§K).
8. **Nudge non-responders** confirm modal — shows count of non-responders; on confirm re-dispatches `EngagementSurveyInvitationNotification` to them (§K endpoint).

## I. My HR loop — complete the employee side (`/hr/my`)

Per Chane: the loop must close on **My HR** especially.

- **`/hr/my/surveys`:** swap the thin respond form for the **premium Respond modal** (§H-2). Show open invitations as cards with "Respond" + a "closes in N days" pill; show completed ones with a quiet "submitted" state. Keep `MyHrShell`.
- **Surface invitations in the My HR hero "needs you" strip** (`my-hr-hero.tsx`): "Respond to {survey title}" chip when the staff member has an unanswered published survey.
- **"Your check-ins":** let staff **see and acknowledge** wellbeing check-ins logged about them (respecting the `private` flag set by the manager — private notes are not shown). Acknowledgement → §K endpoint; reflect back to the manager on the Signals tab.
- **Kudos tie-in:** link recognition (the existing kudos/shoutouts on My HR) into the wellbeing picture — a "Send kudos" quick action from a check-in or action-plan completion, reusing the existing shoutout flow (don't fork it).

## J. Right-click everywhere (rows and tabs)

Add `resources/js/components/hr/wellbeing-context-menu.tsx` (mirror `leave-context-menu.tsx`). Same items appear on hover "⋯" and on right-click:

- **Survey row:** Open · Edit (draft) · Publish / Close · Duplicate (clone) · Nudge non-responders · Export results · Copy link · Archive/Delete (draft only).
- **Action-plan row:** Open · Update progress · Reassign owner · Add note · Complete · Reopen · Cancel.
- **Flagged-staff row:** Log check-in · Create action plan · Refer to EAP · Acknowledge · Snooze · Dismiss · View time/roster · View profile.
- **Tabs:** right-click a tab → the relevant "new": Surveys → New survey; Action plans → New action plan; Signals → Log check-in.

Gate every item on permission; never show a manager action to a non-manager.

## K. Backend handoff for Claude Code

> Claude design: as you build the UI and discover anything that needs server work, **add it here** with a short spec + migration sketch, so Chane has one clean list to hand to Claude Code. Mirror the final list into `WELLBEING_BACKEND_HANDOVER.md`. Gate manager actions on `hr.performance.manage`, respect `ResolvesHrTenant` (tenant scoping), keep anonymity guarantees intact, and **confirm any schema before building**. Seed list from the audit:

**Flag actions / close the loop (highest priority)**
1. **Flag triage** — new `hr_wellbeing_flag_actions` (`indicator_id`/`staff_user_id`, `tenant_id`, `action` enum [acknowledge|snooze|dismiss], `reason` nullable, `snooze_until` nullable, `actor_user_id`, timestamps). Endpoints: `POST /hr/wellbeing/signals/{user}/acknowledge|snooze|dismiss`. `getFlaggedStaff` should join latest action so the UI can show acknowledged/snoozed/active state and hide snoozed-until-future.
2. **Standalone action plans** — make `HrEngagementActionPlan.survey_id` **nullable** and add a non-survey create route `POST /hr/wellbeing/action-plans` (so plans can originate from a wellbeing flag). Add `source_type`/`source_id` (or `staff_user_id`) to link a plan to the flagged person. Add **reopen** (status back to open, clear `completed_at`) and **cancel**; consider manager soft-delete.
3. **Action-plan notes/timeline** — `hr_engagement_action_plan_notes` (`plan_id`, `author_user_id`, `body`, timestamps) **or** a status-history/activity log. Surface as the timeline in modal §H-4.

**Duty-of-care features**
4. **Wellbeing check-ins** — `hr_wellbeing_checkins` (`staff_user_id`, `manager_user_id`, `tenant_id`, `type` [1:1|welfare|return_to_work], `notes`, `mood` nullable, `follow_up_date` nullable, `is_private` bool, timestamps) + `acknowledged_at` for the employee. Endpoints: `POST /hr/wellbeing/checkins`, `PATCH …`, and `POST /hr/my/wellbeing/checkins/{checkin}/acknowledge` for staff. Respect `is_private` (never shown to staff).
5. **EAP referrals** — `hr_eap_referrals` (`staff_user_id`, `referred_by`, `tenant_id`, `reason_category`, `provider` nullable, `status` enum, `consent_given` bool, `notes`, timestamps). Endpoints for manager create + optional staff **self-referral** from My HR. Sensitive — tighten permissions.

**Surveys**
6. **Clone / duplicate survey** — `POST /hr/wellbeing/surveys/{survey}/duplicate` (copies survey + questions as a new draft).
7. **Survey templates** — `hr_engagement_survey_templates` (or seeded constants) for eNPS, Monthly pulse, Wellbeing pulse; endpoint/list to prefill the builder Step 2.
8. **Nudge non-responders** — `POST /hr/wellbeing/surveys/{survey}/nudge` re-dispatching the existing `EngagementSurveyInvitationNotification` to users without a response (anonymity-safe — target by recipient list, not by who answered).
9. **Results export** — `GET /hr/wellbeing/surveys/{survey}/export?format=csv|pdf` (manager-gated, anonymity-respecting).
10. **Survey delete/archive** — endpoint for draft delete + an `archived` state for closed surveys (list hygiene).

**Signals / data**
11. **Wellbeing trend** — indicators are point-in-time (`HrWellbeingIndicator` snapshots from `CalculateWellbeingIndicatorsJob`). Expose a small per-staff history endpoint for the sparkline in §G, and/or a tenant trend (red/amber counts over time) for the Overview tab.
12. **Audience targeting** — if survey audience (all/site/team) isn't modelled, add a recipients concept so recipient counts and nudge targeting are real (today invitations go to all active staff). Confirm desired scoping with Chane.

**For each item: short spec + migration (if any) and confirm before building. Don't silently invent schema.**

## L. Premium polish & delight

- Micro-interactions: hover lifts on cards, animated progress rings/bars, `slide-in` step panes, count-up on hero stats.
- Confetti on: survey published, action plan completed, "all caught up" on the signals tab.
- Keyboard: `N` = new survey (Surveys tab) / new action plan (Action plans) / log check-in (Signals); `/` focuses filter; `Esc` closes modals; arrow-key row nav with right-click menu on `Menu`/`Shift+F10`.
- Sonner toasts on every mutation with an **Undo** where safe (e.g. acknowledge/snooze).
- Respect `motion-reduce`. Loading skeletons, real empty states, and error states on every list and chart.
- Tone: warm and mana-enhancing — this is people's wellbeing, not a ticketing queue. Avoid clinical/surveillance language; frame flags as "needs a check-in," not "violations."

---

## Definition of done

- `/hr/wellbeing` is a four-tab hub (Overview · Surveys · Action plans · Wellbeing signals) on `hr-tabs`, under a **golden hero with no clock**, with live count badges and `?tab=` deep-links.
- The inline survey builder, the bare respond form, and the inline action-plan flows are **gone** — replaced by full `WizardShell` wizards matching `add-client-dialog.tsx` / `leave-request-dialog.tsx`.
- **Every flagged staff member is actionable** — check-in, action plan, EAP, acknowledge/snooze/dismiss — and the loop reflects back to the employee on `/hr/my`.
- Right-click menus on every row **and** on tabs; hover "⋯" parity; all permission-gated.
- `/hr/my/surveys` uses the premium respond modal; invitations show in the My HR hero "needs you"; staff can acknowledge check-ins; kudos tie-in works.
- No raw hex, no dead buttons. Clean `npm run build`, `npm run types`, `npm run lint`. Screenshots of each tab + every modal diffed against the reference pages.
- `WELLBEING_BACKEND_HANDOVER.md` exists with every discovered server-side item (spec + migration sketch), seeded from §K.
- **Signals to watch:** flags acknowledged within SLA, check-ins logged per red flag, survey response rate, action-plan on-time completion, "all caught up" reached.

**Build order:** §A audit → §B hero → §C tabs → §D Overview → §E Surveys + Survey Builder wizard → §F Action plans + modals → §G Signals (loop-closers) → §H remaining wizards → §I My HR loop → §J right-click → §L delight. Verify each pass against the reference pages, and keep appending discovered backend work to §K (and mirroring into `WELLBEING_BACKEND_HANDOVER.md`).
