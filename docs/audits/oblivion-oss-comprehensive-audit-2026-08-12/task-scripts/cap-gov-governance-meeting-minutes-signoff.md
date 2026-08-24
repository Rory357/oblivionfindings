# CAP-GOV-GOVERNANCE-MEETING-MINUTES-SIGNOFF: Meeting minutes approval and signing

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`
- Owning module: Governance
- Legacy family: `GOV-GOVERNANCE-MEETING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/meetings` (`governance.meetings.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.meetings.view`, `permission:governance.meetings.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/meetings` (`governance.meetings.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/meetings/{meeting}/minutes` (`governance.meetings.minutes.store`, action `storeMinutes`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:250-274`; `content_blocks`.
3. Invoke only the owning control for `PUT governance/meetings/{meeting}/minutes` (`governance.meetings.minutes.update`, action `updateMinutes`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:276-294`; `content_blocks`.
4. Invoke only the owning control for `POST governance/meetings/{meeting}/minutes/approve` (`governance.meetings.minutes.approve`, action `approveMinutes`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:296-313`; no exact validation fields extracted.
5. Invoke only the owning control for `POST governance/meetings/{meeting}/sign-minutes` (`governance.meetings.minutes.sign`, action `signMinutes`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:359-375`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeMinutes` / `ROUTE-0944` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:250`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMinutes` / `ROUTE-0945` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:276`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approveMinutes` / `ROUTE-0946` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:296`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `signMinutes` / `ROUTE-0949` at `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:359`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0944` / `storeMinutes`: fields `content_blocks`; success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:273 `return redirect()->back()->with('success', 'Minutes drafted.');`.
- `ROUTE-0945` / `updateMinutes`: fields `content_blocks`; success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:293 `return redirect()->back()->with('success', 'Minutes updated.');`.
- `ROUTE-0946` / `approveMinutes`: success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:312 `return redirect()->back()->with('success', 'Minutes approved.');`.
- `ROUTE-0949` / `signMinutes`: success app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:374 `return redirect()->back()->with('success', 'Minutes signed successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:263 `$minutes = MeetingMinute::create([`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:271 `$meeting->update(['status' => 'minutes_draft']);`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:288 `$meeting->minutes->update([`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:304 `$meeting->minutes->update([`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:310 `$meeting->update(['status' => 'minutes_approved']);`; responses app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:273 `return redirect()->back()->with('success', 'Minutes drafted.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:281 `return redirect()->back()->with('error', 'Minutes have not been created for this meeting yet.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:293 `return redirect()->back()->with('success', 'Minutes updated.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:301 `return redirect()->back()->with('error', 'Minutes have not been created for this meeting yet.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:312 `return redirect()->back()->with('success', 'Minutes approved.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:365 `return redirect()->back()->with('error', 'No minutes found for this meeting.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:369 `return redirect()->back()->with('error', 'Minutes must be approved before signing.');`; app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:374 `return redirect()->back()->with('success', 'Minutes signed successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/meetings/{meeting}/minutes` — `governance.meetings.minutes.store` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@storeMinutes` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:250` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `PUT governance/meetings/{meeting}/minutes` — `governance.meetings.minutes.update` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@updateMinutes` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:276` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `POST governance/meetings/{meeting}/minutes/approve` — `governance.meetings.minutes.approve` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@approveMinutes` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:296` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`
- `POST governance/meetings/{meeting}/sign-minutes` — `governance.meetings.minutes.sign` — `App\Domain\Governance\Http\Controllers\GovernanceMeetingController@signMinutes` — `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:359` — middleware `web, auth, permission:governance.meetings.view, permission:governance.meetings.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
