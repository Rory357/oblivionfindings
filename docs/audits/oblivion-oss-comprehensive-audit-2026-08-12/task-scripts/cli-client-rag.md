# CLI-CLIENT-RAG: Client Rag

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned|clients.viewPortal`, `permission:clients.viewAny|clients.viewAssigned`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-RAG`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned|clients.viewPortal`, `permission:clients.viewAny|clients.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.viewAny|clients.viewAssigned|clients.viewPortal`, `throttle:ai-queries`, `permission:clients.viewAny|clients.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST clients/{client}/rag/ask` (`clients.rag.ask`, action `ask`). Source category: **mutation outcome source gap (ask)**; controller `app/Http/Controllers/ClientRagController.php:14-75`; `question`.
3. Invoke only the owning control for `POST operations/clients/{client}/rag/ask` (`operations.clients.rag.ask`, action `ask`). Source category: **mutation outcome source gap (ask)**; controller `app/Http/Controllers/ClientRagController.php:14-75`; `question`.
4. Invoke only the owning control for `POST portal/clients/{client}/rag/ask` (`portal.clients.rag.ask`, action `ask`). Source category: **mutation outcome source gap (ask)**; controller `app/Http/Controllers/ClientRagController.php:14-75`; `question`.

## Source-applicable states and transitions

- **mutation outcome source gap (ask)** is applicable only to `ask` / `ROUTE-0189` at `app/Http/Controllers/ClientRagController.php:14`; it is not runtime-observed.
- **mutation outcome source gap (ask)** is applicable only to `ask` / `ROUTE-2040` at `app/Http/Controllers/ClientRagController.php:14`; it is not runtime-observed.
- **mutation outcome source gap (ask)** is applicable only to `ask` / `ROUTE-2275` at `app/Http/Controllers/ClientRagController.php:14`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0189` / `ask`: fields `question`; failure app/Http/Controllers/ClientRagController.php:25 `return back()->withErrors(['question' => 'LLM is not configured. Set OPENAI_API_KEY.']);`; app/Http/Controllers/ClientRagController.php:32 `return back()->withErrors(['question' => 'Unable to create vector store.']);`; app/Http/Controllers/ClientRagController.php:66 `return back()->withErrors([`.
- `ROUTE-2040` / `ask`: fields `question`; failure app/Http/Controllers/ClientRagController.php:25 `return back()->withErrors(['question' => 'LLM is not configured. Set OPENAI_API_KEY.']);`; app/Http/Controllers/ClientRagController.php:32 `return back()->withErrors(['question' => 'Unable to create vector store.']);`; app/Http/Controllers/ClientRagController.php:66 `return back()->withErrors([`.
- `ROUTE-2275` / `ask`: fields `question`; failure app/Http/Controllers/ClientRagController.php:25 `return back()->withErrors(['question' => 'LLM is not configured. Set OPENAI_API_KEY.']);`; app/Http/Controllers/ClientRagController.php:32 `return back()->withErrors(['question' => 'Unable to create vector store.']);`; app/Http/Controllers/ClientRagController.php:66 `return back()->withErrors([`.

## Failure and recovery paths

- `ask`: app/Http/Controllers/ClientRagController.php:25 `return back()->withErrors(['question' => 'LLM is not configured. Set OPENAI_API_KEY.']);`; app/Http/Controllers/ClientRagController.php:32 `return back()->withErrors(['question' => 'Unable to create vector store.']);`; app/Http/Controllers/ClientRagController.php:66 `return back()->withErrors([`.
- `ask`: app/Http/Controllers/ClientRagController.php:25 `return back()->withErrors(['question' => 'LLM is not configured. Set OPENAI_API_KEY.']);`; app/Http/Controllers/ClientRagController.php:32 `return back()->withErrors(['question' => 'Unable to create vector store.']);`; app/Http/Controllers/ClientRagController.php:66 `return back()->withErrors([`.
- `ask`: app/Http/Controllers/ClientRagController.php:25 `return back()->withErrors(['question' => 'LLM is not configured. Set OPENAI_API_KEY.']);`; app/Http/Controllers/ClientRagController.php:32 `return back()->withErrors(['question' => 'Unable to create vector store.']);`; app/Http/Controllers/ClientRagController.php:66 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientRagController.php:34 `$client->forceFill(['openai_vector_store_id' => $vsId])->save();`; responses app/Http/Controllers/ClientRagController.php:25 `return back()->withErrors(['question' => 'LLM is not configured. Set OPENAI_API_KEY.']);`; app/Http/Controllers/ClientRagController.php:32 `return back()->withErrors(['question' => 'Unable to create vector store.']);`; app/Http/Controllers/ClientRagController.php:66 `return back()->withErrors([`; app/Http/Controllers/ClientRagController.php:71 `return back()->with('rag_answer', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/rag/ask` — `clients.rag.ask` — `App\Http\Controllers\ClientRagController@ask` — `app/Http/Controllers/ClientRagController.php:14` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned|clients.viewPortal, throttle:ai-queries`
- `POST operations/clients/{client}/rag/ask` — `operations.clients.rag.ask` — `App\Http\Controllers\ClientRagController@ask` — `app/Http/Controllers/ClientRagController.php:14` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, throttle:ai-queries`
- `POST portal/clients/{client}/rag/ask` — `portal.clients.rag.ask` — `App\Http\Controllers\ClientRagController@ask` — `app/Http/Controllers/ClientRagController.php:14` — middleware `web, auth, throttle:ai-queries`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientRagController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
