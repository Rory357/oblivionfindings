# W15 catalogue entity selection — in progress

The template recovery/context slice is checkpointed locally on main at `855446684`. The next W15 work completes requester intake; this first change removes a confirmed backend selection ceiling while preserving existing access boundaries.

## Confirmed defect and implementation

`ItCatalogSubmissionService::validateValues` formerly used `ItCatalogFieldOptionService::forTypes` as the allowed-ID list. Its first 200 profiles/assets were therefore also the maximum accepted records, even when later records were otherwise permitted.

The existing field-option service now provides a direct `find` operation backed by the same canonical profile/asset query builders used for discovery. Submission resolves only the supplied current record IDs and snapshots their permitted names/details. Discovery remains bounded at 200 until the paginated selector is integrated. No permissions are expanded: requester profiles/users remain self-only, assets require their current assignment and approved Site, technician records retain current approved Site boundaries, inactive records are unavailable and unapproved actors are denied.

Changed files:

- `app/Domain/It/Services/ItCatalogFieldOptionService.php`
- `app/Domain/It/Services/ItCatalogSubmissionService.php`
- `tests/Feature/It/ItServiceCatalogTest.php`

The new regression creates more than 200 eligible profiles and assets, proves the last records are absent from initial discovery, submits all three entity types through the real catalogue route, checks stored labels and one outcome, then deactivates the profile/retires the asset and requires a new submission to fail without another outcome. The existing forged-object test is included in the same focused run.

## Verification state

Scoped Pint passed. Focused isolated session73913, token `it_4468f879b42d4779`, is running with all14 preflight checks passed; no test result is claimed yet. Output: `w15-catalogue-entity-current-selection-tests.txt` and matching diagnostic JSONL. No other test/import/browser runtime is owned. Poll the exact session and confirm cleanup before starting another database job.

## Next complete vertical slice

Add server-side search/pagination to canonical entity choices and integrate the approved searchable control into the catalogue requester wizard. Scope the endpoint to a currently discoverable published item, the exact requester-visible field and its published version; never accept an arbitrary entity type as directory authority. Recheck approved actor/Site and field visibility on every search and direct selection. Return private responses without caching, bound query/page sizes, deterministic ordering and an explicit continuation indication. Preserve current permitted selected labels across pages; do not silently choose a replacement when access or publication changes.

Verify more-than-200 discovery and submission, empty/search/loading/failure/retry/cancel, stale search responses, changed account/current-Site loss, keyboard selection, and current-build desktop browser journeys. Extend the requester form using Rory's approved wizard controls and the existing canonical submission/recovery services; complete requested-for permissions, attachment fields and both persisted ticket/provisioning outcomes. The direct-ID fix alone does not make the requester selection UI feature-complete or browser Verified.

W15 and E14 remain open, along with eligible provisioning approval, dependencies, fulfilment evidence, failure/retry/cancel/reversal and the later packages/release gate. No production provider or AI execution is enabled.

## 18:20 NZ continuation — search implemented, browser gate pending

- Initial direct-ID session73913 / `it_4468f879b42d4779` completed exit0: two diagnostic Test Passed events, no failures, all14 postflight checks and exact schema absence. This run predates the search endpoint.
- Search endpoint now derives the entity type from the exact visible field in the item's immutable published contract. Approved actor binding is required; withdrawn/unavailable/private/non-entity fields return404, changed published version409. Request bounds are 100 search characters and positive numeric cursors/selection IDs. Canonical queries perform 50-record keyset pagination with one lookahead row, literal wildcard escaping and current selected-record reconciliation. Successful responses include actor/query/item/field/version binding and private no-store headers.
- Search backend session6954 / `it_56657e9e30f24be4` completed exit0: three diagnostic Test Passed events, no failures, all14 postflight checks and exact schema absence. Covers both existing forged-object denial and the expanded beyond-200 submission/search/pagination test, plus visible published field/version/account/withdrawal/literal-search boundaries. The two groups overlap and are not summed.
- Catalogue entity fields now use the shared Popover/Command/Button primitives with bounded server search, load more, selected labels, cancellation, retry, empty/failure distinction, strict typed response checks and concealment after access/session/version loss. The hub keys the catalogue subtree by current actor. This is the selection portion of the intake upgrade; the full requester WizardShell and recoverable submission lifecycle are still next.
- First UI session35517 exited1: 31 passed, one failed. The search input's aria-label was overridden by cmdk's empty generated label. Corrected the Command label and named the popover/list. Recheck: 32 tests / three files / 7.63s, exit0. Keyboard follow-up: eight picker tests / 4.42s, exit0; verifies no implicit choice on footer Enter, retained focus at the last page, and selected label after parent value update. Earlier logs are preserved.
- Scoped strict lint37988 and follow-up lint passed. Source whitespace and fixture PHP syntax passed. TypeScript19892 exited0 before the keyboard follow-up; final TypeScript followed by Vite is now running sequentially in session1225. Do not run another build/type-generation job or start the browser against the previous manifest.
- Thirteen source/test/fixture hashes and all ten unchanged protected design hashes are captured in `w15-catalogue-entity-picker-source-hashes.json`. The existing owned CatalogueFixtures seed now adds a published entities form, 201 synthetic assets assigned to the requester at SiteA, and one assigned asset at unapproved SiteC. It does not create a browser runtime yet.

Next: poll1225 to terminal, resolve any compiler/build issue, update the source bundle with the actual manifest, then Preview/CreateAndStart a fresh fingerprinted CatalogueFixtures runtime. Through normal requester sign-in, use Employee/User/Equipment searches, search/select asset201 beyond initial discovery, verify asset202 exclusion, no-match/retry/cancellation, keyboard pagination/selection and persisted labelled answers. Inspect current records, console and actual desktop layout without resizing; use normal changed-account sign-in to verify stale reads are refused. Close only owned tabs, exact cleanup and independent postflight. Do not call this selection slice browser Verified until those journeys pass. Full W15 requester wizard, attachments/requested-for, canonical ticket/provisioning recovery and complete lifecycle remain in scope.

18:22 NZ: final TypeScript completed successfully; the guarded sequential job1225 emitted Vite startup and is now building. No browser runtime exists. Keep source/helpers/assets frozen until current-build verification.

18:29 NZ: final job1225 terminal0, TypeScript passed then Vite5m25s; app-h_IqAC9r.js, manifestdc8ec99cc733e646a8f892001bdb744c9791b06c1c2bcedab09b186daf544c32. All13source/10design hashes match frozen bundle. Preview captured fingerprintcba8198a9ac75269f9557c8b8cf2eae85f7968b66b029772f1088292363575a4; fresh CatalogueFixtures bootstrap66348 is live for token2c1c543f3e8b45a0. No browser tab or browser acceptance yet. Do not change frozen helpers/migrations/assets or start another DB/import; poll the exact bootstrap to readiness.

18:44 NZ: functional browser2c1c543f3e8b45a0 completed and cleaned49150/postflight0. Actual keyboard201choices/unapproved202denial/session+actorloss+originalaccountrecovery/onepersistedticket detailed in w15-catalogue-entity-picker-browser.md. Selected-row screenshot exposed muted text; corrected only pickerforeground/minimumrowheight after cleanup. Scopedlint pass; contrast build40063 is live, no other runtime/test/import. New13source/10design bundle preserves oldbrowserbundle separately. No new logic/type change; previous functional tests/types remain applicable, but visualretest is required before browserVerified.
