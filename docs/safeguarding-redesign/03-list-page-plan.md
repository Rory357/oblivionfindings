# Safeguarding Redesign — Step Plan: 03 — List page

## 0. Identity
- **Step:** 3 — list page rebuilt to hs-hero-kit + TabStrip + right-click rows + reviews worklist + need-to-know
- **Routes:** `/safeguarding` (index) — unchanged route, new payload
- **Page:** `resources/js/pages/safeguarding/index.tsx` (full rewrite, modelled 1:1 on `incidents/index.tsx`)
- **Controller:** `SafeguardingConcernController@index` (rewrite payload)
- **Models/migration:** `SafeguardingConcern` (+ `is_sensitive`); migration `2026_06_17_160000`
- **Drop refs:** Safeguarding.dc.html list (hero 38–110, tabs 1b, rows 1c, banner 203, worklist), HANDOFF §1; incidents/index.tsx (§7 template)
- **Goal:** `/safeguarding` reads as the same product as `/incidents` — counts-only hero, 8 tabs + mine, EntityFilters, right-click rows, Restricted redaction, reviews worklist.

## 1. Section map (design → component → backend)
| Design block | Component | Backend source |
|---|---|---|
| Hero band | `HeroShell`+`HeroMedallion(ShieldAlert)`+`HeroStatusPill("Safeguarding register · need-to-know")` | — |
| Open-work cluster | `HeroCluster`+`HeroClusterTile` ×4 (Open · Awaiting triage · Under investigation · Referred external) | `hero.openWork` counts |
| Needs-attention cluster | ×4 (Overdue actions · Risk reviews due · Acks awaited · Critical open) | `hero.attention` counts |
| Footer filter band | `HeroSegmented`(period) + `EntityFilter`(Site, Subject onDark) + `HeroSegmented`(Severity, Category) + search | `filters` |
| Tabs | `TabStrip`/`RosterTabItem` ×8 + mine | `tab`, `tabCounts` |
| Referrals banner | inline card (critical tone) when `referralOverdueCount>0` | payload |
| Rows | table, row click → show (Step 4 swaps to modal), right-click `ShiftContextMenu` | `rows` (concerns) |
| Reviews worklist | table (kind=risk/ack, due, overdue) | `rows` (reviews), `rowsKind='reviews'` |
| Restricted row | hatched + lock, redacted | `row.restricted` |

## 2. Lifecycle/gates (§5)
- None new. Hero/tab counts reflect the Step-2 lifecycle (Awaiting triage = reported; Referred external = requires_referral OR has report; Closed folds no_action_required via `TERMINAL_STATUSES`).

## 3. Need-to-know / redaction (§3b — the critical part)
- Index route already gated by `permission:safeguarding.viewAny`. Within it, a row is **Restricted** when `is_sensitive && !viewSensitive && not assignee && not reporter`.
- Restricted row: `subject`→null, `abuse_category`→null, description never sent in the list payload anyway; `restricted:true` drives the hatched lock treatment + "Restricted · need-to-know". Context menu keeps only View (+ permitted) items.
- Counts (hero/tab) are **counts only** — never leak identities. Reviews worklist also redacts the subject name for restricted rows.
- `is_sensitive` column needed (policy `viewSensitive` exists but nothing flags a concern) → migration. Set at raise time in Step 6; seeder/tests set it directly for now.

## 4. Modal map (§4)
- None built here. Row click + "View" → `/safeguarding/{id}` (existing show) as a placeholder; **Step 4** introduces `SafeguardingConcernDialog` + detail-over-list (`openDetail`/`only:['detail']`). Action items (triage/assign/…) are added to the context menu in Steps 4/5 as their modals land (no stubs now — [[feedback_hide_unbuilt_actions]]).

## 5. Backend
| # | Change | Migration? | Test |
|---|---|---|---|
| index payload | rewrite to `{filters, tab, tabCounts, rows, rowsKind, hero, sites, subjects, can, referralOverdueCount}` | no | rewrite index tests |
| redaction | restricted mapping server-side | no | new redaction test (restricted hides subject for non-sensitive-cleared viewer; visible to assignee) |
| reviews worklist | risk reviews due + acks awaited | no | reviews tab returns rowsKind=reviews |
| is_sensitive | boolean default false | **YES** `2026_06_17_160000` | covered by redaction test |

## 6. Incidents-consistency (§7)
- Adopt the SAME `hs-hero-kit` + `@/components/rostering` (`TabStrip`/`EntityFilter`/`ShiftContextMenu`) + the SAME hero/footer/row anatomy as `incidents/index.tsx`. Period pills, Site/Client(→Subject) EntityFilter onDark, search pill, clear affordance — identical. Row click + right-click `openRowCtx` mechanism copied. Differences: medallion ShieldAlert vs AlertTriangle; eyebrow "need-to-know"; counts-only (no compliance badges); Subject (redactable) vs Client; Stage pill from the 8-status set; reviews worklist instead of follow-ups. Log nothing in INCIDENTS_CONSISTENCY beyond "adopted as-is".

## 7. Cross-module
- Row flags: linked incident (related_incident_id), Control Room alert (deferred to Step 8 — `control_room_alert_id` left null now; "View CR alert" item appears only when present). Subject jump `/operations/clients/{id}/care`. Linked incident jump `/incidents/{id}`.

## 8. Retire → redirect
- None this step (show.tsx retired in Step 4; create.tsx in Step 6).

## 9. Execution checklist
- [ ] Migration `is_sensitive` + model fillable/cast
- [ ] `@index` rewrite (filters, tabs, counts, rows+redaction, reviews worklist, hero)
- [ ] `index.tsx` rewrite on hs-hero-kit/TabStrip/ShiftContextMenu (Restricted rows, reviews worklist, referrals banner)
- [ ] Update index tests to new payload + new redaction test
- [ ] Migrate local; pint new files; types/lint/build; tests green
- [ ] Commit + tick PROGRESS

## 10. Notes
- `mine` ("Assigned to me") = 9th tab (functional parity; right-alignment is cosmetic, revisit if TabStrip supports it).
- Category filter = `abuse_category`; search = reference/description/subject_name; period = `reported_at` range.
- Hero counts global (org-wide register state, like incidents); tabCounts reflect footer filters.
