# HR-POLICY: Policy

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.policies.view`, `permission:hr.policies.manage`, `permission:hr.policies.attest`
- Owning module: Human resources
- Legacy family: `HR-POLICY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/documents/policies` (`hr.policies.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.policies.view`, `permission:hr.policies.manage`, `permission:hr.policies.attest`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.policies.view`, `permission:hr.policies.manage`, `permission:hr.policies.attest`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/documents/policies` (`hr.policies.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/documents/policies/{policy}` (`hr.policies.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PolicyController.php:122-149`.
3. Use `GET|HEAD hr/documents/policies/{policy}/download` (`hr.policies.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/PolicyController.php:376-396`.
4. Use `GET|HEAD hr/documents/policies/{policy}/edit` (`hr.policies.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PolicyController.php:109-117`.
5. Use `GET|HEAD hr/documents/policies/create` (`hr.policies.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PolicyController.php:97-103`.
6. Invoke only the owning control for `POST hr/documents/policies` (`hr.policies.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PolicyController.php:154-227`; `title`.
7. Invoke only the owning control for `DELETE hr/documents/policies/{policy}` (`hr.policies.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/PolicyController.php:354-371`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT hr/documents/policies/{policy}` (`hr.policies.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PolicyController.php:232-256`; `title`.
9. Invoke only the owning control for `POST hr/documents/policies/{policy}/versions` (`hr.policies.versions.store`, action `storeVersion`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PolicyController.php:261-308`; `content_summary`.
10. Invoke only the owning control for `DELETE hr/documents/policies/{policy}/versions/{version}` (`hr.policies.versions.destroy`, action `destroyVersion`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/PolicyController.php:401-422`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1407` at `app/Http/Controllers/Hr/PolicyController.php:38`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1408` at `app/Http/Controllers/Hr/PolicyController.php:154`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1409` at `app/Http/Controllers/Hr/PolicyController.php:354`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1410` at `app/Http/Controllers/Hr/PolicyController.php:122`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1411` at `app/Http/Controllers/Hr/PolicyController.php:232`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-1413` at `app/Http/Controllers/Hr/PolicyController.php:376`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1414` at `app/Http/Controllers/Hr/PolicyController.php:109`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeVersion` / `ROUTE-1415` at `app/Http/Controllers/Hr/PolicyController.php:261`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyVersion` / `ROUTE-1416` at `app/Http/Controllers/Hr/PolicyController.php:401`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1418` at `app/Http/Controllers/Hr/PolicyController.php:97`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/documents/policies/index.tsx`, `resources/js/pages/hr/documents/policies/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1408` / `store`: fields `title`; success app/Http/Controllers/Hr/PolicyController.php:226 `return redirect()->route('hr.policies.index')->with('success', 'Policy created successfully.');`; failure app/Http/Controllers/Hr/PolicyController.php:187 `return redirect()->back()->withErrors(['document' => 'The file failed to upload. Error: ' . $file->getErrorMessage()]);`; app/Http/Controllers/Hr/PolicyController.php:195 `return redirect()->back()->withErrors(['document' => 'Failed to save the file: ' . $e->getMessage()]);`.
- `ROUTE-1409` / `destroy`: success app/Http/Controllers/Hr/PolicyController.php:370 `return redirect()->route('hr.policies.index')->with('success', 'Policy deleted successfully.');`.
- `ROUTE-1411` / `update`: fields `title`; success app/Http/Controllers/Hr/PolicyController.php:255 `return redirect()->back()->with('success', 'Policy updated.');`.
- `ROUTE-1413` / `download`: failure app/Http/Controllers/Hr/PolicyController.php:389 `abort(404, 'Document not found.');`.
- `ROUTE-1415` / `storeVersion`: fields `content_summary`; success app/Http/Controllers/Hr/PolicyController.php:307 `return redirect()->back()->with('success', 'New policy version published.');`; failure app/Http/Controllers/Hr/PolicyController.php:280 `return redirect()->back()->withErrors(['document' => 'The file failed to upload.']);`.
- `ROUTE-1416` / `destroyVersion`: success app/Http/Controllers/Hr/PolicyController.php:421 `return redirect()->back()->with('success', 'Version deleted successfully.');`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Hr/PolicyController.php:187 `return redirect()->back()->withErrors(['document' => 'The file failed to upload. Error: ' . $file->getErrorMessage()]);`; app/Http/Controllers/Hr/PolicyController.php:195 `return redirect()->back()->withErrors(['document' => 'Failed to save the file: ' . $e->getMessage()]);`.
- `download`: app/Http/Controllers/Hr/PolicyController.php:389 `abort(404, 'Document not found.');`.
- `storeVersion`: app/Http/Controllers/Hr/PolicyController.php:280 `return redirect()->back()->withErrors(['document' => 'The file failed to upload.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PolicyController.php:199 `$policy = HrPolicy::create([`; app/Http/Controllers/Hr/PolicyController.php:212 `$version = HrPolicyVersion::create([`; app/Http/Controllers/Hr/PolicyController.php:364 `Storage::disk('private')->delete($version->document_path);`; app/Http/Controllers/Hr/PolicyController.php:368 `$policy->delete();`; app/Http/Controllers/Hr/PolicyController.php:253 `$policy->update($data);`; app/Http/Controllers/Hr/PolicyController.php:291 `$policy->versions()->update(['is_current' => false]);`; app/Http/Controllers/Hr/PolicyController.php:293 `$version = HrPolicyVersion::create([`; app/Http/Controllers/Hr/PolicyController.php:416 `Storage::disk('private')->delete($version->document_path);`; app/Http/Controllers/Hr/PolicyController.php:419 `$version->delete();`; responses app/Http/Controllers/Hr/PolicyController.php:77 `return Inertia::render('hr/documents/policies/index', [`; app/Http/Controllers/Hr/PolicyController.php:187 `return redirect()->back()->withErrors(['document' => 'The file failed to upload. Error: ' . $file->getErrorMessage()]);`; app/Http/Controllers/Hr/PolicyController.php:195 `return redirect()->back()->withErrors(['document' => 'Failed to save the file: ' . $e->getMessage()]);`; app/Http/Controllers/Hr/PolicyController.php:226 `return redirect()->route('hr.policies.index')->with('success', 'Policy created successfully.');`; app/Http/Controllers/Hr/PolicyController.php:370 `return redirect()->route('hr.policies.index')->with('success', 'Policy deleted successfully.');`; app/Http/Controllers/Hr/PolicyController.php:141 `return Inertia::render('hr/documents/policies/show', [`; app/Http/Controllers/Hr/PolicyController.php:255 `return redirect()->back()->with('success', 'Policy updated.');`; app/Http/Controllers/Hr/PolicyController.php:392 `return Storage::disk('private')->response($path, basename($path), [`; app/Http/Controllers/Hr/PolicyController.php:116 `return redirect()->route('hr.policies.index', ['edit' => $policy->id]);`; app/Http/Controllers/Hr/PolicyController.php:280 `return redirect()->back()->withErrors(['document' => 'The file failed to upload.']);`; app/Http/Controllers/Hr/PolicyController.php:307 `return redirect()->back()->with('success', 'New policy version published.');`; app/Http/Controllers/Hr/PolicyController.php:411 `return redirect()->back()->with('error', 'Cannot delete the current version. Set another version as current first.');`; app/Http/Controllers/Hr/PolicyController.php:421 `return redirect()->back()->with('success', 'Version deleted successfully.');`; app/Http/Controllers/Hr/PolicyController.php:102 `return redirect()->route('hr.policies.index', ['new' => 1]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/documents/policies` — `hr.policies.index` — `App\Http\Controllers\Hr\PolicyController@index` — `app/Http/Controllers/Hr/PolicyController.php:38` — middleware `web, auth, permission:hr.policies.view`
- `POST hr/documents/policies` — `hr.policies.store` — `App\Http\Controllers\Hr\PolicyController@store` — `app/Http/Controllers/Hr/PolicyController.php:154` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.manage`
- `DELETE hr/documents/policies/{policy}` — `hr.policies.destroy` — `App\Http\Controllers\Hr\PolicyController@destroy` — `app/Http/Controllers/Hr/PolicyController.php:354` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.manage`
- `GET|HEAD hr/documents/policies/{policy}` — `hr.policies.show` — `App\Http\Controllers\Hr\PolicyController@show` — `app/Http/Controllers/Hr/PolicyController.php:122` — middleware `web, auth, permission:hr.policies.view`
- `PUT hr/documents/policies/{policy}` — `hr.policies.update` — `App\Http\Controllers\Hr\PolicyController@update` — `app/Http/Controllers/Hr/PolicyController.php:232` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.manage`
- `GET|HEAD hr/documents/policies/{policy}/download` — `hr.policies.download` — `App\Http\Controllers\Hr\PolicyController@download` — `app/Http/Controllers/Hr/PolicyController.php:376` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.attest`
- `GET|HEAD hr/documents/policies/{policy}/edit` — `hr.policies.edit` — `App\Http\Controllers\Hr\PolicyController@edit` — `app/Http/Controllers/Hr/PolicyController.php:109` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.manage`
- `POST hr/documents/policies/{policy}/versions` — `hr.policies.versions.store` — `App\Http\Controllers\Hr\PolicyController@storeVersion` — `app/Http/Controllers/Hr/PolicyController.php:261` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.manage`
- `DELETE hr/documents/policies/{policy}/versions/{version}` — `hr.policies.versions.destroy` — `App\Http\Controllers\Hr\PolicyController@destroyVersion` — `app/Http/Controllers/Hr/PolicyController.php:401` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.manage`
- `GET|HEAD hr/documents/policies/create` — `hr.policies.create` — `App\Http\Controllers\Hr\PolicyController@create` — `app/Http/Controllers/Hr/PolicyController.php:97` — middleware `web, auth, permission:hr.policies.view, permission:hr.policies.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PolicyController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/documents/policies/index.tsx`, `resources/js/pages/hr/documents/policies/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
