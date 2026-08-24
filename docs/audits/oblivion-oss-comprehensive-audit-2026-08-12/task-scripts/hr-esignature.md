# HR-ESIGNATURE: ESignature

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.signatures.manage|hr.documents.manage`
- Owning module: Human resources
- Legacy family: `HR-ESIGNATURE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/signatures/{signature}` (`hr.signatures.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.signatures.manage|hr.documents.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.signatures.manage|hr.documents.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/signatures/{signature}` (`hr.signatures.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/signatures/{signature}/document` (`hr.signatures.document`, action `downloadDocument`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/ESignatureController.php:80-99`.
3. Use `GET|HEAD hr/signatures/pending` (`hr.signatures.pending`, action `pending`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ESignatureController.php:23-42`.
4. Invoke only the owning control for `POST hr/signatures/{signature}/decline` (`hr.signatures.decline`, action `decline`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/ESignatureController.php:127-143`; `reason`.
5. Invoke only the owning control for `POST hr/signatures/{signature}/nudge` (`hr.signatures.nudge`, action `nudge`). Source category: **mutation outcome source gap (nudge)**; controller `app/Http/Controllers/Hr/ESignatureController.php:183-191`; no exact validation fields extracted.
6. Invoke only the owning control for `POST hr/signatures/{signature}/resend` (`hr.signatures.resend`, action `resend`). Source category: **mutation outcome source gap (resend)**; controller `app/Http/Controllers/Hr/ESignatureController.php:193-201`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/signatures/{signature}/sign` (`hr.signatures.sign`, action `sign`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/ESignatureController.php:105-121`; `signature_data`.
8. Invoke only the owning control for `POST hr/signatures/document/{document}/cancel` (`hr.signatures.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/ESignatureController.php:203-211`; no exact validation fields extracted.
9. Invoke only the owning control for `POST hr/signatures/request` (`hr.signatures.request`, action `request`). Source category: **mutation outcome source gap (request)**; controller `app/Http/Controllers/Hr/ESignatureController.php:149-177`; `document_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-1750` at `app/Http/Controllers/Hr/ESignatureController.php:48`; it is not runtime-observed.
- **rejected/returned** is applicable only to `decline` / `ROUTE-1751` at `app/Http/Controllers/Hr/ESignatureController.php:127`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadDocument` / `ROUTE-1752` at `app/Http/Controllers/Hr/ESignatureController.php:80`; it is not runtime-observed.
- **mutation outcome source gap (nudge)** is applicable only to `nudge` / `ROUTE-1753` at `app/Http/Controllers/Hr/ESignatureController.php:183`; it is not runtime-observed.
- **mutation outcome source gap (resend)** is applicable only to `resend` / `ROUTE-1754` at `app/Http/Controllers/Hr/ESignatureController.php:193`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `sign` / `ROUTE-1755` at `app/Http/Controllers/Hr/ESignatureController.php:105`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-1756` at `app/Http/Controllers/Hr/ESignatureController.php:203`; it is not runtime-observed.
- **information presented** is applicable only to `pending` / `ROUTE-1757` at `app/Http/Controllers/Hr/ESignatureController.php:23`; it is not runtime-observed.
- **mutation outcome source gap (request)** is applicable only to `request` / `ROUTE-1758` at `app/Http/Controllers/Hr/ESignatureController.php:149`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/signatures/pending.tsx`, `resources/js/pages/hr/signatures/sign.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1751` / `decline`: fields `reason`; success app/Http/Controllers/Hr/ESignatureController.php:142 `return redirect()->route('hr.signatures.pending')->with('success', 'Signature request declined.');`.
- `ROUTE-1753` / `nudge`: success app/Http/Controllers/Hr/ESignatureController.php:190 `return redirect()->back()->with('success', 'Reminder sent to signer.');`.
- `ROUTE-1754` / `resend`: success app/Http/Controllers/Hr/ESignatureController.php:200 `return redirect()->back()->with('success', 'Signature request resent.');`.
- `ROUTE-1755` / `sign`: fields `signature_data`; success app/Http/Controllers/Hr/ESignatureController.php:120 `return redirect()->route('hr.signatures.pending')->with('success', 'Document signed successfully.');`.
- `ROUTE-1756` / `cancel`: success app/Http/Controllers/Hr/ESignatureController.php:210 `return redirect()->back()->with('success', $count . ' outstanding request(s) cancelled.');`.
- `ROUTE-1758` / `request`: fields `document_id`; success app/Http/Controllers/Hr/ESignatureController.php:176 `return redirect()->back()->with('success', 'Signature requests sent.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/ESignatureController.php:55 `return Inertia::render('hr/signatures/sign', [`; app/Http/Controllers/Hr/ESignatureController.php:139 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/ESignatureController.php:142 `return redirect()->route('hr.signatures.pending')->with('success', 'Signature request declined.');`; app/Http/Controllers/Hr/ESignatureController.php:98 `return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);`; app/Http/Controllers/Hr/ESignatureController.php:190 `return redirect()->back()->with('success', 'Reminder sent to signer.');`; app/Http/Controllers/Hr/ESignatureController.php:200 `return redirect()->back()->with('success', 'Signature request resent.');`; app/Http/Controllers/Hr/ESignatureController.php:117 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/ESignatureController.php:120 `return redirect()->route('hr.signatures.pending')->with('success', 'Document signed successfully.');`; app/Http/Controllers/Hr/ESignatureController.php:210 `return redirect()->back()->with('success', $count . ' outstanding request(s) cancelled.');`; app/Http/Controllers/Hr/ESignatureController.php:39 `return Inertia::render('hr/signatures/pending', [`; app/Http/Controllers/Hr/ESignatureController.php:176 `return redirect()->back()->with('success', 'Signature requests sent.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/signatures/{signature}` — `hr.signatures.show` — `App\Http\Controllers\Hr\ESignatureController@show` — `app/Http/Controllers/Hr/ESignatureController.php:48` — middleware `web, auth`
- `POST hr/signatures/{signature}/decline` — `hr.signatures.decline` — `App\Http\Controllers\Hr\ESignatureController@decline` — `app/Http/Controllers/Hr/ESignatureController.php:127` — middleware `web, auth`
- `GET|HEAD hr/signatures/{signature}/document` — `hr.signatures.document` — `App\Http\Controllers\Hr\ESignatureController@downloadDocument` — `app/Http/Controllers/Hr/ESignatureController.php:80` — middleware `web, auth`
- `POST hr/signatures/{signature}/nudge` — `hr.signatures.nudge` — `App\Http\Controllers\Hr\ESignatureController@nudge` — `app/Http/Controllers/Hr/ESignatureController.php:183` — middleware `web, auth, permission:hr.signatures.manage|hr.documents.manage`
- `POST hr/signatures/{signature}/resend` — `hr.signatures.resend` — `App\Http\Controllers\Hr\ESignatureController@resend` — `app/Http/Controllers/Hr/ESignatureController.php:193` — middleware `web, auth, permission:hr.signatures.manage|hr.documents.manage`
- `POST hr/signatures/{signature}/sign` — `hr.signatures.sign` — `App\Http\Controllers\Hr\ESignatureController@sign` — `app/Http/Controllers/Hr/ESignatureController.php:105` — middleware `web, auth`
- `POST hr/signatures/document/{document}/cancel` — `hr.signatures.cancel` — `App\Http\Controllers\Hr\ESignatureController@cancel` — `app/Http/Controllers/Hr/ESignatureController.php:203` — middleware `web, auth, permission:hr.signatures.manage|hr.documents.manage`
- `GET|HEAD hr/signatures/pending` — `hr.signatures.pending` — `App\Http\Controllers\Hr\ESignatureController@pending` — `app/Http/Controllers/Hr/ESignatureController.php:23` — middleware `web, auth`
- `POST hr/signatures/request` — `hr.signatures.request` — `App\Http\Controllers\Hr\ESignatureController@request` — `app/Http/Controllers/Hr/ESignatureController.php:149` — middleware `web, auth, permission:hr.signatures.manage|hr.documents.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ESignatureController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/signatures/pending.tsx`, `resources/js/pages/hr/signatures/sign.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
