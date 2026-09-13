# W15 requester provisioning tracking

12 September 2026. W15/E14 requester-update and original-contract slice; E07 approval visibility only. The full packages and release gate remain incomplete.

Latest continuation,14:31 NZ: bounded desktop acceptance and exact cleanup completed in w15-tracking-desktop-browser-results.md. That run found/fixed the unsupported reference-search query. Final PHP48688 passed20 diagnostic cases/all14cleanup/schema absent. Subsequent presentation corrections remove repeated error announcements and align pending status tone; UI24/5.10s/lint0 pass. Types49519 found a test-only unsupported exact query option; removed, final68166 running. Final presentation build3251 running. No DB/browser/lock remains. Recheck only the final changed presentation against its new assets before checkpointing; earlier results below are historical within this slice.

## Implementation

- Catalogue author creation maps server validation errors into the existing form and opens the affected wizard step. Successful catalogue-list refresh includes generatedAt and removes a nonexistent stats prop.
- Catalogue submission redirects to the persisted canonical ticket or provisioning request. No parallel request records or ticket copies.
- ItProvisioningAccessService owns requester tracking scope: approved actor with request/manage permission, original creator and original catalogue submission, active eligible employee profile/current approved Site. Internal-only submitted contracts remain management-only; legacy submissions without a contract use their canonical catalogue audience conservatively.
- My requests includes paginated equipment/access work with shared search/status filters, the existing card/table toggle, context menus and native canonical links. Page size20; pagination query resets on filter changes.
- The canonical tracking page uses Rory's PageHeader, GroupPillRail, TierTwoTabs and TabSearchPalette, existing status tokens, shared dates and a balanced details/progress layout. It shows submitted form version, requester-visible answers, current approval/work status, public event labels and explicit refresh.
- The public projection never serializes technician notes, failure reasons, HR IDs, fulfilment/provider context, decision notes, event payloads or employee directories. Entity answers resolve only from currently permitted canonical options. HTTP response is private/no-store. These are read views; no requester approval or fulfilment controls are added.

## Actual checks

- PHP96632 / it_7a88f61e70c74a24 terminal0:18 diagnostic Test Passed events; no Failed/Errored/Skipped; all14 postflight checks, exact schema absent.
- Follow-up access review added an internal-contract guard and role-downgrade regression. Final PHP87896 / it_dd7c1175383241f9 terminal0:19 diagnostic Test Passed events; no Failed/Errored/Skipped; all14 postflight checks, exact schema absent. Normal Pest summaries/assertion counts were not emitted; none are invented.
- HTTP regressions exercise actual creation/tracking/hub list, original contract after withdrawal/revision, other-user404, inactive employee/Site denial, unapproved actor denial, exclusion of ordinary HR work without catalogue provenance, and former-manager internal-request denial. Existing catalogue submission/publication regressions are included.
- Initial types19337 found three shared-component prop mismatches. Corrected.32107 and17654 terminal0. Profile types63044 found the required tab renderer missing; corrected using the shared tab contract, final71627terminal0. Final tracking UI3/3.73s and profile lint0/max-warnings0.
- UI24 tests/3files/4.71s passed (catalogue wizard, shared create recovery, tracking). Subsequent shared profile-nav adjustment:3 tracking tests/3.47s passed. Scoped lint0 including final profile and validation test correction. PHP Pint passed.
- Initial build79409 passed4m8s. Profile build99085 passed4m21s but its source changed during the run; do not use it for acceptance. Definitive final build65330 is live. Only subsequent source edit is an explanatory lint comment, no executable change. Earlier preview96cdb831e547a20f/fingerprint8bdd45f05bb774ac8b7564ea2c0751c42b0a027811c72b21a7eccb417ecc3079 is superseded; rerun Preview after the final build.

## Browser acceptance and remaining work

Pending: fresh exact-fingerprint CatalogueFixtures bootstrap after build65330; normal requester submit→canonical detail→My requests; required validation, public activity, search/no results/recovery, cards/table, keyboard navigation/refresh and another normal user's direct404. No browser acceptance is claimed yet for this slice. User tab2 and desktop viewport must remain unchanged. All prior test schemas have been cleaned; no browser runtime exists yet.

Broader W15 still needs requested-for/audience/Site/attachment intake and preview, full template versioning and approval/dependency/evidence/failure/retry/cancel/reversal lifecycle. Entity answer labels currently reflect permitted canonical records, rather than claiming a historical display-name snapshot. Independent-process publication races, legacy migration acceptance and additional tracking pagination/filter/browser access transitions still need evidence. No real communications/providers, production AI or working-database migrations were used.
