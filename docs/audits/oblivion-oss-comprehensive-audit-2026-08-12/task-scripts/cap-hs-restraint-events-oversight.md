# CAP-HS-RESTRAINT-EVENTS-OVERSIGHT: Restraint events evidence incidents and client oversight

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:restraints.view`, `permission:restraints.create|restraints.manage`, `permission:restraints.review|restraints.manage`, `permission:restraints.manage`
- Owning module: Health and safety
- Legacy family: `HS-RESTRAINT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/restraints` (`health-safety.restraints.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:restraints.view`, `permission:restraints.create|restraints.manage`, `permission:restraints.review|restraints.manage`, `permission:restraints.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:restraints.view`, `permission:restraints.create|restraints.manage`, `permission:restraints.review|restraints.manage`, `permission:restraints.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/restraints` (`health-safety.restraints.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/restraints/clients/{client}/summary` (`health-safety.restraints.clients.summary`, action `clientSummary`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/RestraintController.php:382-417`.
3. Use `GET|HEAD health-safety/restraints/events/{event}/attachments/{attachment}/download` (`health-safety.restraints.events.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/RestraintController.php:732-744`.
4. Use `GET|HEAD health-safety/restraints/export` (`health-safety.restraints.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/RestraintController.php:750-817`.
5. Invoke only the owning control for `POST health-safety/restraints/events` (`health-safety.restraints.events.store`, action `storeEvent`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:423-477`; `client_id`, `behaviour_support_plan_id`, `stay_id`, `site_id`, `started_at`, `ended_at`, `duration_minutes`, `restraint_type`, `severity`, `trigger_description`, `de_escalation_attempted`, `restraint_description`, `staff_involved`, `person_response`, `post_incident_support`, `injury_occurred`, `injury_details`, `within_support_plan`, `deviation_reason`, `authorised_by`, `related_incident_id`.
6. Invoke only the owning control for `PUT health-safety/restraints/events/{event}` (`health-safety.restraints.events.update`, action `updateEvent`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:479-512`; `reviewed_by`, `reviewed_at`, `review_notes`, `lessons_learned`, `severity`, `post_incident_support`.
7. Invoke only the owning control for `POST health-safety/restraints/events/{event}/attachments` (`health-safety.restraints.events.attachments.store`, action `storeAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:689-716`; `file`.
8. Invoke only the owning control for `DELETE health-safety/restraints/events/{event}/attachments/{attachment}` (`health-safety.restraints.events.attachments.destroy`, action `destroyAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:718-730`; no exact validation fields extracted.
9. Invoke only the owning control for `POST health-safety/restraints/events/{event}/link-incident` (`health-safety.restraints.events.link-incident`, action `linkIncident`). Source category: **mutation outcome source gap (linkIncident)**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:522-551`; `related_incident_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1199` at `app/Http/Controllers/HealthSafety/RestraintController.php:39`; it is not runtime-observed.
- **information presented** is applicable only to `clientSummary` / `ROUTE-1200` at `app/Http/Controllers/HealthSafety/RestraintController.php:382`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeEvent` / `ROUTE-1201` at `app/Http/Controllers/HealthSafety/RestraintController.php:423`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateEvent` / `ROUTE-1202` at `app/Http/Controllers/HealthSafety/RestraintController.php:479`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeAttachment` / `ROUTE-1203` at `app/Http/Controllers/HealthSafety/RestraintController.php:689`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAttachment` / `ROUTE-1204` at `app/Http/Controllers/HealthSafety/RestraintController.php:718`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1205` at `app/Http/Controllers/HealthSafety/RestraintController.php:732`; it is not runtime-observed.
- **mutation outcome source gap (linkIncident)** is applicable only to `linkIncident` / `ROUTE-1206` at `app/Http/Controllers/HealthSafety/RestraintController.php:522`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1207` at `app/Http/Controllers/HealthSafety/RestraintController.php:750`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/restraints/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1201` / `storeEvent`: fields `client_id`, `behaviour_support_plan_id`, `stay_id`, `site_id`, `started_at`, `ended_at`, `duration_minutes`, `restraint_type`, `severity`, `trigger_description`, `de_escalation_attempted`, `restraint_description`, `staff_involved`, `person_response`, `post_incident_support`, `injury_occurred`, `injury_details`, `within_support_plan`, `deviation_reason`, `authorised_by`, `related_incident_id`; success app/Http/Controllers/HealthSafety/RestraintController.php:471 `return back()->with('success', 'Restraint event recorded.');`; app/Http/Controllers/HealthSafety/RestraintController.php:476 `->with('success', 'Restraint event recorded.');`.
- `ROUTE-1202` / `updateEvent`: fields `reviewed_by`, `reviewed_at`, `review_notes`, `lessons_learned`, `severity`, `post_incident_support`; success app/Http/Controllers/HealthSafety/RestraintController.php:511 `return back()->with('success', 'Restraint event reviewed.');`.
- `ROUTE-1203` / `storeAttachment`: fields `file`; success app/Http/Controllers/HealthSafety/RestraintController.php:715 `return back()->with('success', 'Attachment uploaded.');`.
- `ROUTE-1204` / `destroyAttachment`: success app/Http/Controllers/HealthSafety/RestraintController.php:729 `return back()->with('success', 'Attachment removed.');`.
- `ROUTE-1206` / `linkIncident`: fields `related_incident_id`; failure app/Http/Controllers/HealthSafety/RestraintController.php:536 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `linkIncident`: app/Http/Controllers/HealthSafety/RestraintController.php:536 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/RestraintController.php:468 `$event = RestraintEvent::create($validated);`; app/Http/Controllers/HealthSafety/RestraintController.php:509 `$event->update($validated);`; app/Http/Controllers/HealthSafety/RestraintController.php:703 `$event->attachments()->create([`; app/Http/Controllers/HealthSafety/RestraintController.php:725 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/HealthSafety/RestraintController.php:727 `$attachment->delete();`; app/Http/Controllers/HealthSafety/RestraintController.php:542 `$event->update([`; responses app/Http/Controllers/HealthSafety/RestraintController.php:135 `return Inertia::render('health-safety/restraints/index', [`; app/Http/Controllers/HealthSafety/RestraintController.php:396 `return response()->json([`; app/Http/Controllers/HealthSafety/RestraintController.php:471 `return back()->with('success', 'Restraint event recorded.');`; app/Http/Controllers/HealthSafety/RestraintController.php:474 `return redirect()`; app/Http/Controllers/HealthSafety/RestraintController.php:511 `return back()->with('success', 'Restraint event reviewed.');`; app/Http/Controllers/HealthSafety/RestraintController.php:715 `return back()->with('success', 'Attachment uploaded.');`; app/Http/Controllers/HealthSafety/RestraintController.php:729 `return back()->with('success', 'Attachment removed.');`; app/Http/Controllers/HealthSafety/RestraintController.php:738 `return $this->streamPrivateAttachment(`; app/Http/Controllers/HealthSafety/RestraintController.php:547 `return back()->with(`; app/Http/Controllers/HealthSafety/RestraintController.php:763 `return response()->streamDownload(function () use ($lens, $from, $to, $clientId, $siteId, $type) {`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/restraints` — `health-safety.restraints.index` — `App\Http\Controllers\HealthSafety\RestraintController@index` — `app/Http/Controllers/HealthSafety/RestraintController.php:39` — middleware `web, auth, permission:restraints.view`
- `GET|HEAD health-safety/restraints/clients/{client}/summary` — `health-safety.restraints.clients.summary` — `App\Http\Controllers\HealthSafety\RestraintController@clientSummary` — `app/Http/Controllers/HealthSafety/RestraintController.php:382` — middleware `web, auth, permission:restraints.view`
- `POST health-safety/restraints/events` — `health-safety.restraints.events.store` — `App\Http\Controllers\HealthSafety\RestraintController@storeEvent` — `app/Http/Controllers/HealthSafety/RestraintController.php:423` — middleware `web, auth, permission:restraints.create|restraints.manage`
- `PUT health-safety/restraints/events/{event}` — `health-safety.restraints.events.update` — `App\Http\Controllers\HealthSafety\RestraintController@updateEvent` — `app/Http/Controllers/HealthSafety/RestraintController.php:479` — middleware `web, auth, permission:restraints.review|restraints.manage`
- `POST health-safety/restraints/events/{event}/attachments` — `health-safety.restraints.events.attachments.store` — `App\Http\Controllers\HealthSafety\RestraintController@storeAttachment` — `app/Http/Controllers/HealthSafety/RestraintController.php:689` — middleware `web, auth, permission:restraints.create|restraints.manage`
- `DELETE health-safety/restraints/events/{event}/attachments/{attachment}` — `health-safety.restraints.events.attachments.destroy` — `App\Http\Controllers\HealthSafety\RestraintController@destroyAttachment` — `app/Http/Controllers/HealthSafety/RestraintController.php:718` — middleware `web, auth, permission:restraints.manage`
- `GET|HEAD health-safety/restraints/events/{event}/attachments/{attachment}/download` — `health-safety.restraints.events.attachments.download` — `App\Http\Controllers\HealthSafety\RestraintController@downloadAttachment` — `app/Http/Controllers/HealthSafety/RestraintController.php:732` — middleware `web, auth, permission:restraints.view`
- `POST health-safety/restraints/events/{event}/link-incident` — `health-safety.restraints.events.link-incident` — `App\Http\Controllers\HealthSafety\RestraintController@linkIncident` — `app/Http/Controllers/HealthSafety/RestraintController.php:522` — middleware `web, auth, permission:restraints.review|restraints.manage`
- `GET|HEAD health-safety/restraints/export` — `health-safety.restraints.export` — `App\Http\Controllers\HealthSafety\RestraintController@export` — `app/Http/Controllers/HealthSafety/RestraintController.php:750` — middleware `web, auth, permission:restraints.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/RestraintController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/restraints/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
