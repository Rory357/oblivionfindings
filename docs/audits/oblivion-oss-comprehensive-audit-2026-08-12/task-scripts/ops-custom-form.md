# OPS-CUSTOM-FORM: Custom Form

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:custom_forms.viewAny`, `permission:custom_forms.create`, `permission:custom_forms.update`, `permission:custom_forms.submit`
- Owning module: Operations and rostering
- Legacy family: `OPS-CUSTOM-FORM`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/forms` (`operations.forms.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:custom_forms.viewAny`, `permission:custom_forms.create`, `permission:custom_forms.update`, `permission:custom_forms.submit`.
- Exact middleware atoms: `web`, `auth`, `permission:custom_forms.viewAny`, `permission:custom_forms.create`, `permission:custom_forms.update`, `permission:custom_forms.submit`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/forms` (`operations.forms.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/forms/{form}` (`operations.forms.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CustomFormController.php:76-88`.
3. Use `GET|HEAD operations/forms/{form}/edit` (`operations.forms.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CustomFormController.php:129-141`.
4. Use `GET|HEAD operations/forms/{form}/submissions` (`operations.forms.submissions`, action `submissions`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CustomFormController.php:170-190`.
5. Use `GET|HEAD operations/forms/create` (`operations.forms.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/CustomFormController.php:90-96`.
6. Invoke only the owning control for `POST operations/forms` (`operations.forms.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CustomFormController.php:98-127`; `name`.
7. Invoke only the owning control for `PUT operations/forms/{form}` (`operations.forms.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/CustomFormController.php:143-168`; `name`.
8. Invoke only the owning control for `POST operations/forms/{form}/submit` (`operations.forms.submit`, action `submitForm`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CustomFormController.php:192-235`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2067` at `app/Http/Controllers/Operations/CustomFormController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2068` at `app/Http/Controllers/Operations/CustomFormController.php:98`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2069` at `app/Http/Controllers/Operations/CustomFormController.php:76`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2070` at `app/Http/Controllers/Operations/CustomFormController.php:143`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2071` at `app/Http/Controllers/Operations/CustomFormController.php:129`; it is not runtime-observed.
- **information presented** is applicable only to `submissions` / `ROUTE-2072` at `app/Http/Controllers/Operations/CustomFormController.php:170`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitForm` / `ROUTE-2073` at `app/Http/Controllers/Operations/CustomFormController.php:192`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2074` at `app/Http/Controllers/Operations/CustomFormController.php:90`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/forms/Create.tsx`, `resources/js/pages/operations/forms/Edit.tsx`, `resources/js/pages/operations/forms/Index.tsx`, `resources/js/pages/operations/forms/Show.tsx`, `resources/js/pages/operations/forms/Submissions.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2068` / `store`: fields `name`; success app/Http/Controllers/Operations/CustomFormController.php:126 `return redirect()->back()->with('success', 'Form created.');`.
- `ROUTE-2070` / `update`: fields `name`; success app/Http/Controllers/Operations/CustomFormController.php:167 `return redirect()->back()->with('success', 'Form updated.');`.
- `ROUTE-2073` / `submitForm`: success app/Http/Controllers/Operations/CustomFormController.php:234 `return redirect()->back()->with('success', 'Form submitted.');`; failure app/Http/Controllers/Operations/CustomFormController.php:217 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `submitForm`: app/Http/Controllers/Operations/CustomFormController.php:217 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/CustomFormController.php:116 `CustomForm::create([`; app/Http/Controllers/Operations/CustomFormController.php:165 `$form->update($data);`; app/Http/Controllers/Operations/CustomFormController.php:224 `CustomFormSubmission::create([`; responses app/Http/Controllers/Operations/CustomFormController.php:66 `return inertia('operations/forms/Index', [`; app/Http/Controllers/Operations/CustomFormController.php:126 `return redirect()->back()->with('success', 'Form created.');`; app/Http/Controllers/Operations/CustomFormController.php:85 `return inertia('operations/forms/Show', [`; app/Http/Controllers/Operations/CustomFormController.php:167 `return redirect()->back()->with('success', 'Form updated.');`; app/Http/Controllers/Operations/CustomFormController.php:138 `return inertia('operations/forms/Edit', [`; app/Http/Controllers/Operations/CustomFormController.php:186 `return inertia('operations/forms/Submissions', [`; app/Http/Controllers/Operations/CustomFormController.php:234 `return redirect()->back()->with('success', 'Form submitted.');`; app/Http/Controllers/Operations/CustomFormController.php:95 `return inertia('operations/forms/Create');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/forms` — `operations.forms.index` — `App\Http\Controllers\Operations\CustomFormController@index` — `app/Http/Controllers/Operations/CustomFormController.php:15` — middleware `web, auth, permission:custom_forms.viewAny`
- `POST operations/forms` — `operations.forms.store` — `App\Http\Controllers\Operations\CustomFormController@store` — `app/Http/Controllers/Operations/CustomFormController.php:98` — middleware `web, auth, permission:custom_forms.create`
- `GET|HEAD operations/forms/{form}` — `operations.forms.show` — `App\Http\Controllers\Operations\CustomFormController@show` — `app/Http/Controllers/Operations/CustomFormController.php:76` — middleware `web, auth, permission:custom_forms.viewAny`
- `PUT operations/forms/{form}` — `operations.forms.update` — `App\Http\Controllers\Operations\CustomFormController@update` — `app/Http/Controllers/Operations/CustomFormController.php:143` — middleware `web, auth, permission:custom_forms.update`
- `GET|HEAD operations/forms/{form}/edit` — `operations.forms.edit` — `App\Http\Controllers\Operations\CustomFormController@edit` — `app/Http/Controllers/Operations/CustomFormController.php:129` — middleware `web, auth, permission:custom_forms.update`
- `GET|HEAD operations/forms/{form}/submissions` — `operations.forms.submissions` — `App\Http\Controllers\Operations\CustomFormController@submissions` — `app/Http/Controllers/Operations/CustomFormController.php:170` — middleware `web, auth, permission:custom_forms.viewAny`
- `POST operations/forms/{form}/submit` — `operations.forms.submit` — `App\Http\Controllers\Operations\CustomFormController@submitForm` — `app/Http/Controllers/Operations/CustomFormController.php:192` — middleware `web, auth, permission:custom_forms.submit`
- `GET|HEAD operations/forms/create` — `operations.forms.create` — `App\Http\Controllers\Operations\CustomFormController@create` — `app/Http/Controllers/Operations/CustomFormController.php:90` — middleware `web, auth, permission:custom_forms.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/CustomFormController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/forms/Create.tsx`, `resources/js/pages/operations/forms/Edit.tsx`, `resources/js/pages/operations/forms/Index.tsx`, `resources/js/pages/operations/forms/Show.tsx`, `resources/js/pages/operations/forms/Submissions.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
