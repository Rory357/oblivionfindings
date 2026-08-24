# CAP-HS-SAFE-WORK-PROCEDURE-REVIEW-APPROVAL: Safe work procedure review changes and approval

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:procedures.approve`, `permission:procedures.manage`, `permission:procedures.create|procedures.manage`
- Owning module: Health and safety
- Legacy family: `HS-SAFE-WORK-PROCEDURE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/procedures` (`health-safety.procedures.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:procedures.approve`, `permission:procedures.manage`, `permission:procedures.create|procedures.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:procedures.approve`, `permission:procedures.manage`, `permission:procedures.create|procedures.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/procedures` (`health-safety.procedures.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/procedures/{procedure}/approve` (`health-safety.procedures.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:200-220`; `note`, `review_date`.
3. Invoke only the owning control for `POST health-safety/procedures/{procedure}/record-review` (`health-safety.procedures.record-review`, action `recordReview`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:237-253`; `review_date`, `note`.
4. Invoke only the owning control for `POST health-safety/procedures/{procedure}/request-changes` (`health-safety.procedures.request-changes`, action `requestChanges`). Source category: **mutation outcome source gap (requestChanges)**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:222-235`; `note`.
5. Invoke only the owning control for `POST health-safety/procedures/{procedure}/submit-for-review` (`health-safety.procedures.submit-for-review`, action `submitForReview`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:189-198`; no exact validation fields extracted.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1182` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:200`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordReview` / `ROUTE-1188` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:237`; it is not runtime-observed.
- **mutation outcome source gap (requestChanges)** is applicable only to `requestChanges` / `ROUTE-1189` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:222`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitForReview` / `ROUTE-1191` at `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:189`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1182` / `approve`: fields `note`, `review_date`; success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:219 `return back()->with('success', 'Procedure approved.');`.
- `ROUTE-1188` / `recordReview`: fields `review_date`, `note`; success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:252 `return back()->with('success', 'Review recorded.');`.
- `ROUTE-1189` / `requestChanges`: fields `note`; success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:234 `return back()->with('success', 'Returned to draft for changes.');`.
- `ROUTE-1191` / `submitForReview`: success app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:192 `return back()->with('success', 'Procedure is already approved.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:197 `return back()->with('success', 'Procedure submitted for review.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:207 `$procedure->update([`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:244 `$procedure->update(['review_date' => $validated['review_date'], 'updated_by' => $request->user()->id]);`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:228 `$procedure->update(['status' => 'draft', 'updated_by' => $request->user()->id]);`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:195 `$procedure->update(['status' => 'under_review', 'updated_by' => $request->user()->id]);`; responses app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:219 `return back()->with('success', 'Procedure approved.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:252 `return back()->with('success', 'Review recorded.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:234 `return back()->with('success', 'Returned to draft for changes.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:192 `return back()->with('success', 'Procedure is already approved.');`; app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:197 `return back()->with('success', 'Procedure submitted for review.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/procedures/{procedure}/approve` — `health-safety.procedures.approve` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@approve` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:200` — middleware `web, auth, permission:procedures.approve`
- `POST health-safety/procedures/{procedure}/record-review` — `health-safety.procedures.record-review` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@recordReview` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:237` — middleware `web, auth, permission:procedures.manage`
- `POST health-safety/procedures/{procedure}/request-changes` — `health-safety.procedures.request-changes` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@requestChanges` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:222` — middleware `web, auth, permission:procedures.manage`
- `POST health-safety/procedures/{procedure}/submit-for-review` — `health-safety.procedures.submit-for-review` — `App\Http\Controllers\HealthSafety\SafeWorkProcedureController@submitForReview` — `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:189` — middleware `web, auth, permission:procedures.create|procedures.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
