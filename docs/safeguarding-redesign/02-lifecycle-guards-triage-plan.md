# Safeguarding Redesign — Step Plan: 02 — Lifecycle guards + triage action

## 0. Identity
- **Step:** 2 — transition guard + gates (W3/W6/W7) + dedicated triage action (W4)
- **Routes touched:** `routes/safeguarding.php` — add `POST /safeguarding/{concern}/triage`
- **Controller(s):** `SafeguardingConcernController@triage` (new), `@updateStatus` (guard), `@close` (W7 gate)
- **Service (new):** `app/Services/Safeguarding/SafeguardingLifecycle.php` (transition map + gate reasons + labels)
- **Models / migration:** `SafeguardingConcern` (triage fillable/casts); migration `2026_06_17_150000` adds triage fields
- **Drop refs:** HANDOFF §3 state machine; `SAFEGUARDING_LIFECYCLE_PLAN.md` §4 (authoritative); prototype triage modal (dc.html 553–600) + stageIndex (1052) + labels (859–865)
- **One-line goal:** Enforce the §4 state machine server-side (gates + legal transitions) and add the first-class triage decision, with reasons mirrored for the UI.

## 1. The enforced state machine (§4)
```
reported --triage(W4)--> {investigating (auto-creates investigation), triaged+refer-flag, no_action_required(+rationale)}
triaged       --> investigating (gate: open investigation), referred_external (gate: >=1 report)
investigating --> action_plan, monitoring, referred_external           (auto-advance on inv complete = Step 7/W5)
referred_external (parallel) --> investigating, action_plan, monitoring
action_plan   --> monitoring, investigating
monitoring    --> action_plan ; close via close() only
closed / no_action_required = terminal
```
- Generic `updateStatus` **never** sets `closed` (use Close) and **never** leaves `reported` (use Triage).

## 2. Lifecycle gates / transitions to enforce (§5 — server-side AND UI)
| Rule | Where enforced | UI reflection (Step 4/5) | Feature test |
|---|---|---|---|
| Can't leave `reported` via updateStatus/close (triage first) | `SafeguardingLifecycle::guardTransition` + `@close` | "Triage the concern first" disabled action | reported→investigating via updateStatus rejected; close(reported) rejected |
| Enter `investigating` needs ≥1 non-abandoned investigation (W3) | guard + (triage-investigate auto-creates one) | locked Investigation empty state | triaged→investigating w/o inv rejected; w/ inv ok |
| Enter `referred_external` needs ≥1 external report (W6) | guard | "Referral indicated — log the report" | triaged→referred_external w/o report rejected; w/ report ok |
| `close` soft-blocked w/ open work unless override reason (W7) | `@close` | gated Close modal (Step 5) | close w/ open action + no reason rejected; w/ reason ok |
| `closure_summary` required (W7) | `@close` validate | required field | existing test |
| No illegal jumps (e.g. action_plan→reported) | guard transition map | n/a | bogus transition rejected |
- **Triage (W4):** substantiate · initial risk · assign lead · path. path=investigate → create investigation + status=investigating; path=refer → requires_external_referral=true + status=triaged; path=no_action → status=no_action_required (+rationale in triage_notes). Records triaged_at/by + substantiation + decision.
- **Auto-advance (W5):** OUT — Step 7.

## 3. Need-to-know / redaction (§3b)
- triage/updateStatus/close all `authorize('update', $concern)` (policy: update perm OR assignee). No new redaction this step (list/detail redaction = Steps 3–4).

## 4. Modal map (§4)
- None built this step (UI modals = Steps 4/5). This step is the server contract those modals POST to: `triage`, `status`, `close`.

## 5. Backend gap list
| # | Gap | Fix | Migration? | Test |
|---|---|---|---|---|
| W3 | enter investigating w/o investigation | guard | no | yes |
| W4 | no first-class triage | `@triage` + `SafeguardingLifecycle` + triage fields | **YES** (5 nullable cols — required to persist the triage decision; autonomous local) | yes |
| W6 | referred_external w/o report | guard | no | yes |
| W7 | close w/o validation | `@close` open-work soft-block + override reason | no | yes |
| W3-leave | reported leaves without triage | guard blocks generic updateStatus/close from reported | no | yes |

**Migration** `2026_06_17_150000_add_triage_fields_to_safeguarding_concerns`: `triaged_at` (ts null), `triaged_by_user_id` (fk users nullOnDelete), `triage_substantiation` (string null), `triage_decision` (string null), `triage_notes` (text null). down() drops them.

## 6. Incidents-consistency (§7)
- No UI surface. Backend lifecycle is Safeguarding-specific (its own §4 state machine). The triage/close/guard *pattern* (service + controller + reasons surfaced to UI) mirrors how Incidents enforces its lifecycle; UI parity verified Steps 4–5. Nothing to log.

## 7. Cross-module touchpoints (§6)
- Observer untouched (status changes have no observer side-effects today; X3 state-sync = Step 8). Triage-investigate creates a `SafeguardingInvestigation` directly (not via the controller store) so it isn't double-guarded.

## 8. Pages / routes to retire → redirect
- None.

## 9. Execution checklist
- [ ] Migration (triage fields) + model fillable/casts
- [ ] `SafeguardingLifecycle` service (transitions + guard + labels + open-work/inv/report helpers)
- [ ] `@triage` action + route
- [ ] `@updateStatus` → guard
- [ ] `@close` → W7 gate (open-work soft-block + override reason appended to closure_summary)
- [ ] Update `SafeguardingConcernControllerTest` (update_status → legal transition; valid_transitions → enforced)
- [ ] New `SafeguardingLifecycleTest` (every guard + triage paths)
- [ ] Run migration locally; pint new files; tests green; no regressions
- [ ] Commit + tick PROGRESS

## 10. Notes / decisions
- `triaged` from a refer path stays `triaged` (not `referred_external`) until a report is logged — matches the "referral indicated, none logged" prototype state + W6.
- Override reason has no column → appended to `closure_summary` with a clear marker (keeps it auditable + surfaced; avoids speculative schema).
- Investigation-store still sets `investigating` directly (legit entry, gate satisfied by the created record). Adding a "must be triaged to start investigation" server guard is deferred unless tests show a hole — UI disables it (Step 4/5).
