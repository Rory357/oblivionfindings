# eMAR P00 v1 — Medication rules & states

**Status: design candidate awaiting Stephan's exact-version approval.** Not approved, not implemented.

- Package: P00 (shared rules and states), approved to start by Stephan on 28 September 2026 (`docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, item 2).
- Version: v1, 28 September 2026 (NZDT). Earlier versions: none. A later revision goes in `P00/v2/` and does not inherit this approval.
- Baseline: `52dafa6728ebe343631453d4863f645d97c59506`, branch `claude/vigilant-mclaren-233129`.
- Exact file identity: see [`VERSION.txt`](VERSION.txt) (SHA-256 of every mockup file). Approval applies to those hashes only.

## Boundaries

- Design only. No application code, routes, schema, seeders, configuration, `DESIGN.md` or `design_styles/*` changed.
- Single organisation, many sites. Scope is shown as approved houses, never tenants.
- Desktop web only (D7): checked at 1440 and 1280 px wide and at 200 % zoom. No phone or tablet layouts.
- Synthetic people, staff, medicines and dates (Monday 28 September 2026, 9:12 am NZDT). The prescription limit on the paracetamol example is synthetic order data, not a clinical rule.
- Designs the **corrected** behaviour from the P0 fix session: honest allergy status (EM-07), a blocked as-needed dose shown as an error (EM-26), truthful eligibility (EM-03/NF-03), dashboard counts from obligations (EM-01), controlled-medicine concealment in Tasks (EM-12).
- Unapproved organisation values (D1–D6, D8–D12) display **Not configured** and fail closed. Nothing shown here becomes policy by being displayed.
- Tokens mirrored from `resources/css/app.css` (light `:root`). No raw palette colours; the only literal is `#000` inside two CSS masks (alpha stops copied verbatim from `.eh-ring`). Geometry mirrors `PageHeader`, `PageHeaderRail`, `TierTwoTabs`, `StatusBadge`, `WizardShell`, `EmptyState`/`ErrorState` and the skeleton set.

## Open it

```
node docs/emar-design/P00/v1/serve.mjs
```

Then open http://127.0.0.1:4360/ (read-only, GET/HEAD only). Opening `index.html` directly from disk also works.

The grey hatched bar at the top is the **mockup viewer, not product UI**: switch between *Navigation frame* and *State catalogue*, choose who is signed in, and choose a scenario. Anything hatched and dashed is a design note.

Useful deep links (append to the URL):

| What | Link |
|---|---|
| Support worker, Meds today | `#/frame/sw/today/schedule` |
| Scenarios | add `?scenario=notClockedIn` · `competencyExpired` · `offline` · `stale` · `unavailable` · `loading` · `empty` · `reject` |
| House lead, a hub | `#/frame/lead/safety/followups` |
| Canonical person record | `#/person/lead/aroha/chart` |
| Controlled-medicine concealment | `#/person/clinical/aroha/medicines` · `#/record/clinical/cd-114` |
| No access (page) | `#/frame/auditor/stock/register` |
| State catalogue | `#/catalogue/intro` (one section only: `#/catalogue/blocked?only=blocked`) |
| A single dialog | `#/frame/sw/today/schedule?open=record:r6` · `open=why:r3` · `open=prn` · `open=rejected` · `open=eligibility` · `open=reoffer` |

## What's in it

1. **Navigation frame** — the approved structure (plan §2). Support workers see one sidebar entry, **Meds today** (overdue badge restored). Leads, clinical staff, auditors and finance see a **Medication** module with up to seven permission-aware hubs: Meds today · MAR & medicines · Orders & reviews · Stock & controlled drugs · Safety & oversight · Reports & audit · Settings. Each hub opens with the Event Horizon header and its pages as `PageHeaderRail` views (every current URL kept, listed in each view's design note). The canonical person medication record (`/emar/mar?client_id=…`) uses the profile header and the tier-2 strip: Chart · Medicines · Support plan · Allergies & alerts · Clinical · History. Hub meter blocks and view bodies after P00 are marked as design notes for their package.
2. **State catalogue** — 14 sections. Every state card shows the live specimen (rendered by the same code as the frame, so the wording can't drift), its exact wording, when it shows, its treatment, what it must never do, which pages and dialogs reuse it, the organisation decisions it depends on and the findings it addresses.

## State checklist

Dose obligations and outcomes

- [x] Not yet due — `dose-not-yet-due`
- [x] Due — `dose-due`
- [x] Late (replaces "Give now if safe") — `dose-late`
- [x] Not yet recorded — `dose-not-yet-recorded`
- [x] Confirmed missed — `dose-missed`
- [x] Given (actual amount, given vs recorded time) — `dose-given`
- [x] Refused — `dose-refused`
- [x] Re-offered, then given — `dose-reoffered`
- [x] Withheld with reason — `dose-withheld`
- [x] Absent / away — `dose-away`
- [x] Self-managed (independent) — `dose-self-managed`
- [x] Taken with prompting — `dose-prompted`
- [x] Taken with assistance — `dose-assisted`
- [x] Pending server confirmation — `rec-sending`
- [x] Confirmed — `rec-confirmed`
- [x] Queued offline — `rec-queued`
- [x] Rejected (not recorded) — `rec-rejected`
- [x] Corrected with lineage — `rec-corrected`

Blocked reasons (each with a named next step and what can still be recorded)

- [x] Not clocked in — `block-not-clocked-in`
- [x] Not on this person's shift — `block-not-on-shift`
- [x] Shift ended (recording after clock-out) — `block-shift-ended`
- [x] Site not approved — `block-site-not-approved`
- [x] Competency expired — `block-competency-expired`
- [x] Competency exemption ended — `block-exemption-ended`
- [x] Competency restricted (supervised practice) — `block-restricted`
- [x] Competency area not passed (insulin) — `block-area-not-passed`
- [x] No eligible witness — `block-no-witness`
- [x] Order awaiting verification — `block-awaiting-verification`
- [x] Covert authorisation missing — `block-covert-missing`
- [x] Covert authorisation expired — `block-covert-expired`
- [x] As-needed limit reached — `block-prn-limit`
- [x] Safety block: allergy match (withhold always recordable) — `block-safety-allergy`
- [x] Safety block: contraindication — `block-safety-contra`
- [x] Emergency access route (emergency-access holders only) — `block-emergency-route`

Allergy status

- [x] Allergies recorded — `allergy-recorded`
- [x] No allergies recorded (never "no known allergies") — `allergy-none-recorded`
- [x] Allergy information unavailable — `allergy-unavailable`
- [x] No known drug allergies (only once D5 approves it) — `allergy-nkda`

Data quality

- [x] Loading (skeleton) — `q-loading`
- [x] Empty / no work — `q-empty`
- [x] Not applicable (zero denominator) — `q-na`
- [x] Unavailable / read failed (incl. sidebar "?") — `q-unavailable`
- [x] Stale, with refresh time and NZ zone — `q-stale`
- [x] No access (page, 403) — `q-no-access`
- [x] Not found / concealed record (404, no existence leak) — `q-not-found`

Controlled-medicine concealment

- [x] With access — `cd-visible`
- [x] Without access: lists (constant caption, no placeholder) — `cd-hidden-list`
- [x] Without access: totals and reports — `cd-hidden-totals`
- [x] Without access: Tasks, search, exports — `cd-hidden-search`
- [x] Without access: direct link — `cd-direct-link`

Follow-ups

- [x] Due, with owner and due time — `fu-due`
- [x] Overdue across midnight and shift change — `fu-overdue-midnight`
- [x] Escalated, no acknowledgement — `fu-escalated`
- [x] No owner — `fu-no-owner`
- [x] Unable to assess (no default result) — `fu-unable`
- [x] Completed late — `fu-completed-late`

Person identity header

- [x] Full, photo held — `id-full`
- [x] No photo on file — `id-no-photo`
- [x] Compact — `id-compact`
- [x] Support level not recorded — `id-support-unknown`

Time display

- [x] Nine rules (zone visible, format, relative + absolute, calendar day, given vs recorded, midnight, elapsed time, DST start, DST end)
- [x] Header zone and update time — `time-header`
- [x] Dose in the skipped hour (Sunday 27 September 2026) — `time-dst-start`
- [x] Repeated hour (Sunday 4 April 2027) — `time-dst-end`
- [x] Interval across a clock change — `time-interval`

Universal interaction states (plan §7.3)

- [x] Offline banner (two variants, pending D7) — `u-offline`
- [x] Conflict: order changed — `u-conflict`
- [x] Already recorded (duplicate) — `u-duplicate`
- [x] Validation with values kept — `u-validation`
- [x] Interruption and resume — `u-resume`
- [x] Focus return and keyboard path — `u-focus`

Also in the catalogue: the navigation frame by role, the "Not configured" pattern, the D1–D13 decision table, and the state-family × package reuse matrix.

## Verification record (28 September 2026)

- **Widths:** every frame view, dialog, scenario and catalogue section captured at 1440 and 1280 (`screenshots/1440-*`, `screenshots/1280-*`). 200 % zoom captured as a 720 px CSS viewport at device scale 2 (`screenshots/zoom200-*`). At 200 % the sidebar collapses to its icon rail, header meters wrap, the rail folds trailing views into "More" (alert counts sum onto it; the active view stays visible), dose rows stack, and the WizardShell rail hides.
- **No horizontal page scroll:** measured with headless Chrome at 200 % on Meds today, a recording dialog, the person record, a hub and every catalogue section (document width 705 = client width 705). One overflow found (visually hidden labels escaping the reuse-matrix scroller) and fixed.
- **Keyboard:** real Tab key presses via the Chrome DevTools Protocol at 1440 and 200 %. Order: skip link → viewer → top bar → sidebar → breadcrumbs → header search and actions → meter blocks → filters → rail → Find → content. Every stop is a link or button with a visible focus ring. Filter chips were spans (unreachable) and were changed to buttons.
- **Dialogs:** open with focus on the title, trap Tab and Shift+Tab, close on Escape, and return focus to the trigger (or the same row's action when the row re-renders). Checked for Why can't I record this?, Record dose (all three steps), Record not given, As-needed (blocked), Not recorded review, My eligibility and Find.
- **Flows exercised:** record → validation ("Choose what happened.", "Choose a reason for withholding.") → review → Sending… → Recorded (row updates from the confirmed state, toast, focus returns); withhold while an allergy block is active; as-needed at its limit (Continue disabled with the reason); refused save (dialog stays open, values kept, row becomes "Not recorded"); offline save ("Saved on this device"); clock in from the blocked state; competency expired (Given disabled with the reason, refusal/withhold/absence available).
- **Roles:** support worker (one entry), house lead (seven hubs), clinical lead (no stock/controlled hub; controlled rows, meters and chart rows vanish; no recording actions), auditor (MAR & medicines, Safety & oversight with errors and emergency access review, Reports & audit with audit trail; direct link to the controlled register shows "no access"), finance (stock only, reports).
- **Console:** no errors.
- Tooling note: the local preview ran through a temporary `emar-p00` entry in the worktree's git-ignored `.claude/launch.json`, removed afterwards.

## Design choices made in v1 that need your confirmation

These aren't organisation decisions (D#), but they are choices this mockup made:

1. **Late is amber, not red.** Red is kept for "not yet recorded", "missed", "not recorded" and safety. A late dose can still be acted on this shift.
2. **"Refused"** stays as the state name (matches the existing outcome code), with the person's choice described in plain words ("Grace said no"). "Declined" is the alternative if you prefer it.
3. **Time format "8:05 am"** (lower-case am/pm, no leading zero); exports use "28/09/2026 8:05 am NZDT".
4. **Support worker's Meds today** is a top-level sidebar row (where the frontline link sits today), with the late-dose count.
5. **Handover and Report a medication error** are icon-only glass buttons in the Meds today header (with labels and tooltips) so the header keeps one row at 1280 px.
6. **Person record** uses the tier-2 strip only (no header rail), as the plan specifies.
7. **Follow-up due times** are entered by the person creating the follow-up; no default interval is shown until D4/D12 exist.
8. **Proposed D13** for the covert administration process, which the review lists as organisation policy but doesn't number.

## Open questions for Stephan (by decision)

- **D1** — Service classification per site. P00 names no standard or regulator; which one applies changes later wording only. Who owns it?
- **D2** — Who may record after a shift ends, relief and agency staff, who may override safety checks, and the approved way to check identity when no photo is held. Until then these read "Not configured" or stay blocked.
- **D3 + NF-03** — For a restricted (supervised) assessment: block "given" outright, or allow a present, qualified co-signer? v1 shows the block and "Whether a colleague who is present can co-sign instead: Not configured".
- **D4** — Due window, late/early rules, re-offer rule, how as-needed limits are counted, and how a dose in the skipped DST hour is rescheduled. v1 shows all of these as "Not configured".
- **D5** — Confirm the health profile as the one allergy source, and whether "No known drug allergies" is an approved status. Also: should recording "given" pause while allergy information can't be loaded?
- **D6** — Confirm the four support levels (Independent, Prompt, Assist, Administer) as the vocabulary for P03.
- **D7** — Screen scope is decided (desktop web). Is offline recording kept (records saved on the device and sent later), or does recording pause with the print pack offered? v1 shows both variants.
- **D8** — Controlled-medicine count cadence and witness credential. v1 shows no count as due or overdue until the cadence is set.
- **D9** — Which roles may see controlled-medicine errors, incidents and aggregates, and whether small numbers are suppressed. v1 uses the current rule: no trace for roles without controlled access, with constant captions.
- **D10** — Not used in P00 (P06).
- **D11** — Is the dated print pack the approved downtime path? v1 links it from the offline, out-of-date and couldn't-load states.
- **D12** — Named escalation and on-call contacts, and what counts as an acknowledgement. v1 shows "On-call contact: Not configured" everywhere.
- **D13 (proposed)** — Should the covert administration process get its own decision number and owner?

## Approval requested

Please approve **eMAR P00 v1** exactly as identified by the hashes in `VERSION.txt`, or list the changes you want for a v2. Nothing after P00 (P01 onwards) starts until this version is approved and the P0 fixes are accepted.
