# W06 — Setup desktop header, registers and entity wizards

Status: **Implemented; focused component checks passed. Build3 visual/partial wizard browser checks reported by root; actual stale-review transport defect fixed in source and awaiting Build5 verification.** This evidence covers the bounded Setup slice only. It does not mark W06, F11, E08 or the release gate complete.

## Scope and design

- Applied the repository single-organisation/approved-Site boundary. No new tenant context, provider call, database migration or operational assignment.
- Read DESIGN.md and the page-header, popup and list guides before this slice. Protected design sources remain unchanged.
- User's later desktop-web-only instruction governs this work. No mobile flow or browser resizing was added.
- One canonical PageHeader, Home-rooted breadcrumbs, connected PageHeaderRail/Find, scoped current-section search, availability/attention filters, Cards/Table choice and URL-driven tab/filter/layout restoration.
- Four linked meters show actual supplied scoped active-team, active-queue, queue-gap and active-service counts. Each navigates to the filtered list containing that count. No mocked trends, SLA-clear celebration or fabricated readiness score.
- Team, Queue and Service lists use EntityCard/EntityTable, approved cells, ListCaption and one current-record action array for kebab/context Edit. Queue keys, operational gaps, owner/cover, counts and existing rule chips are retained.
- Co-located Team/Service WizardShell shares add/edit identity, accountability and review steps; prefilled fields, approved categorical TilePicker choices, deliberate save, server-error focus, dirty discard confirmation, pending cancel-wait, acknowledgement and success pane.
- Updates send the original configuration_version plus only changed domain fields. Creates omit that version. Root supplied canonical Team/Service hashes, mandatory update validation and locking.
- Stale/unknown update recovery explicitly reads current authorised setup, validates the JSON/record/version contract, presents saved vs retained values, requires a separate adopt action, and requires another explicit Save. HTML/session expiry, malformed or missing rows remain recoverable; no automatic save with a fresh token.
- Queue now adds a fourth Review step and an explicit success pane. Existing W03 rules/eligibility/dirty-field/version/recovery contracts remain intact. Distinct Continue/Save nodes and native early-form-submit guard preserve the confirmed root browser fix; entering Review does not save.

## Files

- resources/js/pages/it/setup/index.tsx
- resources/js/pages/it/setup/_types.ts
- resources/js/pages/it/setup/_header.tsx
- resources/js/pages/it/setup/_registers.tsx
- resources/js/pages/it/setup/_dialogs.tsx
- resources/js/pages/it/setup/queue-routing.test.tsx
- resources/js/pages/it/setup/setup-record-wizard.test.tsx
- resources/js/pages/it/setup/setup-workspace.test.tsx
- resources/js/components/it/__tests__/it-module-navigation.test.tsx (canonical header/rail labels and real URL navigation mock)
- tests/Feature/It/ItServiceManagementSetupTest.php (only first navigation case updated to existing W05 canonical Work/Knowledge/Reports links; all other current edits/tests owned by root)

## Actual checks

- Initial queue run: 2 passed, 8 failed because tests selected the removed plain Edit button. Updated selectors to the canonical card activation; this was a test-adapter mismatch.
- Initial combined wizard batch: 9 passed, 8 failed; seven new-wizard failures came from an unawaited asynchronous Inertia acknowledgement test callback and its leaked test scope, plus one queue conflict still returning to the old final-step index. Awaited callbacks and retained the new Review step for version conflicts.
- Second wizard batch: 16 passed, 1 failed from an ambiguous Review test selector; fixed by selecting the wizard rail.
- First complete UI group: 21 passed, 6 failed from legacy title/tab expectations, a nonreactive local-tab test mock and case-sensitive meter names. Updated those tests to the actual URL-driven canonical contracts.
- Fourth UI group: **27 passed / 4 files, 9.44s, exit 0**, w06-setup-ui-fourth.txt.
- Final UI group: **27 passed / 4 files, 9.05s, exit 0**, w06-setup-ui-final.txt. Command:
  node_modules/.bin/vitest.cmd run resources/js/pages/it/setup/queue-routing.test.tsx resources/js/pages/it/setup/setup-record-wizard.test.tsx resources/js/pages/it/setup/setup-workspace.test.tsx resources/js/components/it/__tests__/it-module-navigation.test.tsx
- Focused ESLint all Setup sources/tests and shared navigation test: **exit 0**, w06-setup-lint-final.txt.
- Prettier applied only the listed implementation/test files. Scoped git diff --check: **exit 0**.
- Final independent full TypeScript run: **exit 0**, w06-setup-types-final.txt (no diagnostics). Earlier full run had no Setup diagnostics and one concurrent W10 test optional-callback diagnostic, reported to its owner and fixed. Root also reported full tsc exit 0 before the coordinated build.
- Root separately reported Team/Service backend group **14 passed / 170 assertions / 270.29s**, wrapper exit 0, including version/pivot-change/stale-permission/audit-rollback cases. This agent did not rerun or claim ownership of that backend group.
- No working database reset/migration, provider access or real communication occurred in this slice.

Post-build follow-ups: contradictory error+success acknowledgement was proved by one failing focused test, then fixed by explicitly rejecting the error; zero-total meter denominators now show a count and no-record text; query q is URL-bound through layout/filter changes and Back, stale debounces are cancelled, and global meters intentionally clear search; stale comparison explains changed-field-only application. Removed the nested main landmark found in root's actual browser DOM. Final follow-up group: **30 passed / 4 files, 9.14s, exit 0**, w06-setup-followup-ui-final.txt. Full tsc and focused ESLint both **exit 0**, w06-setup-followup-types.txt and w06-setup-followup-lint.txt. Scoped diff check remains clean. Source frozen again for Build4.

Actual Build3 two-editor Team verification subsequently proved stale rejection and draft retention, but **review failed**: Team/Service requested the wrong Inertia component and both Team/Service and Queue omitted the current asset version. Earlier component mocks did not exercise this middleware contract. Root added an authenticated narrow JSON seam reusing the exact scoped Team/Queue/Service projections: GET /it/setup?review_resource=teams|queues|services with Accept JSON and no X-Inertia, returning {resource,records}, no-store/private. Both review clients now use it and strictly check resource, array and record; a stale built page no longer needs an asset reload to review. An empty queue-create lookup keeps uncertainty and cannot unlock another create. Final **32 passed / 4 files, 8.62s, exit0**, w06-setup-json-review-verified-ui.txt; focused lint **exit0**, w06-setup-json-review-lint.txt. Full TypeScript rerun **exit0**, w06-setup-json-review-final-types.txt. The preceding32-case run had one ambiguous rail test selector, corrected; no application source change for that failure. Root reports canonical backend review tests **10 passed /88 assertions /175.20s**, isolated wrapperexit0, w06-setup-review-transport-tests.txt (all3 resources/current access denial). Build5 browser recovery remains required.

## Precise browser gate / remaining work

Root reported Build3 desktop visual confirmation of the canonical Setup header/registers at 1440×900 and Team empty-name validation focus, Continue to Accountability without POST, Escape/discard confirmation, Cancel retaining exact identity/description, Back retaining and explicit Discard closing. Build3 assets app-CVn9oyuP.js, manifest 8340e15e1ab3f83430c7a19c0019198885ad310aca4725011ba92afdfe9d7572. These are root's actual browser observations; follow-up source awaits Build4 and does not inherit browser verification automatically.

1. Desktop Setup header/card/table anatomy, connected rail, section Find and keyboard focus; meter count -> exact filtered list, search, Cards/Table, browser Back.
2. Existing Team and Service prefill visibly correct; dirty Cancel preserves until explicit discard; role/category controls usable by keyboard.
3. New Team/Service and Queue: Continue and native Enter only advance; Review must be visible before an explicit Save creates anything; acknowledgement -> success -> Done.
4. Two editors: original token denied after a concurrent change; draft retained; explicit authorised review/adopt causes no write; subsequent Save applies only deliberately changed fields.
5. Failure/HTML session expiry/cancel-wait: no false success, no duplicate implicit retry, draft remains visible for review.
6. Queue approved Sites, manager/cover eligibility and configuration gap visibility still match canonical W03 policy.

All identified post-freeze follow-ups above were implemented only after root reported Build3 complete. No application source mutation occurred during that build.

Uncertain Team/Service creates have no Setup command receipt and cannot prove ownership of an existing same-name/key record. UI retains the draft, offers current-record review and blocks automatic create retry. Manual matching-record review is the explicit recovery path; do not label exactly-once Setup creation verified.

## Resumption

W05 list/navigation UI source remains frozen and has separate evidence. Temporary Knowledge/Reports redirects remain compatibility adapters; the desk-rail reduction criterion is explicitly pending dedicated W17/W21 replacements, reaffirmed by root. Continue Setup acceptance from Build5/browser gate, not a fresh audit. Root owns ticket show/header/thread, Team/Service backend and the main progress ledger. W01 agent owns durable ticket-draft backend; W00 owns W10 SSO. This agent has begun NEW unreferenced draft contract source, then reusable hook/components, coordinating the current W01 backend contract. No draft feature is imported or activated yet. Production AI and ticket draft retention activation remain disabled/unset.
