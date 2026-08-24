# OPS-MESSAGE: Message

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Operations and rostering
- Legacy family: `OPS-MESSAGE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/messages` (`operations.messages.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/messages` (`operations.messages.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/messages-search` (`operations.messages.search`, action `searchMessages`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/MessageController.php:246-263`.
3. Use `GET|HEAD operations/messages/{conversation}` (`operations.messages.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/MessageController.php:112-173`.
4. Invoke only the owning control for `POST operations/messages/{conversation}` (`operations.messages.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/MessageController.php:175-208`; `content`.
5. Invoke only the owning control for `PATCH operations/messages/{conversation}/read` (`operations.messages.read`, action `markRead`). Source category: **mutation outcome source gap (markRead)**; controller `app/Http/Controllers/Operations/MessageController.php:292-305`; no exact validation fields extracted.
6. Invoke only the owning control for `DELETE operations/messages/archive/{message}` (`operations.messages.archive`, action `archiveMessage`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/MessageController.php:235-244`; no exact validation fields extracted.
7. Invoke only the owning control for `POST operations/messages/create` (`operations.messages.create`, action `createConversation`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/MessageController.php:68-110`; `participant_ids`.
8. Invoke only the owning control for `POST operations/messages/pin/{message}` (`operations.messages.pin`, action `togglePin`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/MessageController.php:227-233`; no exact validation fields extracted.
9. Invoke only the owning control for `POST operations/messages/react/{message}` (`operations.messages.react`, action `toggleReaction`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/MessageController.php:210-225`; `emoji`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2097` at `app/Http/Controllers/Operations/MessageController.php:14`; it is not runtime-observed.
- **information presented** is applicable only to `searchMessages` / `ROUTE-2098` at `app/Http/Controllers/Operations/MessageController.php:246`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2099` at `app/Http/Controllers/Operations/MessageController.php:112`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2100` at `app/Http/Controllers/Operations/MessageController.php:175`; it is not runtime-observed.
- **mutation outcome source gap (markRead)** is applicable only to `markRead` / `ROUTE-2101` at `app/Http/Controllers/Operations/MessageController.php:292`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `archiveMessage` / `ROUTE-2102` at `app/Http/Controllers/Operations/MessageController.php:235`; it is not runtime-observed.
- **created/recorded** is applicable only to `createConversation` / `ROUTE-2103` at `app/Http/Controllers/Operations/MessageController.php:68`; it is not runtime-observed.
- **updated/revised** is applicable only to `togglePin` / `ROUTE-2104` at `app/Http/Controllers/Operations/MessageController.php:227`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleReaction` / `ROUTE-2105` at `app/Http/Controllers/Operations/MessageController.php:210`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/messages/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2100` / `store`: fields `content`.
- `ROUTE-2103` / `createConversation`: fields `participant_ids`.
- `ROUTE-2105` / `toggleReaction`: fields `emoji`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/MessageController.php:196 `OpsMessage::create([`; app/Http/Controllers/Operations/MessageController.php:302 `$participant->update(['last_read_at' => now()]);`; app/Http/Controllers/Operations/MessageController.php:241 `$message->delete();`; app/Http/Controllers/Operations/MessageController.php:95 `$conversation = OpsConversation::create([`; app/Http/Controllers/Operations/MessageController.php:102 `OpsConversationParticipant::create([`; app/Http/Controllers/Operations/MessageController.php:231 `$message->update(['is_pinned' => !$message->is_pinned]);`; app/Http/Controllers/Operations/MessageController.php:220 `if ($existing) { $existing->delete(); } else {`; app/Http/Controllers/Operations/MessageController.php:221 `OpsMessageReaction::create(['message_id' => $message->id, 'user_id' => $auth->id, 'emoji' => $validated['emoji']]);`; responses app/Http/Controllers/Operations/MessageController.php:37 `return $conv;`; app/Http/Controllers/Operations/MessageController.php:58 `return $user;`; app/Http/Controllers/Operations/MessageController.php:61 `return inertia('operations/messages/Index', [`; app/Http/Controllers/Operations/MessageController.php:252 `if (strlen($q) < 2) return response()->json([]);`; app/Http/Controllers/Operations/MessageController.php:256 `return response()->json(`; app/Http/Controllers/Operations/MessageController.php:165 `return inertia('operations/messages/Index', [`; app/Http/Controllers/Operations/MessageController.php:207 `return redirect()->back();`; app/Http/Controllers/Operations/MessageController.php:304 `return redirect()->back();`; app/Http/Controllers/Operations/MessageController.php:243 `return redirect()->back();`; app/Http/Controllers/Operations/MessageController.php:91 `return redirect()->back()->with('selected_conversation_id', $existing->id);`; app/Http/Controllers/Operations/MessageController.php:109 `return redirect()->back()->with('selected_conversation_id', $conversation->id);`; app/Http/Controllers/Operations/MessageController.php:232 `return redirect()->back();`; app/Http/Controllers/Operations/MessageController.php:224 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/messages` — `operations.messages.index` — `App\Http\Controllers\Operations\MessageController@index` — `app/Http/Controllers/Operations/MessageController.php:14` — middleware `web, auth`
- `GET|HEAD operations/messages-search` — `operations.messages.search` — `App\Http\Controllers\Operations\MessageController@searchMessages` — `app/Http/Controllers/Operations/MessageController.php:246` — middleware `web, auth`
- `GET|HEAD operations/messages/{conversation}` — `operations.messages.show` — `App\Http\Controllers\Operations\MessageController@show` — `app/Http/Controllers/Operations/MessageController.php:112` — middleware `web, auth`
- `POST operations/messages/{conversation}` — `operations.messages.store` — `App\Http\Controllers\Operations\MessageController@store` — `app/Http/Controllers/Operations/MessageController.php:175` — middleware `web, auth`
- `PATCH operations/messages/{conversation}/read` — `operations.messages.read` — `App\Http\Controllers\Operations\MessageController@markRead` — `app/Http/Controllers/Operations/MessageController.php:292` — middleware `web, auth`
- `DELETE operations/messages/archive/{message}` — `operations.messages.archive` — `App\Http\Controllers\Operations\MessageController@archiveMessage` — `app/Http/Controllers/Operations/MessageController.php:235` — middleware `web, auth`
- `POST operations/messages/create` — `operations.messages.create` — `App\Http\Controllers\Operations\MessageController@createConversation` — `app/Http/Controllers/Operations/MessageController.php:68` — middleware `web, auth`
- `POST operations/messages/pin/{message}` — `operations.messages.pin` — `App\Http\Controllers\Operations\MessageController@togglePin` — `app/Http/Controllers/Operations/MessageController.php:227` — middleware `web, auth`
- `POST operations/messages/react/{message}` — `operations.messages.react` — `App\Http\Controllers\Operations\MessageController@toggleReaction` — `app/Http/Controllers/Operations/MessageController.php:210` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/MessageController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/messages/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
