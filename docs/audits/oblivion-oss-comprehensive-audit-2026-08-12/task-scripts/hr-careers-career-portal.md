# HR-CAREERS-CAREER-PORTAL: Career Portal

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution
- Owning module: Human resources
- Legacy family: `HR-CAREERS-CAREER-PORTAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `careers` (`careers.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution.
- Exact middleware atoms: `web`, `throttle:30,1`, `throttle:10,1`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD careers` (`careers.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD careers/jobs/{job:slug}/apply` (`careers.apply`, action `showApply`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Careers/CareerPortalController.php:108-146`.
3. Use `GET|HEAD careers/offers/{token}` (`careers.offer.show`, action `showOffer`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Careers/CareerPortalController.php:261-300`.
4. Invoke only the owning control for `POST careers/jobs/{job:slug}/apply` (`careers.apply.store`, action `submitApplication`). Source category: **created/recorded**; controller `app/Http/Controllers/Careers/CareerPortalController.php:148-238`; `first_name`.
5. Invoke only the owning control for `POST careers/offers/{token}` (`careers.offer.respond`, action `respondToOffer`). Source category: **mutation outcome source gap (respondToOffer)**; controller `app/Http/Controllers/Careers/CareerPortalController.php:302-406`; `response`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0088` at `app/Http/Controllers/Careers/CareerPortalController.php:28`; it is not runtime-observed.
- **information presented** is applicable only to `showApply` / `ROUTE-0091` at `app/Http/Controllers/Careers/CareerPortalController.php:108`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitApplication` / `ROUTE-0092` at `app/Http/Controllers/Careers/CareerPortalController.php:148`; it is not runtime-observed.
- **information presented** is applicable only to `showOffer` / `ROUTE-0093` at `app/Http/Controllers/Careers/CareerPortalController.php:261`; it is not runtime-observed.
- **mutation outcome source gap (respondToOffer)** is applicable only to `respondToOffer` / `ROUTE-0094` at `app/Http/Controllers/Careers/CareerPortalController.php:302`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/careers/apply.tsx`, `resources/js/pages/careers/index.tsx`, `resources/js/pages/careers/offer-response.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0091` / `showApply`: failure app/Http/Controllers/Careers/CareerPortalController.php:111 `abort(404);`; app/Http/Controllers/Careers/CareerPortalController.php:115 `abort(404);`.
- `ROUTE-0092` / `submitApplication`: fields `first_name`; success app/Http/Controllers/Careers/CareerPortalController.php:237 `->with('success', 'Thanks, your application has been received.');`; failure app/Http/Controllers/Careers/CareerPortalController.php:151 `abort(404);`; app/Http/Controllers/Careers/CareerPortalController.php:155 `return redirect()->back()->withErrors(['application' => 'This job is no longer accepting applications.']);`; app/Http/Controllers/Careers/CareerPortalController.php:227 `return redirect()->back()->withErrors(['application' => $exception->getMessage()]);`; app/Http/Controllers/Careers/CareerPortalController.php:230 `return redirect()->back()->withErrors(['application' => 'Application could not be submitted.']);`.
- `ROUTE-0094` / `respondToOffer`: fields `response`; success app/Http/Controllers/Careers/CareerPortalController.php:405 `->with('success', 'Your response has been recorded. Thank you.');`; failure app/Http/Controllers/Careers/CareerPortalController.php:336 `return redirect()->back()->withErrors(['signature_name' => 'Please enter your full name as a digital signature.']);`; app/Http/Controllers/Careers/CareerPortalController.php:340 `return redirect()->back()->withErrors(['terms_accepted' => 'You must accept the terms to sign this offer.']);`.

## Failure and recovery paths

- `showApply`: app/Http/Controllers/Careers/CareerPortalController.php:111 `abort(404);`; app/Http/Controllers/Careers/CareerPortalController.php:115 `abort(404);`.
- `submitApplication`: app/Http/Controllers/Careers/CareerPortalController.php:151 `abort(404);`; app/Http/Controllers/Careers/CareerPortalController.php:155 `return redirect()->back()->withErrors(['application' => 'This job is no longer accepting applications.']);`; app/Http/Controllers/Careers/CareerPortalController.php:227 `return redirect()->back()->withErrors(['application' => $exception->getMessage()]);`; app/Http/Controllers/Careers/CareerPortalController.php:230 `return redirect()->back()->withErrors(['application' => 'Application could not be submitted.']);`.
- `respondToOffer`: app/Http/Controllers/Careers/CareerPortalController.php:336 `return redirect()->back()->withErrors(['signature_name' => 'Please enter your full name as a digital signature.']);`; app/Http/Controllers/Careers/CareerPortalController.php:340 `return redirect()->back()->withErrors(['terms_accepted' => 'You must accept the terms to sign this offer.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Careers/CareerPortalController.php:344 `$offer->update([`; app/Http/Controllers/Careers/CareerPortalController.php:358 `$candidate->update([`; app/Http/Controllers/Careers/CareerPortalController.php:363 `$application?->update([`; app/Http/Controllers/Careers/CareerPortalController.php:367 `$candidate->update([`; app/Http/Controllers/Careers/CareerPortalController.php:372 `$application?->update([`; responses app/Http/Controllers/Careers/CareerPortalController.php:92 `return Inertia::render('careers/index', [`; app/Http/Controllers/Careers/CareerPortalController.php:120 `return Inertia::render('careers/apply', [`; app/Http/Controllers/Careers/CareerPortalController.php:155 `return redirect()->back()->withErrors(['application' => 'This job is no longer accepting applications.']);`; app/Http/Controllers/Careers/CareerPortalController.php:227 `return redirect()->back()->withErrors(['application' => $exception->getMessage()]);`; app/Http/Controllers/Careers/CareerPortalController.php:230 `return redirect()->back()->withErrors(['application' => 'Application could not be submitted.']);`; app/Http/Controllers/Careers/CareerPortalController.php:235 `return redirect()`; app/Http/Controllers/Careers/CareerPortalController.php:270 `return Inertia::render('careers/offer-response', [`; app/Http/Controllers/Careers/CareerPortalController.php:278 `return Inertia::render('careers/offer-response', [`; app/Http/Controllers/Careers/CareerPortalController.php:311 `return redirect()->route('careers.offer.show', ['token' => $token])->with('error', 'Offer link is invalid.');`; app/Http/Controllers/Careers/CareerPortalController.php:315 `return redirect()->route('careers.offer.show', ['token' => $token])->with('error', 'Offer link has expired.');`; app/Http/Controllers/Careers/CareerPortalController.php:319 `return redirect()->route('careers.offer.show', ['token' => $token])->with('error', 'This offer has already been responded to.');`; app/Http/Controllers/Careers/CareerPortalController.php:336 `return redirect()->back()->withErrors(['signature_name' => 'Please enter your full name as a digital signature.']);`; app/Http/Controllers/Careers/CareerPortalController.php:340 `return redirect()->back()->withErrors(['terms_accepted' => 'You must accept the terms to sign this offer.']);`; app/Http/Controllers/Careers/CareerPortalController.php:403 `return redirect()`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Careers/CareerPortalController.php:382 `->notify(new OfferResponseAckNotification($offer, $candidate, $response));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD careers` — `careers.index` — `App\Http\Controllers\Careers\CareerPortalController@index` — `app/Http/Controllers/Careers/CareerPortalController.php:28` — middleware `web`
- `GET|HEAD careers/jobs/{job:slug}/apply` — `careers.apply` — `App\Http\Controllers\Careers\CareerPortalController@showApply` — `app/Http/Controllers/Careers/CareerPortalController.php:108` — middleware `web`
- `POST careers/jobs/{job:slug}/apply` — `careers.apply.store` — `App\Http\Controllers\Careers\CareerPortalController@submitApplication` — `app/Http/Controllers/Careers/CareerPortalController.php:148` — middleware `web`
- `GET|HEAD careers/offers/{token}` — `careers.offer.show` — `App\Http\Controllers\Careers\CareerPortalController@showOffer` — `app/Http/Controllers/Careers/CareerPortalController.php:261` — middleware `web, throttle:30,1`
- `POST careers/offers/{token}` — `careers.offer.respond` — `App\Http\Controllers\Careers\CareerPortalController@respondToOffer` — `app/Http/Controllers/Careers/CareerPortalController.php:302` — middleware `web, throttle:10,1`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Careers/CareerPortalController.php`.
- Exact render/action page relationships: `resources/js/pages/careers/apply.tsx`, `resources/js/pages/careers/index.tsx`, `resources/js/pages/careers/offer-response.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
