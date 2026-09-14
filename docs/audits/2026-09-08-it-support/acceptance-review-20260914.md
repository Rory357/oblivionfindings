# IT & Support — W27 acceptance review (14 September 2026)

Reviewed HEAD: `a2c935ee1` on `main` (deployed via webhook). Reviewer: the
continuation session in worktree `strange-bhaskara-ae2843`. Method: every
E01–E23 scenario and release-gate line is graded against **actual evidence on
current source** — passing automated suites, code inspection, and the dated
browser evidence already in `evidence/`. Grades are deliberately conservative:

- **Verified (tests)** — the behaviour is implemented and its invariants are
  encoded in passing suites on current source. Browser journey still owed
  where the scenario names one.
- **Browser-owed** — implemented and test-green; the scenario's browser
  journey has not been walked on the current build (this session was
  instructed to verify tests-only until now).
- **Blocked (external)** — cannot pass without something outside the
  application: approved provider accounts, infrastructure custody, live
  collectors. Recorded as a gate, never relabelled complete.
- **Partial** — implemented slices with a named remaining gap.

## E01–E23

| # | Scenario | Grade | Evidence / remaining |
|---|---|---|---|
| E01 | Request and recovery | Verified (tests) + Browser-owed | Draft persistence, site selection, retry-same-identity: `ItTicketWorkspaceTest`, draft suites, `w02-browser-recovery.md` (earlier build). Narrow-width browser journey owed on current build. |
| E02 | Accountable triage | Verified (tests) | Shared routing for browser/email/system intake, fallback queue, override provenance: routing decision suites; dry run added 14 Sep (`ItRoutingDryRunTest`). |
| E03 | SLA truth | Partial | Clocks, coverage/unmeasured states and freshness verdicts are test-green; **holiday/DST reprioritisation fixtures across all surfaces** are not a single reconciled test yet. Watchdog staleness is visible in the Operations audit. |
| E04 | Work navigation | Browser-owed | Backend list contracts test-green; 390 px, keyboard and zoom journeys owed on current build. |
| E05 | Conversation | Verified (tests) + Browser-owed | Audience, next-responsible, quarantine, delivery states: interaction/notification suites; copied-URL denial in attachment suites. |
| E06 | Collision and draft protection | Verified (tests) | Version conflicts, draft recovery, revoked-access restore: version/draft suites (`ItTicketVersionConflict` middleware, `ticket-version-conflict` UI tests). |
| E07 | Required work and approval | Verified (tests) | Task dependencies/evidence, approval cover/expiry: `ItWorkTaskTest`, approval suites; provisioning approval routing added 14 Sep by the W15 session (`ItProvisioningApprovalRoutingTest`). |
| E08 | Resolution and canonical history | Verified (tests) | Resolve/confirm/CSAT/auto-close/merge: W09 suites; resolution-to-knowledge with revision citation: revision workspace suite. |
| E09 | SSO administration | Partial + Blocked (external) | Persistence contract local-verified (W10); **real provider sign-in/denial** needs approved provider accounts. |
| E10 | Microsoft and Gmail intake | Blocked (external) | Poll/recovery/duplicate logic test-green (`ItMailboxPollTest`, W11 suites); the provider-scenario matrix needs approved non-production mailboxes. |
| E11 | Outbound delivery | Blocked (external) | Delivery ledger, retry and identity local-verified; live recipient verification needs provider accounts. |
| E12 | Service identities | Verified (tests) | Issue/rotate/revoke/replay and safe diagnostics: W13 suites. |
| E13 | Automatic technical work | Verified (tests) | Monitoring/Fleet episodes, replay, recovery: W14 suites. Proactive cases beyond supported collectors remain gated on source capability (no certificate register exists). |
| E14 | Catalogue and provisioning | Verified (tests) + Browser-owed | Versioned catalogue, staged attachments, lifecycle, approval routing: `ItServiceCatalogTest`, `ItProvisioningLifecycleAcceptanceTest`, W15 closure suites. Browser walk of the approvals inbox owed (handed over 14 Sep). |
| E15 | Specialised lifecycles | Browser-owed | Problems/Changes/Major Incidents services test-green; full lifecycle browser walk owed. |
| E16 | Reports and improvement | Partial | Reports/export sanitation test-green; **known-dataset CSV/drilldown reconciliation** not yet a single encoded fixture. |
| E17 | Templates and automation | Verified (tests) | Templates with placeholder blocking, macros with previews and guard-routed application, routing dry run: `ItReplyTemplateTest`, `ItTicketMacroTest`, `ItRoutingDryRunTest` (14 Sep). Rule *execution* engine (precedence/rate limits) is D02 scope, not built. |
| E18 | Effort, dispatch, continuity | Verified (tests) + Browser-owed | Timer, bookings, corrections: ticket-workflow release suites (`b30465bff`). Handover acknowledgement flow is the next-action/booking data; a dedicated handover surface is not built. |
| E19 | Scheduled and proactive work | Verified (tests) | Recurrence idempotency, bounded catch-up, exceptions/end dates, failure tasks, template review reminders: `ItRecurrencePlanTest`, provider cases (14 Sep). Vendor renewals: `VendorCommercialWorkflowTest`. |
| E20 | Knowledge and documentation | Verified (tests) + Browser-owed | 74-test Knowledge surface + revision workspace + retention (`ItKnowledgeUploadRetentionTest`) green; old-URL redirects and search scoping encoded. Browser walk on current build owed. |
| E21 | Shared vault | Verified (tests) + Browser-owed | `SharedCredentialSecurityTest` (step-up, audited reveal/copy, rotation vs key maintenance) green; earlier browser evidence `vendor-vault-browser-20260213.md`; current-build walk owed. |
| E22 | AI foundation only | Verified (tests) | Disabled contract with no execution path, descriptors-only context, audience projection, config flag never enables: `ItAssistContractTest`; four surfaces with fixtures: panel vitest. Adapter checklist documented. |
| E23 | Recovery and final regression | Partial + Blocked (external) | Runbook and drills documented (`docs/it-support-operations-runbook.md`); restore/replay drills need an isolated environment and custody decisions. Restricted-role browser regression owed. |

## Release gate

| Gate line | State |
|---|---|
| W00–W27 Verified with dated evidence | **Not met** — automated evidence is broad and green; browser-owed and externally blocked items above remain. |
| F01–F16 / A01–D08 closed; D05 only as foundation | F02/F03/F04/F05/F06/F07/F09–F16 have implemented, test-green corrections; F01 root cause remains unproven (not reproduced since W00); F08 (390 px) browser-owed. D05 closed as foundation only — no live AI claim. |
| Every visible control has a working authorized outcome | Test-green for new controls; browser sweep owed to confirm no dead control on the current build. |
| Provider, permission, recurrence, recovery verification | Recurrence and permission (grant migrations) done; provider and recovery are external gates. |
| Migrations reconciled, runbooks reviewable | Migrations ship with every slice; runbook at `docs/it-support-operations-runbook.md`. |
| Rory/protected design files unchanged | Confirmed by git that **no commit from this implementation session** touches `DESIGN.md`, `design_styles/` or the shared page header. Four main-line commits from other programmes did (`ada9be439` calendar, `e3e8d6694` operations header, `9ed17cfef` page-hero merge, `871813c5d` Governance hubs); they are outside this programme's authority and should be reconciled against `evidence/implementation-design-baseline.json` by the design owner. |
| Final implementation report | This document plus the CURRENT entries in `implementation-progress.md`. |

## Residual risks and prerequisites

1. **Browser acceptance debt** (W15, W21–W25, narrow-width journeys) — the next
   session's job; all backend contracts are green so this is verification, not
   construction.
2. **Provider accounts** for E09–E11 — cannot be simulated honestly.
3. **Recovery drills and custody** (E23) — infrastructure responsibility.
4. **Suite hygiene** — a handful of order-dependent backend cases fail only in
   whole-suite runs (pass isolated); CI shards mask this. Worth a dedicated pass.
5. **E03/E16 reconciliation fixtures** — the behaviours exist; a single
   known-dataset fixture spanning header/list/report/export would close them.

Local readiness does not imply production acceptance; the webhook deploy of
`a2c935ee1` is the current live build.
