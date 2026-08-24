# HR-POLICY-ATTESTATION: Policy Attestation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.policies.view`, `permission:hr.policies.attest`
- Owning module: Human resources
- Legacy family: `HR-POLICY-ATTESTATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/documents/policies/attestations` (`hr.policies.attestations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.policies.view`, `permission:hr.policies.attest`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.policies.view`, `permission:hr.policies.attest`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/documents/policies/attestations` (`hr.policies.attestations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/documents/policies/{policy}/attest` (`hr.policies.attestations.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PolicyAttestationController.php:55-99`; `attestation_method`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1412` at `app/Http/Controllers/Hr/PolicyAttestationController.php:55`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-1417` at `app/Http/Controllers/Hr/PolicyAttestationController.php:22`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/documents/policies/attestations.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1412` / `store`: fields `attestation_method`; success app/Http/Controllers/Hr/PolicyAttestationController.php:98 `return redirect()->back()->with('success', 'Policy attestation recorded.');`; failure app/Http/Controllers/Hr/PolicyAttestationController.php:64 `return redirect()->back()->withErrors(['policy' => 'This policy does not require attestation.']);`; app/Http/Controllers/Hr/PolicyAttestationController.php:70 `return redirect()->back()->withErrors(['policy' => 'This policy has no published version to attest.']);`; app/Http/Controllers/Hr/PolicyAttestationController.php:80 `return redirect()->back()->withErrors(['policy' => 'You have already attested to this version of the policy.']);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Hr/PolicyAttestationController.php:64 `return redirect()->back()->withErrors(['policy' => 'This policy does not require attestation.']);`; app/Http/Controllers/Hr/PolicyAttestationController.php:70 `return redirect()->back()->withErrors(['policy' => 'This policy has no published version to attest.']);`; app/Http/Controllers/Hr/PolicyAttestationController.php:80 `return redirect()->back()->withErrors(['policy' => 'You have already attested to this version of the policy.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PolicyAttestationController.php:87 `HrPolicyAttestation::create([`; responses app/Http/Controllers/Hr/PolicyAttestationController.php:64 `return redirect()->back()->withErrors(['policy' => 'This policy does not require attestation.']);`; app/Http/Controllers/Hr/PolicyAttestationController.php:70 `return redirect()->back()->withErrors(['policy' => 'This policy has no published version to attest.']);`; app/Http/Controllers/Hr/PolicyAttestationController.php:80 `return redirect()->back()->withErrors(['policy' => 'You have already attested to this version of the policy.']);`; app/Http/Controllers/Hr/PolicyAttestationController.php:98 `return redirect()->back()->with('success', 'Policy attestation recorded.');`; app/Http/Controllers/Hr/PolicyAttestationController.php:41 `return Inertia::render('hr/documents/policies/attestations', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/documents/policies/{policy}/attest` — `hr.policies.attestations.store` — `App\Http\Controllers\Hr\PolicyAttestationController@store` — `app/Http/Controllers/Hr/PolicyAttestationController.php:55` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.attest`
- `GET|HEAD hr/documents/policies/attestations` — `hr.policies.attestations.index` — `App\Http\Controllers\Hr\PolicyAttestationController@index` — `app/Http/Controllers/Hr/PolicyAttestationController.php:22` — middleware `web, auth, permission:hr.policies.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PolicyAttestationController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/documents/policies/attestations.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
