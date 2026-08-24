# CLI-CLIENT-ONBOARDING: Client Onboarding

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.onboarding.manage|clients.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-ONBOARDING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.onboarding.manage|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.onboarding.manage|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST clients/{client}/onboarding/{key}` (`clients.onboarding.toggle`, action `toggle`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientOnboardingController.php:19-60`; no exact validation fields extracted.
3. Invoke only the owning control for `POST operations/clients/{client}/onboarding/{key}` (`operations.clients.onboarding.toggle`, action `toggle`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientOnboardingController.php:19-60`; no exact validation fields extracted.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `toggle` / `ROUTE-0183` at `app/Http/Controllers/ClientOnboardingController.php:19`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggle` / `ROUTE-2027` at `app/Http/Controllers/ClientOnboardingController.php:19`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientOnboardingController.php:44 `ClientOnboardingOverride::updateOrCreate(`; app/Http/Controllers/ClientOnboardingController.php:54 `->delete();`; responses app/Http/Controllers/ClientOnboardingController.php:59 `return back();`; audit calls app/Http/Controllers/ClientOnboardingController.php:49 `AuditLogger::log('clients.onboarding.override.set', $client, ['key' => $key]);`; app/Http/Controllers/ClientOnboardingController.php:56 `AuditLogger::log('clients.onboarding.override.cleared', $client, ['key' => $key]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/onboarding/{key}` — `clients.onboarding.toggle` — `App\Http\Controllers\ClientOnboardingController@toggle` — `app/Http/Controllers/ClientOnboardingController.php:19` — middleware `web, auth, permission:clients.onboarding.manage|clients.update`
- `POST operations/clients/{client}/onboarding/{key}` — `operations.clients.onboarding.toggle` — `App\Http\Controllers\ClientOnboardingController@toggle` — `app/Http/Controllers/ClientOnboardingController.php:19` — middleware `web, auth, permission:clients.onboarding.manage|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientOnboardingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
