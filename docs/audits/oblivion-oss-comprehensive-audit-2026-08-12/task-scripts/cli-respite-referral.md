# CLI-RESPITE-REFERRAL: Respite Referral

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.create`, `permission:respite.viewAny`, `permission:respite.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-RESPITE-REFERRAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/referrals/{referral}` (`respite.referrals.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.create`, `permission:respite.viewAny`, `permission:respite.update`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.create`, `permission:respite.viewAny`, `permission:respite.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/referrals/{referral}` (`respite.referrals.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/referrals/create` (`respite.referrals.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteReferralController.php:50-55`.
3. Invoke only the owning control for `POST respite/referrals` (`respite.referrals.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteReferralController.php:57-174`; `client_id`, `new_client`, `new_client.first_name`, `new_client.last_name`, `new_client.date_of_birth`, `new_client.nhi_number`, `new_client.site_id`, `nhi_number`, `referrer_type`, `referrer_name`, `referrer_contact`, `third_party_source_type`, `third_party_source_name`, `third_party_collection_consent`, `referral_reason`, `urgency`, `funding_source`.
4. Invoke only the owning control for `PUT respite/referrals/{referral}` (`respite.referrals.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteReferralController.php:186-207`; `triage_notes`, `risk_level`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2417` at `app/Http/Controllers/Respite/RespiteReferralController.php:57`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2418` at `app/Http/Controllers/Respite/RespiteReferralController.php:176`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2419` at `app/Http/Controllers/Respite/RespiteReferralController.php:186`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2420` at `app/Http/Controllers/Respite/RespiteReferralController.php:50`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/referrals/create.tsx`, `resources/js/pages/respite/referrals/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2417` / `store`: fields `client_id`, `new_client`, `new_client.first_name`, `new_client.last_name`, `new_client.date_of_birth`, `new_client.nhi_number`, `new_client.site_id`, `nhi_number`, `referrer_type`, `referrer_name`, `referrer_contact`, `third_party_source_type`, `third_party_source_name`, `third_party_collection_consent`, `referral_reason`, `urgency`, `funding_source`; success app/Http/Controllers/Respite/RespiteReferralController.php:173 `return back()->with('success', 'Respite referral created.');`.
- `ROUTE-2419` / `update`: fields `triage_notes`, `risk_level`; success app/Http/Controllers/Respite/RespiteReferralController.php:206 `return back()->with('success', 'Referral updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteReferralController.php:115 `$client = Client::create([`; app/Http/Controllers/Respite/RespiteReferralController.php:131 `$referral = RespiteReferral::create([`; app/Http/Controllers/Respite/RespiteReferralController.php:198 `$referral->update($validated);`; responses app/Http/Controllers/Respite/RespiteReferralController.php:173 `return back()->with('success', 'Respite referral created.');`; app/Http/Controllers/Respite/RespiteReferralController.php:181 `return Inertia::render('respite/referrals/show', [`; app/Http/Controllers/Respite/RespiteReferralController.php:206 `return back()->with('success', 'Referral updated.');`; app/Http/Controllers/Respite/RespiteReferralController.php:52 `return Inertia::render('respite/referrals/create', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteReferralController.php:167 `event(new RespiteEvent('respite.referral.created', [`; app/Http/Controllers/Respite/RespiteReferralController.php:200 `event(new RespiteEvent('respite.referral.updated', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST respite/referrals` — `respite.referrals.store` — `App\Http\Controllers\Respite\RespiteReferralController@store` — `app/Http/Controllers/Respite/RespiteReferralController.php:57` — middleware `web, auth, permission:respite.create`
- `GET|HEAD respite/referrals/{referral}` — `respite.referrals.show` — `App\Http\Controllers\Respite\RespiteReferralController@show` — `app/Http/Controllers/Respite/RespiteReferralController.php:176` — middleware `web, auth, permission:respite.viewAny`
- `PUT respite/referrals/{referral}` — `respite.referrals.update` — `App\Http\Controllers\Respite\RespiteReferralController@update` — `app/Http/Controllers/Respite/RespiteReferralController.php:186` — middleware `web, auth, permission:respite.update`
- `GET|HEAD respite/referrals/create` — `respite.referrals.create` — `App\Http\Controllers\Respite\RespiteReferralController@create` — `app/Http/Controllers/Respite/RespiteReferralController.php:50` — middleware `web, auth, permission:respite.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteReferralController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/referrals/create.tsx`, `resources/js/pages/respite/referrals/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
