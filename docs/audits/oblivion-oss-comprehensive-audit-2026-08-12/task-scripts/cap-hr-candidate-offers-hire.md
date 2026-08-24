# CAP-HR-CANDIDATE-OFFERS-HIRE: Offer approval response and hire conversion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`, `permission:hr.employees.manage`
- Owning module: Human resources
- Legacy family: `HR-CANDIDATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/recruitment/applications/{application}/offer/create` (`hr.offers.create`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`, `permission:hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`, `permission:hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/recruitment/applications/{application}/offer/create` (`hr.offers.create`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/recruitment/offers/{offer}/letter` (`hr.offers.letter`, action `downloadOfferLetter`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/CandidateController.php:1388-1419`.
3. Invoke only the owning control for `POST hr/recruitment/offers` (`hr.offers.store`, action `storeOffer`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:1196-1282`; `application_id`.
4. Invoke only the owning control for `POST hr/recruitment/offers/{offer}/approve` (`hr.offers.approve`, action `approveOffer`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/CandidateController.php:1421-1457`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/recruitment/offers/{offer}/convert` (`hr.offers.convert`, action `convertToEmployee`). Source category: **mutation outcome source gap (convertToEmployee)**; controller `app/Http/Controllers/Hr/CandidateController.php:1720-1755`; no exact validation fields extracted.
6. Invoke only the owning control for `POST hr/recruitment/offers/{offer}/decline-approval` (`hr.offers.decline-approval`, action `declineOfferApproval`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/CandidateController.php:1492-1518`; `reason`.
7. Invoke only the owning control for `POST hr/recruitment/offers/{offer}/resend` (`hr.offers.resend`, action `resendOffer`). Source category: **mutation outcome source gap (resendOffer)**; controller `app/Http/Controllers/Hr/CandidateController.php:1341-1367`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/recruitment/offers/{offer}/respond` (`hr.offers.respond`, action `respondOffer`). Source category: **mutation outcome source gap (respondOffer)**; controller `app/Http/Controllers/Hr/CandidateController.php:1579-1714`; `response`.
9. Invoke only the owning control for `POST hr/recruitment/offers/{offer}/send` (`hr.offers.send`, action `sendOffer`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:1284-1335`; no exact validation fields extracted.
10. Invoke only the owning control for `POST hr/recruitment/offers/{offer}/submit-approval` (`hr.offers.submit-approval`, action `submitOfferApproval`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:1460-1489`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `createOffer` / `ROUTE-1670` at `app/Http/Controllers/Hr/CandidateController.php:1123`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeOffer` / `ROUTE-1706` at `app/Http/Controllers/Hr/CandidateController.php:1196`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approveOffer` / `ROUTE-1707` at `app/Http/Controllers/Hr/CandidateController.php:1421`; it is not runtime-observed.
- **mutation outcome source gap (convertToEmployee)** is applicable only to `convertToEmployee` / `ROUTE-1708` at `app/Http/Controllers/Hr/CandidateController.php:1720`; it is not runtime-observed.
- **rejected/returned** is applicable only to `declineOfferApproval` / `ROUTE-1709` at `app/Http/Controllers/Hr/CandidateController.php:1492`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadOfferLetter` / `ROUTE-1710` at `app/Http/Controllers/Hr/CandidateController.php:1388`; it is not runtime-observed.
- **mutation outcome source gap (resendOffer)** is applicable only to `resendOffer` / `ROUTE-1711` at `app/Http/Controllers/Hr/CandidateController.php:1341`; it is not runtime-observed.
- **mutation outcome source gap (respondOffer)** is applicable only to `respondOffer` / `ROUTE-1712` at `app/Http/Controllers/Hr/CandidateController.php:1579`; it is not runtime-observed.
- **created/recorded** is applicable only to `sendOffer` / `ROUTE-1713` at `app/Http/Controllers/Hr/CandidateController.php:1284`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitOfferApproval` / `ROUTE-1714` at `app/Http/Controllers/Hr/CandidateController.php:1460`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/candidates/create-offer.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1706` / `storeOffer`: fields `application_id`; success app/Http/Controllers/Hr/CandidateController.php:1281 `return redirect()->back()->with('success', 'Offer created successfully.');`.
- `ROUTE-1707` / `approveOffer`: success app/Http/Controllers/Hr/CandidateController.php:1431 `return redirect()->back()->with('success', 'Offer already approved.');`; app/Http/Controllers/Hr/CandidateController.php:1456 `return redirect()->back()->with('success', 'Offer approved.');`.
- `ROUTE-1708` / `convertToEmployee`: success app/Http/Controllers/Hr/CandidateController.php:1754 `return redirect()->back()->with('success', "Employee profile created (#{$profile->id}).");`.
- `ROUTE-1709` / `declineOfferApproval`: fields `reason`; success app/Http/Controllers/Hr/CandidateController.php:1517 `return redirect()->back()->with('success', 'Offer declined.');`.
- `ROUTE-1711` / `resendOffer`: success app/Http/Controllers/Hr/CandidateController.php:1366 `return redirect()->back()->with('success', 'Offer link resent to the candidate.');`.
- `ROUTE-1712` / `respondOffer`: fields `response`; success app/Http/Controllers/Hr/CandidateController.php:1682 `return redirect()->back()->with('success', 'Offer accepted. Account provisioning is pending — a user with employee-management rights can Convert to finish the hire.');`; app/Http/Controllers/Hr/CandidateController.php:1702 `return redirect()->back()->with('success', 'Offer accepted — employee profile created and onboarding started.');`; app/Http/Controllers/Hr/CandidateController.php:1708 `return redirect()->back()->with('success', 'Offer accepted. Converting to an employee could not complete automatically — use Convert to finish.');`; app/Http/Controllers/Hr/CandidateController.php:1713 `return redirect()->back()->with('success', 'Offer response recorded.');`; failure app/Http/Controllers/Hr/CandidateController.php:1611 `return redirect()->back()->withErrors(['terms_accepted' => 'Terms must be accepted when applying a signature.']);`.
- `ROUTE-1713` / `sendOffer`: success app/Http/Controllers/Hr/CandidateController.php:1334 `return redirect()->back()->with('success', 'Offer sent — portal link emailed to the candidate.');`.
- `ROUTE-1714` / `submitOfferApproval`: success app/Http/Controllers/Hr/CandidateController.php:1488 `return redirect()->back()->with('success', 'Offer submitted for approval.');`.

## Failure and recovery paths

- `respondOffer`: app/Http/Controllers/Hr/CandidateController.php:1611 `return redirect()->back()->withErrors(['terms_accepted' => 'Terms must be accepted when applying a signature.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CandidateController.php:1255 `HrOffer::create([`; app/Http/Controllers/Hr/CandidateController.php:1434 `$offer->update([`; app/Http/Controllers/Hr/CandidateController.php:1506 `$offer->update([`; app/Http/Controllers/Hr/CandidateController.php:1358 `$offer->update([`; app/Http/Controllers/Hr/CandidateController.php:1616 `$offer->update([`; app/Http/Controllers/Hr/CandidateController.php:1637 `$candidate->update([`; app/Http/Controllers/Hr/CandidateController.php:1643 `$application->update([`; app/Http/Controllers/Hr/CandidateController.php:1304 `$offer->update([`; app/Http/Controllers/Hr/CandidateController.php:1475 `$offer->update([`; responses app/Http/Controllers/Hr/CandidateController.php:1149 `return Inertia::render('hr/candidates/create-offer', [`; app/Http/Controllers/Hr/CandidateController.php:1238 `return redirect()->back()->with('error', 'Cannot create an offer for a terminal candidate stage.');`; app/Http/Controllers/Hr/CandidateController.php:1243 `return redirect()->back()->with('error', 'An offer already exists for this application.');`; app/Http/Controllers/Hr/CandidateController.php:1281 `return redirect()->back()->with('success', 'Offer created successfully.');`; app/Http/Controllers/Hr/CandidateController.php:1431 `return redirect()->back()->with('success', 'Offer already approved.');`; app/Http/Controllers/Hr/CandidateController.php:1456 `return redirect()->back()->with('success', 'Offer approved.');`; app/Http/Controllers/Hr/CandidateController.php:1734 `return redirect()->back()->with('error', 'Cannot convert: offer has not been accepted.');`; app/Http/Controllers/Hr/CandidateController.php:1740 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/CandidateController.php:1754 `return redirect()->back()->with('success', "Employee profile created (#{$profile->id}).");`; app/Http/Controllers/Hr/CandidateController.php:1501 `return redirect()->back()->with('error', 'This offer can no longer be declined.');`; app/Http/Controllers/Hr/CandidateController.php:1517 `return redirect()->back()->with('success', 'Offer declined.');`; app/Http/Controllers/Hr/CandidateController.php:1402 `return $disk->download($offer->offer_letter_path, $offer->offer_letter_name ?? 'offer-letter.pdf');`; app/Http/Controllers/Hr/CandidateController.php:1418 `return $pdf->download("offer-letter-{$slug}.pdf");`; app/Http/Controllers/Hr/CandidateController.php:1351 `return redirect()->back()->with('error', 'Send the offer before resending the link.');`; app/Http/Controllers/Hr/CandidateController.php:1355 `return redirect()->back()->with('error', 'This offer has already been responded to.');`; app/Http/Controllers/Hr/CandidateController.php:1366 `return redirect()->back()->with('success', 'Offer link resent to the candidate.');`; app/Http/Controllers/Hr/CandidateController.php:1599 `return redirect()->back()->with('error', 'Offer must be sent before recording a response.');`; app/Http/Controllers/Hr/CandidateController.php:1603 `return redirect()->back()->with('error', 'Offer has already been accepted.');`; app/Http/Controllers/Hr/CandidateController.php:1611 `return redirect()->back()->withErrors(['terms_accepted' => 'Terms must be accepted when applying a signature.']);`; app/Http/Controllers/Hr/CandidateController.php:1651 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/CandidateController.php:1654 `return redirect()->back()->with('error', 'Offer response could not be recorded.');`; app/Http/Controllers/Hr/CandidateController.php:1682 `return redirect()->back()->with('success', 'Offer accepted. Account provisioning is pending — a user with employee-management rights can Convert to finish the hire.');`; app/Http/Controllers/Hr/CandidateController.php:1702 `return redirect()->back()->with('success', 'Offer accepted — employee profile created and onboarding started.');`; app/Http/Controllers/Hr/CandidateController.php:1708 `return redirect()->back()->with('success', 'Offer accepted. Converting to an employee could not complete automatically — use Convert to finish.');`; app/Http/Controllers/Hr/CandidateController.php:1713 `return redirect()->back()->with('success', 'Offer response recorded.');`; app/Http/Controllers/Hr/CandidateController.php:1295 `return redirect()->back()->with('error', 'Offer must be approved before sending.');`; app/Http/Controllers/Hr/CandidateController.php:1299 `return redirect()->back()->with('error', 'Offer has already been sent.');`; app/Http/Controllers/Hr/CandidateController.php:1317 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/CandidateController.php:1320 `return redirect()->back()->with('error', 'Offer could not be sent.');`; app/Http/Controllers/Hr/CandidateController.php:1334 `return redirect()->back()->with('success', 'Offer sent — portal link emailed to the candidate.');`; app/Http/Controllers/Hr/CandidateController.php:1469 `return redirect()->back()->with('error', 'This offer has already been sent.');`; app/Http/Controllers/Hr/CandidateController.php:1472 `return redirect()->back()->with('error', 'This offer is already approved.');`; app/Http/Controllers/Hr/CandidateController.php:1488 `return redirect()->back()->with('success', 'Offer submitted for approval.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/recruitment/applications/{application}/offer/create` — `hr.offers.create` — `App\Http\Controllers\Hr\CandidateController@createOffer` — `app/Http/Controllers/Hr/CandidateController.php:1123` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/offers` — `hr.offers.store` — `App\Http\Controllers\Hr\CandidateController@storeOffer` — `app/Http/Controllers/Hr/CandidateController.php:1196` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/offers/{offer}/approve` — `hr.offers.approve` — `App\Http\Controllers\Hr\CandidateController@approveOffer` — `app/Http/Controllers/Hr/CandidateController.php:1421` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/offers/{offer}/convert` — `hr.offers.convert` — `App\Http\Controllers\Hr\CandidateController@convertToEmployee` — `app/Http/Controllers/Hr/CandidateController.php:1720` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage, permission:hr.employees.manage`
- `POST hr/recruitment/offers/{offer}/decline-approval` — `hr.offers.decline-approval` — `App\Http\Controllers\Hr\CandidateController@declineOfferApproval` — `app/Http/Controllers/Hr/CandidateController.php:1492` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `GET|HEAD hr/recruitment/offers/{offer}/letter` — `hr.offers.letter` — `App\Http\Controllers\Hr\CandidateController@downloadOfferLetter` — `app/Http/Controllers/Hr/CandidateController.php:1388` — middleware `web, auth, permission:hr.recruitment.view`
- `POST hr/recruitment/offers/{offer}/resend` — `hr.offers.resend` — `App\Http\Controllers\Hr\CandidateController@resendOffer` — `app/Http/Controllers/Hr/CandidateController.php:1341` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/offers/{offer}/respond` — `hr.offers.respond` — `App\Http\Controllers\Hr\CandidateController@respondOffer` — `app/Http/Controllers/Hr/CandidateController.php:1579` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/offers/{offer}/send` — `hr.offers.send` — `App\Http\Controllers\Hr\CandidateController@sendOffer` — `app/Http/Controllers/Hr/CandidateController.php:1284` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/offers/{offer}/submit-approval` — `hr.offers.submit-approval` — `App\Http\Controllers\Hr\CandidateController@submitOfferApproval` — `app/Http/Controllers/Hr/CandidateController.php:1460` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CandidateController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/candidates/create-offer.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
