# Locate current-evidence inventory

2026-09-21. Bounded implementation through Main release revision 15 and the user's subsequent motion/fall presentation request. This is the actual Locate path, not a claim that all application queries are current locking reads. One operating organisation across approved Sites; no new tenancy boundary. See `status-and-audit-checkpoint.md` for the current verification ledger; earlier passing runs do not imply whole-candidate acceptance.

## Intake, re-entry and queue

`ClientLocationLocateController` uses the authenticated actor, real password-confirmation session time and validated reason/UUID/privacy fingerprint. `ClientLocationLocateService` resolves current client visibility/section/management access and creates a typed server-owned `ClientLocationCommandOrigin`. HTTP input cannot supply origin, actor, device, capability, parameters or step-up evidence. The command is always `tracking.location_refresh` with empty parameters. Availability and status reads do not enqueue.

`DeviceCommandRequestService` locks an existing canonical request before the context graph. It invokes `ClientLocationCommandContext::lock` on initial intake and generic re-entry carrying an origin. A new request never performs a second existing-key locking lookup after acquiring the evidence graph; a uniqueness collision rolls back and retries the same key, or escapes a caller-owned transaction as a retryable busy condition. Existing nonce, reason, expiry and signed origin are preserved. The reserved client prefix without a typed origin is denied, but prefix spelling is not the authorization proof.

`GovernedCommandDispatchService::assertCurrentContract` verifies the entire original signature before classifying context, then invokes the same context guard at queue and worker dispatch. The original requester is always guarded. Capability, risk, approvals, step-up, route, site, assignment fingerprint, expiry and canonical state checks remain shared with generic commands. A different triggering administrator additionally passes the existing triggering-actor policy. Accepted/Running provider claims use a separate allowed-state set through the same predicate; they do not call a Ready/Queued-only entry point.

## Locked consent and assignment evidence

The guard requires a transaction. `CurrentConsentEvidence` uses ordinary reads only to discover graph pointers. It current-locks substitute authority, scope, the own/capacity consent IDs in sorted order, consent type/version and source request with `FOR SHARE NOWAIT`; pointer mismatch fails closed. The canonical source/self/substitute predicates in `ConsentValidationService::evaluateEvidence` are reused. All relations consumed by its `loadMissing` are already populated, including explicit nulls. Current resident-specific and general tracking entry points retain their existing distinct allowlists and use the same source, purpose, capacity, authority and expiry predicates.

The current Device, Client, operational custody Site and every active assignment for that Device are shared locked. Exactly one current client assignment must match the signed Client/assignment/consent and custody Site, with collection/purpose/authority/audience/retention and consent fingerprint still valid. `PersonalTrackingPrivacyService` reuses its canonical assignment predicate with the locked evidence. `DeviceCustodySiteResolver` carries the explicit lock mode through its participating queries; ordinary callers retain their original behavior.

## Current staff, permissions, policy and nested SQL

`AuthorizationEvidenceLockService::lockForUserWithoutWaiting` reads and hydrates current User, permission definitions, override pivots, role pivots, Roles and role permissions using shared non-waiting locks. The existing `User::canDo` implementation, alias rules, explicit denies and legacy roles operate on this graph. The current HR profile and exact care pivot are locked and hydrated as well.

`CurrentAuthorizationReads::within` provides a server-created callback-bounded proof for the same active connection/transaction. It cannot be reused after its callback, even inside a later transaction. It changes no global isolation or ordinary cache settings. Its query hook adds an explicit `FOR SHARE NOWAIT` to each root and nested EXISTS/NOT EXISTS query block; nested grouping blocks recurse into their query children. Query-builder subqueries passed to `whereIn` compile while the proof is active. An outer locking clause alone is not relied upon for nested evidence.

The guard's actual shared-predicate call graph is:

- `ClientPolicy::viewFromCurrentEvidence` → the existing view decision → `User::canAccessClientPortal` (including current negative HR check and portal pivot), or existing viewAny/assigned-only grants and `UserSiteAccessService`. Exact care-pivot evidence and the existing clinical/site bypasses are retained.
- `UserSiteAccessService::accessibleSiteIds(..., reads)` uses a per-call clone with an empty local memoization cache, current HR employment/start/end/secondary Sites and operational Site queries. Ordinary callers retain the ordinary cache.
- `ClientProfileSectionAccess::trackingFromCurrentEvidence` → exact care query and `ClientWorkerEligibility::isEligible(..., reads)` → the existing role-eligible query and `applyFleetRecipientEligibility`. Current staff/legacy role, RBAC role, employment/date and Site SQL is reused, including nested EXISTS and negative non-staff branches. It does not compute unrelated profile sections.
- `DeviceManagementAuthorizationService::forCurrentEvidence(...)->evaluate` uses fresh local permission/deny/visibility/assignment caches, the original ordered level lattice and explicit-deny query. Active assignments are current reads. Its general personal-tracking check invokes the canonical current-consent path; the ordinary eager consent relation is not used as authority in that branch.
- `SecurityDevicesAccessService::forCurrentEvidence` wraps the existing visible-device query and its approved Sites, rooms, current HR, assigned Client/Asset/Staff candidate queries, per-target policy and mixed-custody exclusions. Candidate assignment/asset-link scans are narrowed to the requested Device. Related category reads used by Asset policy are explicitly current. `AssetPolicy::view` delegates to `canAccessAsset`; the current path invokes that same predicate. Ordinary Gate paths are unchanged.
- `CanonicalDeviceSiteResolver::forCurrentEvidence(...)->resolve` current-reads the Device, eager active assignment/asset-link relations and the actual Site/Client/HR/Asset targets, with a fresh local Site cache. The guard uses `resolve`; the unrelated loaded-picker method is not a current-evidence entry point used here.
- `CommandAssignmentFingerprint::forDevice(..., reads)` serializes the same canonical assignment fields from current rows; the signed fingerprint must match.

The ordinary stock visibility branch contains an existing raw last-known-custody scalar subquery. The Locate guard requires and locks the active client assignment before visibility evaluation; the stock branch requires no active assignments under its explicit current nested NOT EXISTS, so it cannot grant Locate authority. No claim is made that arbitrary raw SQL is rewritten by the current-read helper. Schema capability memoization is structural, not staff/privacy authority.

## Provider claim, atomic failure and contention

`FrameRouter::claimQueuedCommand` acquires the provider Device/pending row, checks immutable sent/session/ACK evidence, then `GovernedCommandLifecycleService::authoriseDelivery` locks canonical request before attempt, verifies signature and linkage and reuses the context/dispatch contract before reading/rebinding/marking command bytes sent. Missing/partial/mismatched governed links never become legacy delivery. Privacy/policy/expiry rejection transitions the pending row and the legal request/attempt states together with an audit record; audit failure rolls back the whole claim. Accepted/Running is not illegally converted to Blocked. Truly legacy unlinked/unmarked commands retain their behavior.

Graph evidence uses compatible shared NOWAIT reads. MySQL error 3572 is transient: it must escape any nested savepoint to the owning outer transaction. HTTP returns retryable busy; a standalone provider claim returns no command after full rollback. Busy does not terminally fail, extend, or duplicate the original request. The verified database is MySQL 8.0.44 with REPEATABLE READ. Tests establish old snapshots and primed caches before committed changes, claim-first writer exclusion, nested rollback/retry, and overlapping independent clients/actors sharing Role/Permission/type/version definitions. Claim commit is the authorization linearization point; this does not recall bytes after socket delivery.

## Signed origin and durable provenance deployment limits

Nullable immutable hidden `origin_context` belongs to the existing canonical request. Non-null context selects schema 8; context-null schemas 2–7 retain their golden serialized bytes. Migration rollback refuses to remove retained origin evidence.

The separate hidden `was_governed` provider-row marker is set atomically whenever real canonical linkage is created, backfilled only from actual request/attempt links and cannot be downgraded through normal model updates. Sequence 1 / role action are legacy defaults, not provenance. Historically damaged rows whose links were already removed cannot be reliably inferred. Arbitrary privileged SQL removing every provenance field is outside this claim.

Future deployment must coordinate migration and code: migrate/backfill while old writers are quiesced or otherwise reconcile linked rows created in the migration/code gap before claims resume. Only the isolated synthetic preview/test schemas were migrated here; no operational backfill or deployment ran. Both additive rollback/refusal behaviors were tested independently.

## Read disclosure and frontend lifetime

Status is scoped to the exact actor, Client, Device, Site and signed origin, with a post-read access/fingerprint recheck. Observation disclosure requires the exact provider pending/request/attempt telemetry reference, an in-window sent/received interval, consent/collection/retention bounds and measured time. ACK, command completion and a newer measured fix remain separate. Older measurements never replace the current marker or an explicitly selected historical point.

The Locate dialog aborts close/unmount/hidden/privacy responses and invalidates generations after parsing too. Uncertain POST retries retain nonce and reason. The received-report live view is a separate 30-second Inertia partial reload, with overlap prevention and cancellation on pause/hidden/unmount/Client or fingerprint change; it preserves local component/scroll state. It never issues Locate or changes device reporting frequency.

## Editor and status additions

Whole-zone pointer dragging previews one translated shape and commits once on release; touch cancellation/Escape restore the original. Centre handles and numbered corners support keyboard movement; handles are 44 px. Undo/redo uses the existing draft history. Address selection pans the map without moving the shape; explicit Move zone here translates it. Linked Site geometry must first be made a custom copy. Grayscale applies to tile panes only.

The client address adapter retains pre-provider and pre-disclosure access checks, no-store and bounded input. It reuses the existing Nominatim provider, sends only the typed query and normal provider search options, and reports provider failures distinctly. User permission for that specific transmission was confirmed in Main release revision 7; automated verification uses synthetic HTTP responses. Existing Site callers retain their original fallback behavior. No client identity, tracker details or history are appended.

Panic false defaults are not a reported all-clear. The card distinguishes explicit active, timestamped acknowledgement and unconfirmed status; absent time does not imply an empty history. The controller normalizes non-boolean metadata to unknown. Battery charging remains independently timestamped. Power-saving status/control is unavailable in this view; no supported capability or mode is invented. Operational zone activation/evaluation/response, outings and new sharing grants remain separate unfinished requirements.
