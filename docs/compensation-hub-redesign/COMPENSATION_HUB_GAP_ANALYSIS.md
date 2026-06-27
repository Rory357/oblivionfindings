# Compensation & Benefits Hub — Gap Analysis

> Audit of the six hub surfaces (`/hr/compensation/*`) + the self-service expense paths against a best-in-class comp / benefits / expenses checklist.
> Benchmarks: Pave · CompTeam · Payscale · Mercer · Carta (bands, compa-ratio, range penetration, overlap) · Employment Hero · PayHero · Gusto · Deel (KiwiSaver, live comp+leave+payroll) · Expensify · Concur · Ramp · Payhawk · Pleo (one claim flow, configured mileage, OCR, approval inbox, policy caps, export).
> **Legend:** ✅ Present · 🟡 Partial · ❌ Missing → (→ how the redesign / mockup closes it).
> Design reference for every "closed" item: **`Compensation Hub.dc.html`**.

---

## 1. Hero
| Item | Today | Redesign |
|---|---|---|
| Golden brand band, no clock | ❌ generic `PageHero` on bands; inconsistent per tab | ✅ `CompensationHero` on the `my-hr-hero` gradient, clock + te-reo + needs-you stripped |
| Stats that matter | ❌ single page-only "Bands" count | ✅ people out of band · reviews in flight · awaiting my approval · reimbursed this month (all true aggregates — _backend_) |
| Quick actions | 🟡 scattered | ✅ New band · Start review · Record bonus · New claim · Export |
| Live alert badges w/ drill-down | ❌ | ✅ awaiting-your-approval · over/under band · claims overdue chips |
| Right cluster (clock slot) | n/a | ✅ band-health ring (% within band) |

## 2. Tabs
| Item | Today | Redesign |
|---|---|---|
| Standardised strip + counts | 🟡 `HrTabs`, no counts | ✅ count badges + tones |
| `?tab=` deep-link | ❌ | ✅ (via `useHrTab`) — _wire in real build_ |
| Right-click tab menu | ❌ | ✅ set default / open / pin |
| Orphans brought in | ❌ Plans + History orphaned | ✅ History is a tab; Plans → Benefits sub-view |

## 3. Salary Bands
| Item | Today | Redesign |
|---|---|---|
| Range-bar visualisation | ❌ plain min/mid/max cells | ✅ horizontal bar, mid marker, target zone |
| Employees per band | ❌ | ✅ count + dots plotted by compa-ratio |
| Compa-ratio / range penetration | ❌ (`getSalaryBandForRole` has **0 callers**) | ✅ per-person compa + in/under/over (_needs `bandPlacement()` backend_) |
| Band-overlap detection | ❌ | ✅ overlap warning in the wizard |
| Effective dating + supersede | 🟡 edit only | ✅ supersede closes prior band day-before |
| Detail drawer | ❌ | ✅ people-in-band drawer w/ compa + bars |
| Duplicate / archive / right-click | ❌ edit-pencil only | ✅ hover actions + context menu |
| Filter role LIKE, export, empty/skeleton | 🟡 exact-match, no export | ✅ LIKE filter, export, empty state |
| Create/edit = wizard w/ min≤mid≤max + hourly⇄salary | ❌ thin 10-field Dialog | ✅ 4-step wizard, live validation |

## 4. Pay Reviews
| Item | Today | Redesign |
|---|---|---|
| Guided multi-line builder | ✅ (the one good surface) | ✅ kept, converted to wizard |
| Band placement per line | ❌ | ✅ current vs proposed vs band, out-of-band flag |
| Budget vs sum-of-proposed tally | ❌ | ✅ running tally + over-budget crit |
| Per-item approve/reject | ❌ (`rejected` dead) | ✅ (_needs endpoints_) |
| Reject-review path | ❌ | ✅ (_needs endpoint_) |
| Edit-after-create | ❌ | ✅ while `planning` (_needs `updateReview`_) |
| Link line → employee History | ❌ | ✅ |
| **Apply bug** | ❌ annual written into hourly (corruption) | ✅ fixed spec → annual→annual, derive hourly (**P0 backend**) |

## 5. Bonuses
| Item | Today | Redesign |
|---|---|---|
| Shared chrome (`Table`+`StatusBadge`+`Intl`) | ❌ `PageShell` + raw `<table>` + hard-coded `$` | ✅ standardised |
| Full lifecycle (paid/cancelled) | ❌ unreachable | ✅ approve/mark-paid/cancel transitions (_needs `BonusService`_) |
| Record = wizard w/ band context | ❌ thin Dialog, no confirm | ✅ 3-step wizard, recipient band card |
| Link to a pay review | ❌ | ✅ optional |
| True totals | 🟡 total true, rest page-slice | ✅ (_backend aggregates_) |
| Right-click + confirms | ❌ bare Approve | ✅ menu + alert-dialog confirms |

## 6. Benefits
| Item | Today | Redesign |
|---|---|---|
| Plans reachable | ❌ orphan URL, no inbound link | ✅ Benefits sub-view, two-way Plans⇄Enrollments link |
| Plan edit (all fields) | ❌ only `is_active` mutable | ✅ wizard (_extend `updatePlan`_) |
| KiwiSaver presets + employer-min validation + cost preview | ❌ thin Dialog | ✅ presets 3/4/6/8/10%, min-3% guard, live cost preview |
| Opt-out as guided state change (date + reason) | ❌ `opt_out_date` omitted in UI | ✅ guided opt-out |
| Surface avg contribution rates | ❌ computed but unused | ✅ shown (_surface `getEnrollmentSummary`_) |
| Enrollee drill-down / export / right-click | ❌ | ✅ |

## 7. Expenses
| Item | Today | Redesign |
|---|---|---|
| One premium claim wizard, reused 3 surfaces | ❌ full-page `create.tsx` + thin inline `hr/my/expenses` (4 fields, **dead-ends**) | ✅ single `ExpenseClaimDialog` on Compensation / My HR / My Day |
| Mileage line at config IRD rate | ❌ HR "mileage" category typed by hand | ✅ `distance_km × config('finance.mileage_rate_per_km')`, rate read-only |
| Manager files on-behalf | ❌ self only | ✅ on-behalf picker (_needs backend_) |
| Self-service can submit + track | ❌ draft dead-end | ✅ submit + status |
| Approvals inbox (not "filter the list") | ❌ | ✅ segments + bulk approve |
| Reject requires reason | 🟡 inline reject, reason optional | ✅ required-reason modal |
| Receipt view/download | ❌ "Attached" badge only | ✅ viewer + download (_needs download route_) |
| Edit/withdraw/add-item draft | ❌ | ✅ (_needs routes; `addItem` exists, no route_) |
| True totals / export | 🟡 page-slice | ✅ |

## 8. History
| Item | Today | Redesign |
|---|---|---|
| Inbound links | ❌ **zero** anywhere | ✅ People profile + pay-review lines + hub tab |
| Comp section on People profile | ❌ none | ✅ current salary + hourly + band placement + "View history" |
| Gated "Record change" wizard | ❌ only written by applying a review | ✅ promotion/adjustment/correction/initial (_wire `recordChange`_) |
| Hourly column correctness | ❌ shows corrupted value | ✅ correct once **P0** apply-bug fixed |

## 9. End-to-end / chrome
| Item | Today | Redesign |
|---|---|---|
| Every action wired + toasted | ❌ dead buttons | ✅ toasts everywhere; disabled-with-tooltip where backend pending |
| Stats are true totals | ❌ `…data.length` | ✅ (_backend aggregates_) |
| Chrome consistency | ❌ bonuses drifts (PageShell, raw table, `$`); filters drift | ✅ `PageLayout` + `Table` + `StatusBadge` everywhere |
| Settings for mileage rate / GL | ❌ no surface | ✅ Settings modal: rate + effective date + GL map (read-only) + consolidation flags |

---

## Decisions encoded in the mockup
1. **Bands are load-bearing** — compa-ratio / placement appears on Bands, Pay-review lines, and (spec'd) the People profile, all from one `bandPlacement()` helper.
2. **Every info-gathering flow is a full wizard** in the Add-Client idiom with the warm Leave feel (identity tiles, live preview/rail context, hero-summary review step, success pane + confetti for staff submits). **Zero thin single-step dialogs.**
3. **One expense modal, config-driven mileage** — React never hardcodes a rate; it renders `rate × km` from a controller prop sourced from `config('finance.mileage_rate_per_km')`.
4. **Mileage rate is set once at the config/settings layer; the claim wizard only reads it.** The Settings surface is the proposed admin home (effective-dated), pending Finance's consolidation of the three other mileage systems.
5. **Permissions respected** — manager-only UI (on-behalf, approvals, record-change) gates on `hr.compensation.*` / `hr.benefits.*` / `hr.expenses.*`.
6. **NZ intact** — NZD / en-NZ formatting, KiwiSaver presets + 3% employer minimum, IRD mileage rate.

## Cross-domain — DO NOT touch this pass
- GL posting / `PostExpenseJournalJob` / observer **double-post** → Finance.
- Operations & Fleet mileage systems → future consolidation.
