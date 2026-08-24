# CAP-MED-EMAR-REVIEWS: Medication review actions and completion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/reviews` (`emar.reviews`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/reviews` (`emar.reviews`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/reviews` (`emar.reviews.store`, action `storeReview`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3078-3096`; `client_id`, `review_type`, `scheduled_date`, `reviewer_name`, `reviewer_role`, `reviewer_user_id`, `trigger_reason`.
3. Invoke only the owning control for `DELETE emar/reviews/{review}` (`emar.reviews.destroy`, action `destroyReview`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Emar/EmarController.php:3141-3146`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT emar/reviews/{review}` (`emar.reviews.update`, action `updateReview`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:3098-3112`; `review_type`, `scheduled_date`, `reviewer_name`, `reviewer_role`, `reviewer_user_id`, `trigger_reason`.
5. Invoke only the owning control for `POST emar/reviews/{review}/actions/advance` (`emar.reviews.actions.advance`, action `advanceReviewAction`). Source category: **mutation outcome source gap (advanceReviewAction)**; controller `app/Http/Controllers/Emar/EmarController.php:3153-3180`; `index`.
6. Invoke only the owning control for `POST emar/reviews/{review}/complete` (`emar.reviews.complete`, action `completeReview`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/EmarController.php:3114-3139`; `clinical_summary`, `medications_reviewed`, `drug_burden_index`, `falls_last_quarter`, `recommendations`, `actions`, `whanau_involved`, `whanau_notes`, `next_review_date`.

## Source-applicable states and transitions

- **information presented** is applicable only to `reviews` / `ROUTE-0411` at `app/Http/Controllers/Emar/EmarController.php:2346`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeReview` / `ROUTE-0412` at `app/Http/Controllers/Emar/EmarController.php:3078`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyReview` / `ROUTE-0413` at `app/Http/Controllers/Emar/EmarController.php:3141`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateReview` / `ROUTE-0414` at `app/Http/Controllers/Emar/EmarController.php:3098`; it is not runtime-observed.
- **mutation outcome source gap (advanceReviewAction)** is applicable only to `advanceReviewAction` / `ROUTE-0415` at `app/Http/Controllers/Emar/EmarController.php:3153`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeReview` / `ROUTE-0416` at `app/Http/Controllers/Emar/EmarController.php:3114`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/Reviews.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0412` / `storeReview`: fields `client_id`, `review_type`, `scheduled_date`, `reviewer_name`, `reviewer_role`, `reviewer_user_id`, `trigger_reason`.
- `ROUTE-0414` / `updateReview`: fields `review_type`, `scheduled_date`, `reviewer_name`, `reviewer_role`, `reviewer_user_id`, `trigger_reason`.
- `ROUTE-0415` / `advanceReviewAction`: fields `index`.
- `ROUTE-0416` / `completeReview`: fields `clinical_summary`, `medications_reviewed`, `drug_burden_index`, `falls_last_quarter`, `recommendations`, `actions`, `whanau_involved`, `whanau_notes`, `next_review_date`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:3093 `MedicationReview::create($validated);`; app/Http/Controllers/Emar/EmarController.php:3143 `$review->update(['status' => 'cancelled']);`; app/Http/Controllers/Emar/EmarController.php:3109 `$review->update($validated);`; app/Http/Controllers/Emar/EmarController.php:3177 `$review->update(['actions' => $actions]);`; app/Http/Controllers/Emar/EmarController.php:3133 `$review->update($validated);`; app/Http/Controllers/Emar/EmarController.php:3136 `])->save();`; responses app/Http/Controllers/Emar/EmarController.php:2368 `return collect($r->actions ?? [])`; app/Http/Controllers/Emar/EmarController.php:2391 `return Inertia::render('emar/Reviews', [`; app/Http/Controllers/Emar/EmarController.php:3095 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3145 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3111 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3169 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3179 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3138 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/reviews` — `emar.reviews` — `App\Http\Controllers\Emar\EmarController@reviews` — `app/Http/Controllers/Emar/EmarController.php:2346` — middleware `web, auth, permission:medications.view`
- `POST emar/reviews` — `emar.reviews.store` — `App\Http\Controllers\Emar\EmarController@storeReview` — `app/Http/Controllers/Emar/EmarController.php:3078` — middleware `web, auth, permission:medications.orders.manage`
- `DELETE emar/reviews/{review}` — `emar.reviews.destroy` — `App\Http\Controllers\Emar\EmarController@destroyReview` — `app/Http/Controllers/Emar/EmarController.php:3141` — middleware `web, auth, permission:medications.orders.manage`
- `PUT emar/reviews/{review}` — `emar.reviews.update` — `App\Http\Controllers\Emar\EmarController@updateReview` — `app/Http/Controllers/Emar/EmarController.php:3098` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/reviews/{review}/actions/advance` — `emar.reviews.actions.advance` — `App\Http\Controllers\Emar\EmarController@advanceReviewAction` — `app/Http/Controllers/Emar/EmarController.php:3153` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/reviews/{review}/complete` — `emar.reviews.complete` — `App\Http\Controllers\Emar\EmarController@completeReview` — `app/Http/Controllers/Emar/EmarController.php:3114` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/Reviews.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
