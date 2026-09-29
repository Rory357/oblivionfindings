# Fleet implementation integration audit — 29 September 2026

This audit covers the integrated Fleet & Assets programme, related reporting and People Locations regressions, and the three recent correction sessions. It verifies publication and exercised behaviour; it is not a claim that every Fleet page has completed visual acceptance.

## Publication provenance

The starting main commit was `c2f89b358eb61036dc31795807cab64b83043611`. Git ancestry confirms that it already includes:

- Map Provider Setup Fixes: `4ee973eac` — deployment-managed Google credentials and guided setup.
- Fleet Integration & Reports Fixes: `ad3c031b5`, integrated through `ee22de096` — Reports opens the library, stable report views and corrected report headers.
- Fleet Compliance & Alerts: `e5b085628`, integrated through `c2f89b358` — requirement queue, canonical alert response and hero entry links.

The old August booking privacy branch has two commits with patch-equivalent changes already in main (`git cherry` marks both with `-`). Archive branches retain historical mockups and recovery material; their existence does not mean their old product implementation should be merged again.

Local main was fast-forwarded to `c2838f86ad1f4c3a7cad80d77dedb1fe0462322f`, preserving the nine newer upstream commits before publishing this audit's corrections. This record belongs to the corrective publication commit; the exact local/remote commit comparison is reported in the completing chat.

## Corrections made during this audit

1. Removing navigation above the hero had also removed ordinary access to fuel, drivers, keys, handovers and specialist reports. A permission-aware **More pages** menu now lives inside the workspace hero. It reuses the existing workspace destinations and site-context rules. The left navigation and the approved design reference files are unchanged.
2. Daily checks counted unrelated Control Room sources as Fleet alerts. Its counters now use the same permitted Fleet-source query as the canonical Fleet queue.
3. Regression fixtures and assertions still assumed the retired map response, old Compliance props, destructive boundary deletion and older wizard names. They now exercise the current contracts, including explicit site authority, boundary retirement and canonical queue evidence.
4. The old package-only rollback test bypassed later dependencies. Finance integration intentionally forbids rollback even when empty. The replacement regressions verify that refusal and the vehicle migration's refusal to discard recorded evidence, leaving the integrated schema intact. No foreign keys were disabled and no production migration was changed.
5. People Locations backend HTML tests explicitly disable the optional Node SSR service, making them independent of a local frontend build. Stray outbound request protection remains enabled.
6. New interaction coverage checks that a maintenance asset selection survives a subsequent search. New menu tests check report-only permissions, operational destinations and scope preservation.

## Verification

The broad backend run covers `tests/Feature/FleetAssets`, `tests/Feature/Reporting`, `tests/Feature/Fleet`, Asset/Fleet federation and telemetry ingestion. Targeted follow-ups cover all corrected failures plus People Locations and personal tracking consent withdrawal. Exact run counts and the final outcomes are recorded in `verification.json`; initial failed runs are retained locally rather than described as passing runs.

Final local results: **713 backend tests / 14,911 assertions passed across the broad run and the latest complete rerun of each affected suite; 374 frontend tests passed.** No selected tests were skipped. The backend figure combines those runs; it is not a claim that the original 678-test run passed unchanged. The final five navigation and retained-history tests passed at the integrated main base.

The frontend scope includes Fleet pages and components, reporting, Fleet and Google-map helpers, shared maps, asset components and People Locations. TypeScript, scoped ESLint, Prettier/Pint and a production Vite build are part of validation. The existing large-chunk build advisory is not a functional test failure.

Real Chrome verification used the main checkout at `http://127.0.0.1:8787` with an existing isolated synthetic fixture database. No production records, provider credentials or provider settings were changed.

- All eight Fleet sidebar destinations loaded without an error. See `browser-landings.json`.
- Reports opens the new library. The hero menu exposes the specialist reports, and Usage by house opens through it.
- Fleet's menu exposes vehicles, bookings, Compliance, trips, fuel, drivers, keys and handovers.
- The menu fits a measured 312 × 675 CSS-pixel viewport and scrolls internally; the document had no horizontal overflow. The temporary viewport override was reset.
- Compliance opens the existing vehicle evidence wizard. Missing evidence remains explicit and insurance remains profile context. The wizard was cancelled without a save.
- Compliance's Alerts entry opens the canonical queue. The two synthetic critical alerts remain two after applying the critical filter.
- Maps settings shows Google disabled and credentials absent. Configure provider opens the corrected four-step workflow. It was cancelled without a save or paid provider call.
- Browser console inspection returned no errors for these local checks. Screenshots contain synthetic records only.

The earlier local preview on port 8768 used an older database missing reporting tables. This was an environment mismatch, not evidence that the published Reports implementation was absent. It was left untouched.

## Remaining acceptance work

**The entire Fleet interface must not be labelled visually complete.** `page-inventory.json` records 285 registered Fleet routes and 50 titled Fleet source pages. Thirty-one files still reference a legacy hero family; this is a source count, not a count of 31 confirmed live defects. Some are inactive fallbacks, including the old map and asset-profile branches.

Reachable secondary areas needing a separate design/ownership assessment include Daily checks; booking list/details; fuel; devices; driver list/details; maintenance overview, schedules and checklists; inspections; keys; handovers; mileage; incidents; trips/playback; outings; client location/history; legacy journey records, pre-checks and medications; and vehicle alert configuration. The new menu keeps these destinations reachable but does not certify their presentation or every action.

Other limits:

- The wider repository's [existing main test run](https://github.com/Rory357/oblivionfindings/actions/runs/36496945800) failed before these audit corrections, at `c2838f86a`. Failures include Finance bill filters, Control Room, Catering, client/authentication and other non-Fleet suites. This audit does not certify all application-wide CI as green or silently change those modules.
- Live Google Maps/Places/Geocoding/Routes verification still needs the deployment owner's configured, restricted keys and enabled APIs/billing. Passing local setup tests is not a live-provider health check.
- Browser-menu 125% zoom was not independently established in this audit. Measured viewport dimensions are not represented as zoom proof.
- All eight landings and the named workflows were browser-checked; every populated, failed, export and revoked-permission state across all 285 routes was not exercised manually.
- A Git push and a local production build do not establish that the hosted `.com` deployment has completed. Deployment verification is reported separately.
- Frozen Compliance mockup versions and old scratch logs remain local preservation material. Unrelated eMAR audit files were not staged with this work. No unrelated working files were deleted.

`DESIGN.md` and `design_styles/*` remain read-only and unchanged. Authorization continues to use one organisation, approved sites, roles, canonical ownership and privacy rules.

## Secondary-page follow-up on the published audit

The follow-up starts from published main `3ffc4c1c4623f6219a3a1994b458a03c891637be`. [Secondary page assessment](secondary-page-assessment.md) traces all 50 titled page files to active controller rendering or an inactive fallback. It identifies 26 active pages still using a legacy hero, including the booking list, and does not count the inactive map or asset-profile fallback as live defects.

This follow-up corrects Daily Checks, booking details and vehicle alert configuration headers, repairs the booking list's mobile calendar and filtered empty state, and restores Home-rooted breadcrumbs on reachable secondary pages. Compliance counts and automatic WoF alerts now interpret date-only expiry fields on the Auckland calendar day, so an item due today is not marked expired at midnight. The Daily Checks alert chip only links to the queue for a user with queue permission. The [separate verification record](secondary-verification.json) lists exact local checks, browser states and external limits; it does not overwrite the earlier audit's 713-test result.
