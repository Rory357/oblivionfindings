# People Locations — implementation and audit closure

14 findings addressed in the local implementation candidate; Main integration pending.

Branch: `codex/ops-pl01-implementation`. Integrated base: `926b4981b0289da08a20baca0995117fb53e413e`. Source manifest: `8fd11b434ae835f8aa7c21f490460d560fc43f749faa745d79a1097c935cd977`.

Local preview: http://127.0.0.1:8776/operations/people-locations/analytics?selected=c1

## Changes and evidence

### PL-01 — Report correctness

UTC-normalized report bounds now agree across journeys, position and telemetry queries. Spring (23h), autumn (25h) and ordinary (24h) boundary cases all pass; the overnight/early passenger case also passes.

Verification: audit-fix-dst.txt; audit-fix-final-backend.txt

### PL-02 — Access contract

Retained history has its own people_locations.history.view capability. Map and Settings omit history; preview/export require fresh history authority. Operational roles are not auto-granted either new capability.

Verification: PeopleLocationsTest: separate map/history/export; authority rechecks

### PL-03 — Performance

Fresh authority graphs are batched per request, before and after observations, and candidate Site filtering occurs before source reads. Six people: 94 queries (previously 386); 250 people: 869 queries, 1.296s. Five simultaneous 250-person reads: 1.308–1.343s each. No cross-request authority cache.

Verification: audit-fix-performance.json; audit-fix-concurrency.json; consent-revocation regression

### PL-04 — Selection and search

The selected identity remains visible and searchable outside the current cohort. An explicit banner explains whose reports are open; selection can be cleared. Searching Noah while Maia is selected no longer blanks Maia’s picker.

Verification: browser-closure.json; selected-identity browser verification

### PL-05 — Alert filters

Response status is visible in the filter controls and active-filter line, with clear actions and a 0-of-1 empty state. Clearing acknowledged restores the original open response.

Verification: browser-closure.json

### PL-06 — Export completeness

Journey/authority/day intersections are applied before the bounded source reads. Saturation is disclosed even when invalid rows are discarded. CSV includes both streams, effective UTC window, generation and partial metadata; preview, PDF and HTML use the same scoped report.

Verification: 510-row early/overnight journey regression; outing-report.pdf; pdf-validation.json

### PL-07 — Export recovery

Report work is abortable and timed out; navigation/cancel cannot deliver a late file. HTTP redirects, wrong MIME and failed authority are rejected. The export reason survives retryable failure.

Verification: 16 frontend tests including four report-lifecycle tests; actual browser PDF download

### PL-08 — Rory's design rules

Person, source, journey and Site record choices use shared searchable controls. Export/calculation dialogs use the 720px bounded popup structure and shared date picker. Cohort chips have plain-language labels.

Verification: fixed-outing-export-1280.png; fixed-analytics-report-entry-1440.png

### PL-09 — Settings

Personal population, Site, Cards/List and boundary defaults now have review/save/cancel, revision-conflict protection and safe handling of unavailable Sites. Save and reload were exercised, and the reviewer’s Cards default was restored. Source consent/provider ownership stays canonical.

Verification: preferences backend regression; browser-closure.json

### PL-10 — Maps and integration

The isolated candidate was fast-forwarded to current Main 926b4981 before applying fixes. Shared permitted Site/house geometry comes from BoundaryService, excludes retired/personal/asset records and never enables monitoring. Current passenger journeys retain Transport ownership.

Verification: shared-boundary retirement/nonactivation regression; current Transport browser journey

### PL-11 — Control Room usability

Alerts show canonical severity, owner, due time and overdue state. Selected-person map detail shows the original readable response. The original PL-PREVIEW-01 opens in Control Room and closes back to Maia.

Verification: independent Control Room denial regression; browser-closure.json

### PL-12 — History and report UX

History includes a recorded-position map, interactive chronological observation list, source samples, exact day/journey preview and direct outing export. Analytics opens the report dialog with the selected person/source/day; the client-profile entry was verified.

Verification: browser-closure.json; fixed-analytics-report-entry-1440.png

### PL-13 — Map continuity

Map markers retain stable identities across refresh; safe DOM tooltips and keyboard context menus remain. Camera state survives Inertia view/selection changes while the keyed evidence view rechecks access. Browser zoom 17 remained 17 after Noah-to-Maia selection and refresh, with selected marker focus restored.

Verification: browser-closure.json; existing privacy and late-response frontend tests

### PL-14 — Useful analytics

Analytics now has separate Population snapshot and Person history modes, position-age and battery-age bands, accurate units/unknown denominators and exact people drills. The 1–24-hour position cohort opens its four matching people. No synthetic response trend was invented.

Verification: age-band model regression; browser-closure.json

## Additional browser finding

The browser-confirmed account menu Settings link now targets /settings/profile instead of the missing /profile route.

## Permission matrix

- Current map: existing telemetry/client Location authority and approved Site scope.
- History and report preview: current map/source authority plus `people_locations.history.view`.
- Export: history authority plus `people_locations.export`, recorded reason, durable audit and final permission/consent/assignment/journey revalidation.
- Defaults affect personal display only. No operational role grants were added.

## Validation

- Final focused backend run: 26 tests / 173 assertions passed. Additional day-boundary dataset: 3 cases / 21 assertions passed (spring, autumn, ordinary day).
- Broader run: 48 passed; four stale consent fixtures were corrected and their four tests pass in the final focused run. No product authorization was weakened.
- Frontend: 16 tests passed. TypeScript, focused ESLint, production build and whitespace checks passed. Existing large-bundle warnings remain.
- One-page day/outing PDFs inspected. A 38-page synthetic PDF retained all 500 positions and 500 device samples; all 38 pages have a header/footer and text. All pages were rendered for visual review.
- Frozen v5: 41 files, zero changes; digest `10b27c27220c1be0ca122464f2f4e2ca8e92036a7dfed32b609574f96fb15b7d`. Protected guides are unchanged relative to the integrated Main base.
- Local performance: 94 queries for six people; 869 / 1.296s for 250 people. Five simultaneous 250-person reads took 1.308–1.343s. Budgets: ≤1000 queries, <2s single, <5s concurrent. Synthetic fixture transactions rolled back.

## Scope and rollout limits

- Main and sibling checkouts were not changed; no publication or production activation was performed.
- New history/export permissions are unassigned to operational roles. Deployment must use the approved role matrix; client/site/consent checks still apply independently.
- Non-vehicle outings need a canonical Transport-owned source before this page can report them. Shared boundary overlays are reference geometry and do not activate personal monitoring.
- Performance evidence is local synthetic service-level measurement, not production HTTP/end-to-end load certification. Larger populations and deployment infrastructure need their own budget.
- Normal authenticated Chrome preview is available. The in-app browser has separate authentication. PDF is untagged; HTML remains the readable alternative. Genuine browser zoom and full accessibility certification were not completed.
- Some browser full-page captures had display-scaling artifacts and were excluded from final proof. DOM overflow checks and ordinary viewport captures were used.

The original audit is preserved in `audit-2026-09-27.md` and `audit-original-findings.json`. `audit-findings.json` now pairs each original finding with its implementation and verification.
