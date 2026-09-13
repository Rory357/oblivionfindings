# W06 — native Back/Forward and last-edit recovery review

Read-only checkpoint2026-09-09. **Confirmed gap, not implemented or verified.** No history listener, marker, browser storage, configuration, retention policy or provider changes were made by this review. It supports W06/F09/F16/E04/E06; ordinary Inertia GET and beforeunload tests do not establish native SPA history acceptance.

## Evidence

The installed `@inertiajs/core` version is2.3.11. Its `dist/index.js` lines1406 and1439–1460 attach native `popstate`, decrypt the historical page and call `page.setQuietly(data, {preserveState:false})`. Only after replacement does it emit the non-cancellable `navigate` event. The cancellable `before` event belongs to request-driven visits. Native same-document traversal can therefore unmount an IT form without passing through the current GET or beforeunload guards. The existing incident draft helper also only handles beforeunload; it supplies no approved native traversal recovery mechanism.

[Inertia v2's event documentation](https://inertiajs.com/docs/v2/advanced/events) confirms that native popstate is not cancellable and that its navigate event occurs after successful history navigation. The [HTML Navigation API specification](https://html.spec.whatwg.org/multipage/nav-history-apis.html#the-navigateevent-interface) permits cancellation only when `NavigateEvent.cancelable` is true; some traversals, including repeated browser Back shortly after an earlier cancellation, intentionally cannot be cancelled. An API listener alone cannot promise universal loss prevention.

Root checked the actual Codex in-app browser read-only: `window.navigation?.addEventListener` unavailable and `window.navigation?.traverseTo` unavailable. A Navigation API implementation cannot be exercised in that required browser. Root explicitly rejected history-marker/pushState approaches that could truncate Forward history. No such workaround was added.

## Why a last-second flush is insufficient

Current `use-it-ticket-draft.ts` only receives a host snapshot when `save(snapshot)` runs. Resolve's800ms and intake's750ms debounces mean a last edit may never reach the hook before Back. `canMutate` rejects overlapping or frozen operations; an existing save/upload/conflict cannot safely be overwritten by a cleanup callback. Hook cleanup increments its epoch, aborts transport and clears frozen/submitted refs. Response acknowledgement additionally requires a mounted client with the same actor/context epoch.

Calling Axios from unmount cannot establish success and races that cleanup. A keepalive/beacon transport would also lack a guaranteed committed acknowledgement and must not bypass current actor, CSRF, scope, expected revision, canonical submission uncertainty or upload rules. It could at best attempt delivery; it cannot honestly label the final edit saved or close E06. Removing abort indiscriminately would instead retain obsolete actors' requests and allow stale acknowledgements to mutate later UI. This review recommends neither shortcut.

## Bounded extension for root review

Extend the existing canonical draft client's in-memory lifetime across same-document form unmounts. Its host must supply the latest typed snapshot as it changes, not only at the debounce boundary. The existing purpose/context contract and draft generation identify a retained unsent buffer alongside any frozen operation; this is not a new ticket/draft record or browser content store. Keep any original selected File objects and upload UUIDs as unsaved/unconfirmed data, never filename-only saved-file representations.

Each buffer requires original actor, purpose/audience, canonical ticket or request UUID, draft generation, acknowledged revision, base ticket version and snapshot identity. A new mount may offer metadata-only notice that unsaved browser work exists, but must not expose its content or auto-adopt it from cached Inertia auth props. Fresh canonical authorization and explicit Resume are required. Authorization must cover the retained snapshot's selected bindings as well as the current server draft: checking only an older saved draft does not prove that a newer unsaved site's/asset's/private reference is still accessible. Reuse the canonical payload/binding authorization service for that check rather than duplicating its rules in JavaScript.

Changed actor, lost purpose/canonical-record access, logout, effective feature deactivation, terminal consumption, explicit discard and generation replacement need explicit purge rules.401/419 must conceal until the same actor is freshly authorized. Back/Forward cannot move an internal buffer into a public composer. A frozen unknown W02 command/resolve outcome takes precedence over editing or saving a newer buffer; recovery must first reconcile the original command or current canonical version. A known acknowledged saved generation and a newer unsaved local snapshot must remain distinct in copy and controls.

Scope this extension to the existing document's memory and canonical draft hook; do not persist content in localStorage, sessionStorage, Inertia history or a parallel IndexedDB system. It can protect native SPA traversal without touching browser history. Full reload/tab closure remain the existing beforeunload plus acknowledged server-draft boundary. Root must review lifetime/resource bounds and operational activation rather than silently choosing a retention policy. Runtime draft persistence remains disabled and its migration/configuration gate remains separate.

## Necessary proof before acceptance

- Type the final character and invoke actual browser Back before the debounce; Forward/return offers explicit recovery of the exact latest authorized snapshot and preserves history order.
- Repeat while save or upload is pending, after a draft409, with a failed transport and while canonical creation/resolution is unknown. There must be no duplicate submission, false saved claim, lost upload identity or implicit newer-revision adoption.
- Confirm a second actor, revoked site/private record/purpose, stale cached page, logout and session expiry cannot reveal or submit the retained payload. An older saved binding is not sufficient evidence for a newer local binding.
- Public/internal contexts and two ticket/intake contexts never overwrite one another. Explicit discard, successful consumption and context replacement clear only the exact intended generation.
- Browser refresh/closure and disabled persistence stay truthfully distinct from same-document in-memory recovery. Required browser acceptance remains pending; unit tests alone do not prove native Back behavior.

The intake/draft agent independently confirmed the present cleanup limitation and the bounded in-memory-lifetime approach. Root owns the decision and follow-up implementation; no source changes were made during this review. Next coordinate that exact lifetime/authorization contract before modifying the shared hook, then verify the final browser journey on a fresh coordinated asset build.

Follow-up: root approved the bounded RAM extension and a canonical read-only candidate authorization seam. The new helper and24 memory-only tests are recorded separately in `w06-draft-memory-results.md`. Root chose20 buffers/64MiB as a local resource ceiling, without TTL or silent eviction. Continuous mounted retention and actionable capacity feedback are required; shared-hook integration and actual native traversal remain pending.
