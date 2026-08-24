# CLI-PORTAL-MESSAGE: Portal Message

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-PORTAL-MESSAGE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/clients/{client}/messages` (`portal.clients.messages`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/clients/{client}/messages` (`portal.clients.messages`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD portal/clients/{client}/messages-search` (`portal.clients.messages.search`, action `searchMessages`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Portal/PortalMessageController.php:330-368`.
3. Use `GET|HEAD portal/clients/{client}/messages/{conversation}` (`portal.clients.messages.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Portal/PortalMessageController.php:100-273`.
4. Invoke only the owning control for `POST portal/clients/{client}/messages/{conversation}` (`portal.clients.messages.send`, action `storeMessage`). Source category: **created/recorded**; controller `app/Http/Controllers/Portal/PortalMessageController.php:370-504`; `content`.
5. Invoke only the owning control for `DELETE portal/clients/{client}/messages/archive/{message}` (`portal.clients.messages.archive`, action `archiveMessage`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Portal/PortalMessageController.php:316-328`; no exact validation fields extracted.
6. Invoke only the owning control for `POST portal/clients/{client}/messages/pin/{message}` (`portal.clients.messages.pin`, action `togglePin`). Source category: **updated/revised**; controller `app/Http/Controllers/Portal/PortalMessageController.php:304-314`; no exact validation fields extracted.
7. Invoke only the owning control for `POST portal/clients/{client}/messages/react/{message}` (`portal.clients.messages.react`, action `toggleReaction`). Source category: **updated/revised**; controller `app/Http/Controllers/Portal/PortalMessageController.php:275-302`; `emoji`.
8. Invoke only the owning control for `POST portal/clients/{client}/messages/start` (`portal.clients.messages.start`, action `startConversation`). Source category: **created/recorded**; controller `app/Http/Controllers/Portal/PortalMessageController.php:506-602`; `title`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2263` at `app/Http/Controllers/Portal/PortalMessageController.php:31`; it is not runtime-observed.
- **information presented** is applicable only to `searchMessages` / `ROUTE-2264` at `app/Http/Controllers/Portal/PortalMessageController.php:330`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2265` at `app/Http/Controllers/Portal/PortalMessageController.php:100`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeMessage` / `ROUTE-2266` at `app/Http/Controllers/Portal/PortalMessageController.php:370`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `archiveMessage` / `ROUTE-2267` at `app/Http/Controllers/Portal/PortalMessageController.php:316`; it is not runtime-observed.
- **updated/revised** is applicable only to `togglePin` / `ROUTE-2268` at `app/Http/Controllers/Portal/PortalMessageController.php:304`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleReaction` / `ROUTE-2269` at `app/Http/Controllers/Portal/PortalMessageController.php:275`; it is not runtime-observed.
- **created/recorded** is applicable only to `startConversation` / `ROUTE-2270` at `app/Http/Controllers/Portal/PortalMessageController.php:506`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/messages.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2266` / `storeMessage`: fields `content`.
- `ROUTE-2269` / `toggleReaction`: fields `emoji`.
- `ROUTE-2270` / `startConversation`: fields `title`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Portal/PortalMessageController.php:404 `$photo = ClientPhoto::create([`; app/Http/Controllers/Portal/PortalMessageController.php:444 `$doc = ClientDocument::create([`; app/Http/Controllers/Portal/PortalMessageController.php:489 `OpsMessage::create([`; app/Http/Controllers/Portal/PortalMessageController.php:501 `$conversation->update(['updated_at' => now()]);`; app/Http/Controllers/Portal/PortalMessageController.php:325 `$message->delete();`; app/Http/Controllers/Portal/PortalMessageController.php:311 `$message->update(['is_pinned' => ! $message->is_pinned]);`; app/Http/Controllers/Portal/PortalMessageController.php:292 `$existing->delete();`; app/Http/Controllers/Portal/PortalMessageController.php:294 `OpsMessageReaction::create([`; app/Http/Controllers/Portal/PortalMessageController.php:543 `OpsMessage::create([`; app/Http/Controllers/Portal/PortalMessageController.php:567 `$conversation = OpsConversation::create([`; app/Http/Controllers/Portal/PortalMessageController.php:574 `OpsConversationParticipant::create([`; app/Http/Controllers/Portal/PortalMessageController.php:581 `OpsConversationParticipant::create([`; app/Http/Controllers/Portal/PortalMessageController.php:588 `OpsMessage::create([`; responses app/Http/Controllers/Portal/PortalMessageController.php:67 `return null;`; app/Http/Controllers/Portal/PortalMessageController.php:76 `return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];`; app/Http/Controllers/Portal/PortalMessageController.php:82 `return inertia('portal/messages', [`; app/Http/Controllers/Portal/PortalMessageController.php:338 `return response()->json([]);`; app/Http/Controllers/Portal/PortalMessageController.php:367 `return response()->json($results);`; app/Http/Controllers/Portal/PortalMessageController.php:196 `return null;`; app/Http/Controllers/Portal/PortalMessageController.php:205 `return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];`; app/Http/Controllers/Portal/PortalMessageController.php:228 `return [`; app/Http/Controllers/Portal/PortalMessageController.php:240 `return null;`; app/Http/Controllers/Portal/PortalMessageController.php:249 `return ['id' => $u->id, 'name' => $u->name, 'presence' => $presence];`; app/Http/Controllers/Portal/PortalMessageController.php:256 `return inertia('portal/messages', [`; app/Http/Controllers/Portal/PortalMessageController.php:486 `return redirect()->back();`; app/Http/Controllers/Portal/PortalMessageController.php:503 `return redirect()->back();`; app/Http/Controllers/Portal/PortalMessageController.php:327 `return redirect()->back();`; app/Http/Controllers/Portal/PortalMessageController.php:313 `return redirect()->back();`; app/Http/Controllers/Portal/PortalMessageController.php:301 `return redirect()->back();`; app/Http/Controllers/Portal/PortalMessageController.php:554 `return redirect("/portal/clients/{$client->id}/messages/{$existing->id}");`; app/Http/Controllers/Portal/PortalMessageController.php:598 `return $conversation;`; app/Http/Controllers/Portal/PortalMessageController.php:601 `return redirect("/portal/clients/{$client->id}/messages/{$conversation->id}");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/clients/{client}/messages` — `portal.clients.messages` — `App\Http\Controllers\Portal\PortalMessageController@index` — `app/Http/Controllers/Portal/PortalMessageController.php:31` — middleware `web, auth`
- `GET|HEAD portal/clients/{client}/messages-search` — `portal.clients.messages.search` — `App\Http\Controllers\Portal\PortalMessageController@searchMessages` — `app/Http/Controllers/Portal/PortalMessageController.php:330` — middleware `web, auth`
- `GET|HEAD portal/clients/{client}/messages/{conversation}` — `portal.clients.messages.show` — `App\Http\Controllers\Portal\PortalMessageController@show` — `app/Http/Controllers/Portal/PortalMessageController.php:100` — middleware `web, auth`
- `POST portal/clients/{client}/messages/{conversation}` — `portal.clients.messages.send` — `App\Http\Controllers\Portal\PortalMessageController@storeMessage` — `app/Http/Controllers/Portal/PortalMessageController.php:370` — middleware `web, auth`
- `DELETE portal/clients/{client}/messages/archive/{message}` — `portal.clients.messages.archive` — `App\Http\Controllers\Portal\PortalMessageController@archiveMessage` — `app/Http/Controllers/Portal/PortalMessageController.php:316` — middleware `web, auth`
- `POST portal/clients/{client}/messages/pin/{message}` — `portal.clients.messages.pin` — `App\Http\Controllers\Portal\PortalMessageController@togglePin` — `app/Http/Controllers/Portal/PortalMessageController.php:304` — middleware `web, auth`
- `POST portal/clients/{client}/messages/react/{message}` — `portal.clients.messages.react` — `App\Http\Controllers\Portal\PortalMessageController@toggleReaction` — `app/Http/Controllers/Portal/PortalMessageController.php:275` — middleware `web, auth`
- `POST portal/clients/{client}/messages/start` — `portal.clients.messages.start` — `App\Http\Controllers\Portal\PortalMessageController@startConversation` — `app/Http/Controllers/Portal/PortalMessageController.php:506` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/PortalMessageController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/messages.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
