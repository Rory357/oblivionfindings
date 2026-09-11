# W14 Fleet technical intake — 12 September 2026

Mappings: W14 / F06 / B04 / E13. Implemented; backend and focused UI checks pass. Actual desktop direct/recovered/urgent, restricted-source, keyboard and denial/Back recovery journeys passed. Full W14/E13 and release acceptance remain incomplete.

The existing Fleet source worker now records and dispatches a separate IT destination. The real consumer creates one canonical incident per persisted offline episode, attaches the actual Asset/Device and optional operational alert, applies the existing priority/routing/SLA services and captures immutable version3 evidence with a FleetSignal source. It does not fabricate a DeviceEvent or copy GPS, people, trips or provider diagnostics.

Stored SignalRule assessment routes confirmed nonurgent availability work directly to IT. Urgent/unclassified events retain Control Room coordination and linked technical work. Existing maintenance windows suppress creation. Exact recovery records verification-required state without closing either record; recovery-first delivery creates open verification work without a stale alert. Settled replays do not create work; later episodes do.

Device and Fleet use the same atomic IT transaction, scheduled recovery and bounded manual retry implementation on their existing outboxes. Mandatory audit or consumer failure rolls back IT writes while preserving source acknowledgement. A manual retry retains lifetime attempts and grants one bounded attempt. Fleet operational recovery now requires its audit to succeed too.

The ticket context exposes canonical Asset links and sealed Fleet source details only through current approved-Site and Asset/Device permissions; linked operational evidence also requires Control Room access. The UI reuses the existing workspace destinations and evidence cards. Restricted Asset metadata has no destination or copied title/tag.

Verification:

- Initial run95909/tokenit_32096171874d44ca:121 passed/1 failed,3827 assertions203.40s,terminal1. The new access fixture used nonexistent IT permission keys; the production gate correctly denied it. Fixed the fixture to use canonical it.view/it.manage; no permission boundary was loosened. All14 isolation cleanup checks/schema absence passed.
- Final run81252/tokenit_84807a3ed2de46a5: **125 passed /3851 assertions /203.30s /terminal0**, all14 isolation cleanup checks/exact schema absent. Includes actual Fleet source/IT job execution, direct/urgent routing, recovery/replay/recurrence, write/audit failures, retry, maintenance, recorded-rule stability, Asset/Device source access, existing Device evidence/delivery and Fleet ingestion/offline publication.
- Focused UI62746: **9 passed /2 files /28.99s /terminal0**. Full TypeScript42994 and scoped ESLint96755 passed terminal0. PHP and frontend formatting passed.
- Build72860: **3m50s /terminal0**, app-B9FgKzu6.js, manifestb650d03c08bbe907a998d378d95313d0fb8992439d38d0625835df97fab8101c; public/hot absent.
- Source/test/fixture bundle: w14-fleet-intake-source-hashes.json (28 files). All28 hashes rechecked after browser verification with no mismatch. Browser runb8e1480e92fa437b passed; owned tab7 closed and schema/directory removal independently confirmed. See w14-fleet-intake-browser-results.md. All10 protected design hashes match.

Changed files are enumerated with exact hashes in the source bundle. They cover shared/Fleet delivery services, Fleet consumer/job/source worker, scheduler/CLI retry, Fleet/Signal routing, canonical link/evidence services and presenters, additive evidence migration000029, ticket context/evidence components and their focused tests, and the guarded browser fixture. Migration000028 is the preceding contract slice. Working database migrations17–29 remain unapplied; no deployment or real communications/provider actions occurred.

Independent-worker Fleet IT contention/interruption/retry/recovery now passed330 assertions, with native regression272 separately; see w14-fleet-it-worker-results.md for exact invocation caveats and cleanup. Remaining: operator create/link-existing handoff, failure/retry UI and other source-capability/E13 criteria remain open. This does not establish full W14 or release readiness.
