# CLI-SITE-CLIENT: Site Client

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:sites.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-SITE-CLIENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST sites/{site}/clients` (`sites.clients.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SiteClientController.php:22-66`; `first_name`.
3. Invoke only the owning control for `POST sites/{site}/clients/{client}/unlink` (`sites.clients.unlink`, action `unlink`). Source category: **mutation outcome source gap (unlink)**; controller `app/Http/Controllers/SiteClientController.php:109-126`; no exact validation fields extracted.
4. Invoke only the owning control for `POST sites/{site}/clients/link` (`sites.clients.link`, action `link`). Source category: **mutation outcome source gap (link)**; controller `app/Http/Controllers/SiteClientController.php:71-103`; `client_id`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2749` at `app/Http/Controllers/SiteClientController.php:22`; it is not runtime-observed.
- **mutation outcome source gap (unlink)** is applicable only to `unlink` / `ROUTE-2750` at `app/Http/Controllers/SiteClientController.php:109`; it is not runtime-observed.
- **mutation outcome source gap (link)** is applicable only to `link` / `ROUTE-2751` at `app/Http/Controllers/SiteClientController.php:71`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2749` / `store`: fields `first_name`; success app/Http/Controllers/SiteClientController.php:65 `return back()->with('success', 'Client created and linked to this site.');`.
- `ROUTE-2750` / `unlink`: success app/Http/Controllers/SiteClientController.php:125 `return back()->with('success', 'Client unlinked from this site.');`.
- `ROUTE-2751` / `link`: fields `client_id`; success app/Http/Controllers/SiteClientController.php:102 `return back()->with('success', 'Client linked to this site.');`; failure app/Http/Controllers/SiteClientController.php:84 `abort(403, 'Client belongs to another organisation.');`.

## Failure and recovery paths

- `link`: app/Http/Controllers/SiteClientController.php:84 `abort(403, 'Client belongs to another organisation.');`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SiteClientController.php:52 `$client = Client::create($payload);`; app/Http/Controllers/SiteClientController.php:115 `$client->save();`; app/Http/Controllers/SiteClientController.php:89 `$client->save();`; responses app/Http/Controllers/SiteClientController.php:55 `return $client;`; app/Http/Controllers/SiteClientController.php:65 `return back()->with('success', 'Client created and linked to this site.');`; app/Http/Controllers/SiteClientController.php:125 `return back()->with('success', 'Client unlinked from this site.');`; app/Http/Controllers/SiteClientController.php:102 `return back()->with('success', 'Client linked to this site.');`; audit calls app/Http/Controllers/SiteClientController.php:58 `AuditLogger::log('sites.clients.create', $client, ['site_id' => $site->id]);`; app/Http/Controllers/SiteClientController.php:117 `AuditLogger::log('sites.clients.unlink', $client, ['site_id' => $site->id]);`; app/Http/Controllers/SiteClientController.php:91 `AuditLogger::log('sites.clients.link', $client, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/clients` — `sites.clients.store` — `App\Http\Controllers\SiteClientController@store` — `app/Http/Controllers/SiteClientController.php:22` — middleware `web, auth, permission:sites.update`
- `POST sites/{site}/clients/{client}/unlink` — `sites.clients.unlink` — `App\Http\Controllers\SiteClientController@unlink` — `app/Http/Controllers/SiteClientController.php:109` — middleware `web, auth, permission:sites.update`
- `POST sites/{site}/clients/link` — `sites.clients.link` — `App\Http\Controllers\SiteClientController@link` — `app/Http/Controllers/SiteClientController.php:71` — middleware `web, auth, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SiteClientController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
