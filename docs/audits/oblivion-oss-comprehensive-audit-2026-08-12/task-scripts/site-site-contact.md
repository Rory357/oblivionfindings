# SITE-SITE-CONTACT: Site Contact

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-CONTACT`
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
2. Invoke only the owning control for `POST sites/{site}/contacts` (`sites.contacts.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SiteContactController.php:13-47`; `type`.
3. Invoke only the owning control for `DELETE sites/{site}/contacts/{contact}` (`sites.contacts.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/SiteContactController.php:85-102`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/contacts/{contact}` (`sites.contacts.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/SiteContactController.php:49-83`; `type`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2757` at `app/Http/Controllers/SiteContactController.php:13`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2758` at `app/Http/Controllers/SiteContactController.php:85`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2759` at `app/Http/Controllers/SiteContactController.php:49`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2757` / `store`: fields `type`; success app/Http/Controllers/SiteContactController.php:46 `return back()->with('success', 'Contact added.');`.
- `ROUTE-2758` / `destroy`: success app/Http/Controllers/SiteContactController.php:101 `return back()->with('success', 'Contact removed.');`.
- `ROUTE-2759` / `update`: fields `type`; success app/Http/Controllers/SiteContactController.php:82 `return back()->with('success', 'Contact updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SiteContactController.php:29 `SiteContact::query()->where('site_id', $site->id)->update(['is_primary' => false]);`; app/Http/Controllers/SiteContactController.php:32 `$contact = SiteContact::create(array_merge($data, [`; app/Http/Controllers/SiteContactController.php:91 `$contact->delete();`; app/Http/Controllers/SiteContactController.php:69 `->update(['is_primary' => false]);`; app/Http/Controllers/SiteContactController.php:72 `$contact->update(array_merge($data, ['is_primary' => $isPrimary]));`; responses app/Http/Controllers/SiteContactController.php:46 `return back()->with('success', 'Contact added.');`; app/Http/Controllers/SiteContactController.php:101 `return back()->with('success', 'Contact removed.');`; app/Http/Controllers/SiteContactController.php:82 `return back()->with('success', 'Contact updated.');`; audit calls app/Http/Controllers/SiteContactController.php:38 `AuditLogger::log('sites.contacts.create', $contact, ['site_id' => $site->id]);`; app/Http/Controllers/SiteContactController.php:93 `AuditLogger::log('sites.contacts.delete', $site, ['site_id' => $site->id, 'name' => $name]);`; app/Http/Controllers/SiteContactController.php:74 `AuditLogger::log('sites.contacts.update', $contact, ['site_id' => $site->id]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/contacts` — `sites.contacts.store` — `App\Http\Controllers\SiteContactController@store` — `app/Http/Controllers/SiteContactController.php:13` — middleware `web, auth, permission:sites.update`
- `DELETE sites/{site}/contacts/{contact}` — `sites.contacts.destroy` — `App\Http\Controllers\SiteContactController@destroy` — `app/Http/Controllers/SiteContactController.php:85` — middleware `web, auth, permission:sites.update`
- `PUT sites/{site}/contacts/{contact}` — `sites.contacts.update` — `App\Http\Controllers\SiteContactController@update` — `app/Http/Controllers/SiteContactController.php:49` — middleware `web, auth, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SiteContactController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
