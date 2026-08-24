# CLI-CLIENT-MEAL-PREFERENCES: Client Meal Preferences

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:sites.meals.view|clients.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-MEAL-PREFERENCES`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/meal-preferences` (`clients.meal-preferences.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:sites.meals.view|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:sites.meals.view|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/meal-preferences` (`clients.meal-preferences.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST clients/{client}/meal-preferences/dislikes` (`clients.meal-preferences.dislikes.store`, action `storeDislike`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientMealPreferencesController.php:53-75`; `product_id`, `free_text_name`, `notes`.
3. Invoke only the owning control for `DELETE clients/{client}/meal-preferences/dislikes/{dislike}` (`clients.meal-preferences.dislikes.destroy`, action `destroyDislike`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientMealPreferencesController.php:77-84`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT clients/{client}/meal-preferences/tags` (`clients.meal-preferences.tags.sync`, action `syncTags`). Source category: **retried/replayed/reconciled**; controller `app/Http/Controllers/ClientMealPreferencesController.php:41-51`; `tag_ids`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-0163` at `app/Http/Controllers/ClientMealPreferencesController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeDislike` / `ROUTE-0164` at `app/Http/Controllers/ClientMealPreferencesController.php:53`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyDislike` / `ROUTE-0165` at `app/Http/Controllers/ClientMealPreferencesController.php:77`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `syncTags` / `ROUTE-0166` at `app/Http/Controllers/ClientMealPreferencesController.php:41`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0164` / `storeDislike`: fields `product_id`, `free_text_name`, `notes`; failure app/Http/Controllers/ClientMealPreferencesController.php:63 `return back()->withErrors(['free_text_name' => 'Pick a product or type a name.']);`.
- `ROUTE-0166` / `syncTags`: fields `tag_ids`.

## Failure and recovery paths

- `storeDislike`: app/Http/Controllers/ClientMealPreferencesController.php:63 `return back()->withErrors(['free_text_name' => 'Pick a product or type a name.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientMealPreferencesController.php:66 `ClientMealDislike::create([`; app/Http/Controllers/ClientMealPreferencesController.php:81 `$dislike->delete();`; app/Http/Controllers/ClientMealPreferencesController.php:48 `$client->mealDietaryTags()->sync($data['tag_ids'] ?? []);`; responses app/Http/Controllers/ClientMealPreferencesController.php:22 `return response()->json([`; app/Http/Controllers/ClientMealPreferencesController.php:63 `return back()->withErrors(['free_text_name' => 'Pick a product or type a name.']);`; app/Http/Controllers/ClientMealPreferencesController.php:74 `return back()->with('status', 'Dislike added');`; app/Http/Controllers/ClientMealPreferencesController.php:83 `return back()->with('status', 'Dislike removed');`; app/Http/Controllers/ClientMealPreferencesController.php:50 `return back()->with('status', 'Dietary tags updated');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/meal-preferences` — `clients.meal-preferences.show` — `App\Http\Controllers\ClientMealPreferencesController@show` — `app/Http/Controllers/ClientMealPreferencesController.php:17` — middleware `web, auth, permission:sites.meals.view|clients.update`
- `POST clients/{client}/meal-preferences/dislikes` — `clients.meal-preferences.dislikes.store` — `App\Http\Controllers\ClientMealPreferencesController@storeDislike` — `app/Http/Controllers/ClientMealPreferencesController.php:53` — middleware `web, auth, permission:sites.meals.view|clients.update`
- `DELETE clients/{client}/meal-preferences/dislikes/{dislike}` — `clients.meal-preferences.dislikes.destroy` — `App\Http\Controllers\ClientMealPreferencesController@destroyDislike` — `app/Http/Controllers/ClientMealPreferencesController.php:77` — middleware `web, auth, permission:sites.meals.view|clients.update`
- `PUT clients/{client}/meal-preferences/tags` — `clients.meal-preferences.tags.sync` — `App\Http\Controllers\ClientMealPreferencesController@syncTags` — `app/Http/Controllers/ClientMealPreferencesController.php:41` — middleware `web, auth, permission:sites.meals.view|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientMealPreferencesController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
