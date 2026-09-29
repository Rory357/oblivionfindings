# eMAR P11 v1 — Settings & staff eligibility

**Status: design candidate, waiting for Stephan's approval of this exact version.** Not implemented.

- Version: v1, 29 September 2026 (NZDT). Branch `claude/serene-aryabhata-d0e908`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of each file).
- Built on the approved P00 v5 contract (commit `ff3bff860`). P00's views are reused unchanged — see "Reuse" below.
- Design only: no application code, routes, schema, seeders or configuration changed. DESIGN.md and `design_styles/*` untouched.
- Desktop web only: checked at 1440 and 1280 px wide and at 200 % zoom. Synthetic data; NZ English.

## Open it

```
node docs/emar-design/P11/v1/serve.mjs
```

Then open http://127.0.0.1:4371/ (it opens on Settings as the clinical lead). Use **State catalogue** in the grey bar for the start page, the state checklist and the questions. The grey bar also switches who is signed in, the Meds today scenario, and (on Settings and Staff eligibility) the data state and whether the next save works, fails once or finds that someone else saved first.

| Page | Signed in as | Link |
|---|---|---|
| Settings — organisation rules and house settings | Clinical lead | `#/frame/clinical/settings/rules` |
| Settings — house settings only (organisation rules read-only) | House lead | `#/frame/lead/settings/templates` |
| Emergency access policy (the only role that can change it today) | Provider manager | `#/frame/pm/settings/eapolicy` |
| Staff eligibility | Clinical lead | `#/frame/clinical/safety/eligibility` |
| Witness competency and PIN side by side | House lead | `#/frame/lead/safety/eligibility/witness` |
| My eligibility, from Meds today | Support worker | `#/frame/sw/today/schedule?open=eligibility` |

## What P11 designs

**Medication › Settings** — one page with rail views, the Fleet Settings pattern (`fleet-assets/settings/index.tsx`): PageHeader, meter blocks that each link to their view, real filters on every view, drafts that survive switching views, a leave guard, a “Changes” button and one change history.

| Rail view | What's in it |
|---|---|
| Medication rules | The approved P00 v5 view, unchanged, plus a new **Medicine photos** card below it (Stephan, 29 Sep: staff photos, no picture library): who can take or replace a photo, and whether to prompt at stock receipt. Both “Default — not yet reviewed”. |
| Rounds & timing | Round templates moved from Meds today › Rounds (link back kept for managers). Today's template rules: one time with a window either side (5–120 min), days, house, optional default staff; retired, never deleted. Add/edit is a 3-step WizardShell with a day timeline and overlap warning; “Create rounds for a day”. **Dose timing** (Stephan, 29 Sep): today's `config/medications.php` times kept until the clinical lead reviews them; time-critical medicines and the re-offer rule are “Not configured”; recording is never blocked. |
| Second-person confirmation | The approved P00 v5 view, unchanged, now holding Stephan's PIN answers (5 attempts, 15 minutes, no renewal, house and clinical leads reset, fallback except controlled drugs, 30 minutes). |
| Alert recipients | Per-house on-call contact (Stephan, 29 Sep: “Not configured” until filled). Who gets each alert: the decided routing is fixed in (everyone rostered plus the house lead until resolved; follow-ups due by the end of the next shift); the rest are per-house proposals. A “Today, in code” lens shows what actually happens now. Whether to build this is a decision (D12). |
| Eligibility rules | The values competency depends on, fixed in code today: assessment length (1 year), pass mark (10 of 12), core areas (form only), renewal reminder (30 days), observed administrations (the UK “12” removed — Not configured), **longest exemption (Not configured — exemptions can't be granted until set, NF-03)**. |
| Emergency access policy | Moved from the Emergency access page. Today's five values and limits, all “Default — not yet reviewed” (never saved); a new check that an extension can't exceed the longest grant; P10 gaps listed as proposals. Editable by the provider manager only (today's role check). |
| Change history | “Still to decide” (every setting that is Not configured or still on its default, what happens meanwhile, the decision number) and all changes across the hub, filterable, each opening before and after. |

Read-only vs editable: organisation-wide rules need “manage medication settings” plus all-sites authority (today's server rule); house settings (templates, on-call contact, a house's extra alert recipients) need access to that house. Everything stays visible; controls are disabled with the reason.

**Safety & oversight › Staff eligibility** — register, renewals, exemptions, and witness competency beside PIN status, as lenses in the header filter row. Status is built from the competency policy (`evaluate()`) and the organisation rules, never from permissions (EM-03). Areas not passed and not assessed are shown separately, with what each means today — including what the system doesn't check yet (insulin, the unsupervised flag). The assessment wizard (5 steps) has no preselected results, shows the result truthfully, and leaves acknowledgement to the worker's own login (the server already requires it). Exemptions (new — no screen or maximum today): one house, a reason of at least 10 characters, an end date within the longest allowed, approved by someone else, never a witness.

**My eligibility** (from the Meds today meter) — replaces P00's placeholder dialog (“Full view designed in P11”): what the worker can do right now, their areas, renewal, PIN, and “Read and acknowledge” for a new assessment. Scenarios: current, renewal due, expired, restricted, exemption, acknowledge, not assessed.

## Reuse — P00 v5 unchanged

`node docs/emar-design/P11/v1/reuse-check.mjs` reads P00 v5 from commit `ff3bff860` and compares 60 approved declarations (Medication rules and its builder, safety and phone-instruction settings, controlled-drug witness card and overrides, PIN rules, staff PIN status, account › Witness PIN, the PIN co-sign fields). Result: **all 60 and `mockup.css` byte-identical.** P11's styles are in `p11.css`.

`mockup.js` is P00 v5's file plus one P11 block (before “render”) and these 26 hook edits, each asserted to match once:

| Hook | Why |
|---|---|
| H01–H02, H04, H17 | P11 title, version, viewer title, catalogue title |
| H03 | Settings hub rail: seven views (was five; alert recipients was conditional) |
| H05, H06, H07, H08, H21 | Viewer extras, P11 query parameters, sub-routes, catalogue opens on P11 |
| H09 | Rail marks views with unsaved changes |
| H10, H13 | My eligibility states on the Meds today meter and row blocking |
| H11 | Row menus for P11 rows |
| H12, H19 | P00's staff PIN row (“designed in P11”) now opens the person in Staff eligibility |
| H14 | Meds today › Rounds: “Round templates have moved to Settings” for managers |
| H15 | Settings hub and Staff eligibility use P11's header and body |
| H16 | P11 catalogue sections |
| H18 | Filters and search applied after each render |
| H20, H23 | My eligibility opens the P11 view |
| H22 | Leave guard on navigation |
| H24 | P11 deep links for screenshots |
| H25 | Opens on Settings as the clinical lead |
| H26 | The P11 block |

Data changed only where Stephan decided on 29 September: the PIN rules are seeded with his answers, the allergy rule reads “Warn for now”, and P00's staff PIN list gains the four P11 staff (not on shift, so P00's recording flows are unchanged).

## Facts from today's app the design relies on

- Emergency access policy: `PUT /emar/break-glass-policy`, `BreakGlassController::updatePolicy` (role check admin/provider manager, fields 5–1440 min, default ≤ longest); table `break_glass_policies`; **changes not audited**.
- Alerts: `SendMedicationAlerts` sends overdue alerts only to the round's assignee (NF-10); low stock to everyone with medication access at the house; renewals to the person only. Suspected faults (not verified by a test): refusal alerts match a role column that doesn't exist; renewal alerts have no de-duplication (every 15 minutes); the emergency access daily report's routing key doesn't match its config; two stock jobs share one timestamp.
- Round templates: `medication_round_templates` (time, window 5–120, ISO days, site, default staff, active, retired); gate `medications.orders.manage` + site; create/update not audited; the “(med-competent)” picker doesn't check competency.
- Competency: pass at 10 of 12 on the server (core areas form-only); expiry +1 year; acknowledgement subject-only; `can_administer_unsupervised` never enforced; witnessing needs a valid assessment (not an exemption) with `can_witness_controlled`; restriction isn't checked for witnesses; rostering blocks on no valid assessment and warns 30 days before expiry. Exemptions: table and service exist (`medications.competency.exempt`; reason ≥ 10 characters; approver ≠ subject; site-scoped; audited) but **no route or screen, and no maximum**; restriction and area rules are skipped during an exemption.
- Reading assessments: `/emar/competency` needs only `medications.view`, so support workers can read colleagues' assessments.
- Settings: `/emar/settings` needs `medications.settings.manage`; global rules need site bypass; **site managers can add rules for their own sites today**.
- Timing: `config/medications.php` — 30 min before, 60 min after (late), 60 min due soon; stored keys have no settings screen.

## Every state

| State | Where |
|---|---|
| Loading | Settings and Staff eligibility — `?sdemo=loading`, `?edemo=loading` |
| Empty | Change history first use, templates, exemptions, nobody assessed, filtered empty |
| Not applicable | “n/a” meter; area rows while given doses can't be recorded |
| No access (403) | Settings as auditor or finance; Staff eligibility as support worker |
| Not found (404) | A staff record or template link that doesn't exist or isn't yours |
| Validation, values kept | Template, assessment (areas, restriction, declaration), exemption, on-call phone, timing, policy |
| Stale | `?sdemo=stale`, `?edemo=stale` |
| Failure with retry | Couldn't load (both); save fails once then “Try again” |
| Conflict | Someone else saved first — refresh, your changes kept |
| Offline | Settings read-only; never saved on the device |
| Success | Toast plus “Set by …”, “Just now” in history, register row changes |
| Focus return | Every dialog returns focus to its trigger; saves move focus to the card heading |
| Unsaved guard | Drafts survive rail views; leaving the hub asks; “{n} unsaved changes” list |
| Interruption | “Discard this assessment?” on close or Escape |

All links are in the catalogue's “Every state” page.

## Verification record (29 September 2026)

- 173 routes × 3 sizes (1440, 1280, 200 %): **no console errors, no horizontal overflow** (headless Chromium).
- 48 scripted interaction checks (clicks and keys) **all pass**: guard, drafts across views, confirm with effect and old value, save failure and retry, validation keeping values, focus return, read-only for the house lead, on-call contact, template wizard with the approved time picker, assessment wizard to “waiting for acknowledgement”, exemption blocked then granted, acknowledgement, filters, 403 and 404.
- `reuse-check.mjs`: 60 approved declarations and `mockup.css` identical to P00 v5.
- 93 screenshots in `screenshots/` (75 at 1440, 10 at 1280, 8 at 200 %).

## Questions for Stephan, by decision

**D2 — who may do what**
1. Today a house manager can add medicine rules for their own house; P00 v5 shows rules read-only without all-sites authority. Keep P00, or restore house rules (changes the approved view)?
2. Emergency access policy: keep today's role check (admins and provider managers), or use “manage medication settings for all sites” like the other organisation rules?
3. Round templates keep today's gate (people who manage orders at the house) inside Settings — agree?
4. Who records assessments: keep “manages orders at the house”, or add an assessor permission?
5. The register is shown to leads only, and each worker sees their own; today every role with medication access can read colleagues' assessments. Agree?
6. Auditors read settings changes in Reports & audit › Audit trail — should they also get read-only Settings?

**D3 — competency**
7. Values for the clinical lead to review: 12 months · pass mark 10 of 12 · every core area must pass · renewal reminder 30 days · observed administrations (Not configured).
8. Set the longest exemption (until then none can be granted).
9. Should the restricted and area rules still apply during an exemption? (Today they don't.)
10. Only allow “can witness controlled drugs” when the controlled drugs area is passed? (Today the box is free.)
11. A restricted worker can still witness today — keep? (also D8)
12. Acknowledgement from the worker's own login only (tick box removed) — agree?

**D4 — timing**
13. Dose timing lives in Settings › Rounds & timing — or inside Medication rules (changes the approved view)?
14. Make the late-dose incident (120 min) and refusal escalation (3 in 7 days) settings too?

**D12 — alerts**
15. Build configurable Alert recipients, or keep recipients in code plus your decided routing?
16. The proposed recipients for stock, refusals, renewals, errors and the emergency access report — agree?
17. Raise a fix task for the suspected alert routing faults listed above?

**Other**
18. P06 — Medicine photos: agree with the two suggested defaults and the card's place on the Medication rules page (its “On this page” links would gain it when built)?
19. P10 — Emergency access proposals (durations follow the policy, optional/required second person, review due time, reviewer ≠ user, record policy changes): approve for P10?
20. P00 copy now out of date (approve the updates for implementation): empty PIN renewal shows “Not configured” though “no renewal” was decided; the locked-PIN message says the limit isn't configured; the staff PIN row menu lets only all-sites people reset; the heads-up badge still says “Stephan to confirm”.
21. Layout: with seven views, Emergency access policy and Change history sit under “More” at 1440 px (Alert recipients too at 1280). The meters and the “Changes” button reach them in one click. Acceptable?

## Approval

Please approve this exact version (hashes in `VERSION.txt`) or send changes. After approval: P01 follows in the agreed order; P11's build waits for PIN-1 to land.
