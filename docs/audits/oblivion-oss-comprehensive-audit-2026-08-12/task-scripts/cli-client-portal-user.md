# CLI-CLIENT-PORTAL-USER: Client Portal User

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-PORTAL-USER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/portal-users` (`clients.portal_users.edit`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/portal-users` (`clients.portal_users.edit`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/clients/{client}/portal-users` (`operations.clients.portal_users.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientPortalUserController.php:14-30`.
3. Invoke only the owning control for `POST clients/{client}/portal-users` (`clients.portal_users.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientPortalUserController.php:32-100`; `email`.
4. Invoke only the owning control for `DELETE clients/{client}/portal-users/{user}` (`clients.portal_users.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientPortalUserController.php:102-109`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/clients/{client}/portal-users` (`operations.clients.portal_users.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientPortalUserController.php:32-100`; `email`.
6. Invoke only the owning control for `DELETE operations/clients/{client}/portal-users/{user}` (`operations.clients.portal_users.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientPortalUserController.php:102-109`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `edit` / `ROUTE-0186` at `app/Http/Controllers/ClientPortalUserController.php:14`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0187` at `app/Http/Controllers/ClientPortalUserController.php:32`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0188` at `app/Http/Controllers/ClientPortalUserController.php:102`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2036` at `app/Http/Controllers/ClientPortalUserController.php:14`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2037` at `app/Http/Controllers/ClientPortalUserController.php:32`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2038` at `app/Http/Controllers/ClientPortalUserController.php:102`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/clients/portal-users.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0187` / `store`: fields `email`; failure app/Http/Controllers/ClientPortalUserController.php:50 `return back()->withErrors(['name' => 'Name is required to create a user.']);`; app/Http/Controllers/ClientPortalUserController.php:63 `return back()->withErrors(['portal_role' => 'Contact-only mode is only available for next of kin.']);`; app/Http/Controllers/ClientPortalUserController.php:66 `return back()->withErrors(['name' => 'Name is required to save a contact.']);`; app/Http/Controllers/ClientPortalUserController.php:79 `return back()->withErrors(['email' => 'No user found with this email.']);`.
- `ROUTE-2037` / `store`: fields `email`; failure app/Http/Controllers/ClientPortalUserController.php:50 `return back()->withErrors(['name' => 'Name is required to create a user.']);`; app/Http/Controllers/ClientPortalUserController.php:63 `return back()->withErrors(['portal_role' => 'Contact-only mode is only available for next of kin.']);`; app/Http/Controllers/ClientPortalUserController.php:66 `return back()->withErrors(['name' => 'Name is required to save a contact.']);`; app/Http/Controllers/ClientPortalUserController.php:79 `return back()->withErrors(['email' => 'No user found with this email.']);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/ClientPortalUserController.php:50 `return back()->withErrors(['name' => 'Name is required to create a user.']);`; app/Http/Controllers/ClientPortalUserController.php:63 `return back()->withErrors(['portal_role' => 'Contact-only mode is only available for next of kin.']);`; app/Http/Controllers/ClientPortalUserController.php:66 `return back()->withErrors(['name' => 'Name is required to save a contact.']);`; app/Http/Controllers/ClientPortalUserController.php:79 `return back()->withErrors(['email' => 'No user found with this email.']);`.
- `store`: app/Http/Controllers/ClientPortalUserController.php:50 `return back()->withErrors(['name' => 'Name is required to create a user.']);`; app/Http/Controllers/ClientPortalUserController.php:63 `return back()->withErrors(['portal_role' => 'Contact-only mode is only available for next of kin.']);`; app/Http/Controllers/ClientPortalUserController.php:66 `return back()->withErrors(['name' => 'Name is required to save a contact.']);`; app/Http/Controllers/ClientPortalUserController.php:79 `return back()->withErrors(['email' => 'No user found with this email.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientPortalUserController.php:53 `$user = User::create([`; app/Http/Controllers/ClientPortalUserController.php:106 `$client->portalUsers()->detach($user->id);`; responses app/Http/Controllers/ClientPortalUserController.php:20 `return inertia('operations/clients/portal-users', [`; app/Http/Controllers/ClientPortalUserController.php:50 `return back()->withErrors(['name' => 'Name is required to create a user.']);`; app/Http/Controllers/ClientPortalUserController.php:63 `return back()->withErrors(['portal_role' => 'Contact-only mode is only available for next of kin.']);`; app/Http/Controllers/ClientPortalUserController.php:66 `return back()->withErrors(['name' => 'Name is required to save a contact.']);`; app/Http/Controllers/ClientPortalUserController.php:77 `return back()->with('status', 'Next-of-kin saved for display/contact purposes.');`; app/Http/Controllers/ClientPortalUserController.php:79 `return back()->withErrors(['email' => 'No user found with this email.']);`; app/Http/Controllers/ClientPortalUserController.php:99 `return back()->with('status', 'Portal user linked.');`; app/Http/Controllers/ClientPortalUserController.php:108 `return back()->with('status', 'Portal user unlinked.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/portal-users` — `clients.portal_users.edit` — `App\Http\Controllers\ClientPortalUserController@edit` — `app/Http/Controllers/ClientPortalUserController.php:14` — middleware `web, auth, permission:clients.update`
- `POST clients/{client}/portal-users` — `clients.portal_users.store` — `App\Http\Controllers\ClientPortalUserController@store` — `app/Http/Controllers/ClientPortalUserController.php:32` — middleware `web, auth, permission:clients.update`
- `DELETE clients/{client}/portal-users/{user}` — `clients.portal_users.destroy` — `App\Http\Controllers\ClientPortalUserController@destroy` — `app/Http/Controllers/ClientPortalUserController.php:102` — middleware `web, auth, permission:clients.update`
- `GET|HEAD operations/clients/{client}/portal-users` — `operations.clients.portal_users.edit` — `App\Http\Controllers\ClientPortalUserController@edit` — `app/Http/Controllers/ClientPortalUserController.php:14` — middleware `web, auth, permission:clients.update`
- `POST operations/clients/{client}/portal-users` — `operations.clients.portal_users.store` — `App\Http\Controllers\ClientPortalUserController@store` — `app/Http/Controllers/ClientPortalUserController.php:32` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/portal-users/{user}` — `operations.clients.portal_users.destroy` — `App\Http\Controllers\ClientPortalUserController@destroy` — `app/Http/Controllers/ClientPortalUserController.php:102` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientPortalUserController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/clients/portal-users.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
