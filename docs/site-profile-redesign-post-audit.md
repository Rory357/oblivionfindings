# Site Profile redesign post-implementation audit

**Audit date:** 2026-07-22

**Reference:** `C:\Users\steph\Downloads\Site profile page redesign.zip`

**Implementation branch:** `codex/site-profile-redesign`

**Worktree:** `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-site-profile-redesign`

**Status:** Implementation-complete in the isolated branch; not merged, pushed, deployed, or live-accepted.

## Outcome

The supplied design has been rebuilt as the canonical responsive web Site Profile. The result uses the Site's configured brand colour and the organisation primary token as its fallback, groups the profile into one typed navigation registry, keeps the initial payload small through four permission-shaped deferred groups, and routes cross-module work to its established owner instead of introducing parallel forms, registers, or credential dialogs.

Three browser-discovered correctness gaps were closed before this audit: tenant leakage from the Site index/Add Site reference payload, the green hero fallback, and a deferred-request failure path that could loop or remain stuck on Loading. The final browser retry proof settles on one accessible error state and recovers through one explicit retry.

## Requirement audit

| Requirement | Current implementation | Authoritative evidence | Result |
| --- | --- | --- | --- |
| Implement the supplied Site Profile design | Branded `PageHero`, grouped/tier-two navigation, finder, readiness, attention, overview cards, type variants, and responsive layout are implemented. | Site Profile frontend suite; screenshots and interaction ledger in `docs/evidence/site-profile-redesign/index.md`. | Verified |
| Follow organisation/Site branding instead of green | `site.brand_colour` is passed when configured; otherwise the hero receives `var(--primary)`. Semantic status tones remain separate. | `resources/js/pages/sites/show.tsx`; shell regression test; branded and unbranded screenshots. | Verified |
| Cover the modules touched by Site Profile | Overview, People, Safety, Operations, and Admin tabs are registry-driven; optional data is split into `peopleData`, `safetyData`, `operationsData`, and `adminData`. | `resources/js/pages/sites/tabs/registry.ts`; `app/Services/Sites/SiteProfileData.php`; payload/registry tests. | Verified |
| Avoid duplicate workflows and modals | Site Profile retains only Site-owned placement/edit surfaces and links or opens the complete canonical owning workflow for cross-module work. | Ownership map below; frontend source guards; real-browser Client/Checklists/Finance/Vendor checks. | Verified |
| Find backend gaps and improvements | Tenant isolation, permission-shaped payloads, bounded attention queries, missing indexes, user preference persistence, and credential non-disclosure were audited and remediated. | Authorization, attention, payload, preference, credential, and query-ceiling tests; migrations. | Verified |
| Preserve safe deep links and failure handling | Unknown/unauthorized tabs normalize to the first visible tab; deferred groups show loading/error/locked states and recover with Retry. | Authorization and registry tests; controlled local outage/recovery proof. | Verified |

## Shipped architecture

- `Sites\SiteProfileController` owns the authorised `sites.show` response; broad Site CRUD remains in `SiteController`.
- `SiteProfileData` assembles the eager shell and the four optional Inertia groups. Unopened registers are not included in the initial response.
- `SiteProfileAttentionService` returns bounded, permission-filtered summaries and actionable rows without creating another corrective-action store or cache.
- `tabs/registry.ts` is the single source for groups, labels, icons, Site-type applicability, permission state, optional data ownership, and warning placement.
- The generalized grouped-profile navigation is shared with Client Profile through a compatibility boundary rather than forked.
- Pin state is stored per user through generic `user_ui_preferences`, keyed by `sites.profile.pinned-tabs`; no new page-specific local-storage convention exists.
- Composite indexes were added for the Site/status/due-date paths used by hazards, drills, documents, assets, and PPE attention queries.
- The Site index and Add Site reference data now fail closed for non-platform users without an organisation and scope Sites, users, and service contexts to the viewer's organisation.

## Canonical workflow ownership

| User intent | Canonical owner kept | Site Profile behaviour |
| --- | --- | --- |
| Create a Client | Complete shared Add Client wizard | Opens the full eight-step wizard with Site defaults. No quick-create clone remains. |
| Link an existing Client | Sites placement workflow | Uses the compact Site-owned placement flow; does not create a Client. |
| Unlink a Client | Site placement endpoint + shared confirmation | Explains placement effects and performs only the placement mutation. |
| Report/manage hazards | Health & Safety Hazard register | Shows a bounded summary and links to the canonical prefilled route. |
| Create/manage risk assessments, inspections, drills, first aid, PPE | Owning Health & Safety workspaces | Site context and summary only; no copied management register. |
| Run/manage Checklists | Site Checklists workspace | Compact summary plus `/sites/{site}/checklists`; the full workspace is not embedded twice. |
| Plan meals | Existing Meal Planner | Reuses its established embedded component and full-module entry point. |
| Manage assets, fleet, hardware | Asset, Fleet, Security Devices modules | Permission-shaped summaries and filtered canonical links. |
| Manage financials | Finance Site Dashboard | Finance summary and canonical dashboard link; the Site ledger is explicitly a secondary named link. |
| Manage vendors/credentials | `/vendors?site_id={site}` | Status/count summary only. Existing audited reveal/edit dialogs remain solely in Vendors. |
| Manage Site documents, contacts, rooms, notes, access and Site metadata | Sites module | Focused Site-owned workflows remain local because Sites owns the records. |

## Backend, authorization, and performance findings

### Fixed

1. **High — tenant index/reference leakage.** A tenant user could receive foreign or unscoped Sites from `/sites`, and Add Site reference lists were not fully organisation-scoped. The query now uses one fail-closed Site scope and applies organisation filters to copyable Sites, users, and service contexts. The profile route was already policy-protected; the index is now consistent with it.
2. **High — credential response boundary.** The earlier Site page owned Vendor/Credential management surfaces. Those dialogs and secret-bearing payloads were removed from Site Profile. Only summary counts/status and the canonical Vendors link remain; reveal continues through its separately authorised and audited endpoint.
3. **Medium — over-broad initial response.** Module registers now materialise only through the requested optional group. Query-count tests cover the eager shell and each partial group.
4. **Medium — deferred network failure.** Inertia network exceptions do not use its validation-error callback. The Site Profile now detects only the matching `X-Inertia-Partial-Data` exception, prevents duplicate in-flight requests, settles Loading, exposes Retry, and suppresses the otherwise-unhandled rejected promise after recording the visible error.
5. **Medium — missing attention indexes.** Composite indexes were added only for confirmed Site/status/date filters. Caching was deliberately omitted because the contributing models do not yet share a complete invalidation contract.
6. **Low — test-harness partial requests.** Feature tests now generate valid Inertia partial headers even when the built asset manifest is present, avoiding false 409 asset-version responses.

### Query and response invariants

- Site policy authorization happens before profile assembly.
- Optional group builders check their own module permissions before querying or returning counts.
- Unauthorized/retired deep links reveal neither protected records nor protected count links and safely select Overview.
- Credential values and credential records do not enter the Site Profile payload.
- Attention results are bounded and carry a real owning tab/module resolution path.
- The initial response contains shell, permissions, hero, Overview, Readiness, attention, and preference data only.

## Browser acceptance

The local browser pass was tied to the isolated worktree and covered a branded house, organisation-colour fallback, day-service hub, head office, restricted viewer, 1440 x 900 desktop, and 390 x 844 responsive layout. It exercised search/Escape/focus, pins after reload, deep links, readiness and attention navigation, canonical Client create versus placement, compact Checklists, canonical Finance/Vendor routes, safe restricted fallbacks, credential non-disclosure, and controlled deferred failure/retry.

The synthetic evidence records were identity-checked and permanently removed afterwards; the verification query found zero remaining records. Ordinary browser passes ended with no console warnings or errors. Details and screenshots are in `docs/evidence/site-profile-redesign/index.md`.

## Fresh final verification

| Gate | Result |
| --- | --- |
| PHP Site/Profile/module feature matrix | Exit 0: 1,899 assertions; 277 known asset-manifest warning annotations; no failures; 1,193.23 seconds. |
| Frontend Site Profile/shared navigation/preferences matrix | Exit 0: 11 files and 62 tests passed in 11.14 seconds. All files were named explicitly after the literal wildcard command selected only three files on Windows. |
| TypeScript | `npm run types` exit 0. |
| Client production build | `npm run build` exit 0 in 4 minutes 37 seconds. |
| SSR production build | `npx vite build --ssr` exit 0 in 1 minute 31 seconds. |
| Formatting and diff checks | `vendor/bin/pint --dirty` passed; `git diff --check` passed. Before the closeout commit, only this audit document was untracked. |

## Remaining improvements and acceptance boundaries

| Priority | Item | User impact | Effort | Owner | Recommended next action |
| --- | --- | --- | --- | --- | --- |
| Medium acceptance | Merge, deploy, migrations, and deployed-browser proof were not requested. | The implementation is not visible outside this branch yet. | Medium | Release owner | After review, merge/push deliberately, run the two migrations, deploy, and repeat a focused branded/restricted smoke test. |
| Low | Deferred-group exception handling is currently local to Site Profile. | None on this page; future deferred pages could otherwise implement the pattern inconsistently. | Small | Frontend platform | Extract a shared tested hook only when a second page needs the same network-error contract. |
| Low | Production latency/error telemetry is not yet attached to each optional group. | Slow data sources would be visible through loading/error UI but harder to trend. | Small–medium | Platform/operations | Add group-labelled timing and failure metrics before considering caching. |
| Low | The local PHP runner emits known asset-manifest warning annotations while assertions pass. | No runtime impact; test output is noisier. | Small | Test infrastructure | Normalize the test asset/version setup separately; do not weaken Inertia assertions. |

No unresolved Site Profile correctness or duplicate-workflow gap was found after the browser-backed remediation. The remaining medium item is an integration/release boundary, not unimplemented feature scope.

## Integration state

- Branch work is committed locally in `codex/site-profile-redesign`.
- No merge, push, pull request, deployment, production migration, or live-site verification has been performed or implied.
