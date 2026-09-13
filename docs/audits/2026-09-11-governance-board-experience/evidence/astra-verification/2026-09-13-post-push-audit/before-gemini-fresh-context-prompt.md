# Gemini fresh-session completion prompt — third return audit, UI first

You are Gemini 3.8 starting in a fresh context. **Implement and verify all remaining Governance work in C:\Users\steph\Herd\oblivionfindings.** Continue the existing implementation, preserve useful fixes and unrelated work, and finish the complete task. This is an implementation request, not a planning-only pass.

**Latest explicit user priority: do the UI/UX first, then continue with the rest of the audit fixes.** The user still sees excessive left navigation and says Rory's rules are missing from most pages. This changes the work order, not the scope or release standards. Complete and verify Phase 1 below, then continue directly into Phase 2 without treating the UI checkpoint as module completion.

The latest independent audit is **astra-third-return-review-2026-09-13.md**. Verdict: **useful progress, still not ready**. Reviewed HEAD: **86dee9068c01276c37840efc242ecd24fc1b4df0**, main, plus the incoming working tree. Obtain current identity before editing. Original acceptance remains **23 Failed, 4 Not tested, 1 Blocked**. Sixteen R-groups remain open. R05's specific old missing-minute-version defect is corrected; full A05 is unverified.

The previous "all resolved / 28 probes passing" summary is superseded. Independent full Governance tests: **281 passed/1 failed, 2,143 assertions**. Sites calendar: **14 passed/2 failed, 86 assertions**. Shared wizard/header UI: **15 passed**. Fresh build passes. TypeScript has three errors in today-retirement.test.tsx and no emitted Governance error. Narrow tests and collector exit 0 do not prove completion.

## Read the full contract

Read these actual files before implementation:

1. AGENTS.md, CLAUDE.md, DESIGN.md, the relevant design_styles guides, and docs/architecture/single-tenant-application.md.
2. Under docs/audits/2026-09-11-governance-board-experience:
   - **astra-third-return-review-2026-09-13.md** and astra-verification.md: current findings, evidence, verified improvements and limitations.
   - **navigation-workflow-decision-2026-09-13.md**: the user's approved member experience, including the later UI-first instruction.
   - audit.md, implementation-plan.md and implementation-tasks.md: the original full scope.
   - implementation-progress.md and acceptance-checklist.md: every **GOV-W01–W24 and GOV-A01–A28**. Historical Verified claims are not current acceptance.
   - evidence/governance-practice-research.md: existing supported-living governance research and D1/D2/D3 limits.
   - evidence/astra-verification/2026-09-13-third-return-review/, especially third-pass-probe-results.json, records-probe-results.json, browser observations, source identity and raw test results.

The previously requested main/push checkpoint was already completed as **527aff2ac76d467019b30da4513531aad0fdcbc7**. Do not repeat a broad commit or push. No new deployment, production vote/signature, real communication, live-data destruction, commit or push is requested here.

## Phase 1 — finish and verify the UI/UX first

Start here. Do not spend another implementation pass primarily on backend patches while leaving the member experience fragmented. Make the minimum supporting API/data corrections needed for real, truthful and authorized UI behavior; preserve existing privacy and business guards throughout.

### Simplify the actual ordinary-member navigation

Deliver **one Governance home** with **My Work and Next Meeting**, plus concise organisational assurance. Papers and actions open in context. Avoid requiring members to understand the module's internal registers.

The real board_member role currently receives **22 Governance links**, because isGovernanceAdmin includes **audit.view**, a permission ordinary members already have. Read access must not activate the administrative navigation. Validate with the actual seeded role, not a specially restricted test user.

The user did **not** approve "exactly four links" as a substitute for the chosen outcome. Keep the member navigation genuinely small and task-oriented, with secondary/history destinations where needed. Preserve authorized chair/secretary/committee administration and canonical URLs. Do not solve menu visibility by widening permissions or removing legitimate capabilities.

### Complete the continuous meeting workspace

Within one meeting context, let members prepare, read the pack and papers, declare conflicts, cast eligible votes, see receipts/results and complete follow-up. Preserve selected meeting, paper/revision, tab/filter, meaningful reading position and focus. Back/forward, refresh, authorized deep links and completion should restore their place.

The paper still opens at a separate resolution page. A return link and query parameter are useful partial work, but do not satisfy this requirement. Reuse the existing components and services within the workspace. Complete non-meeting My Work in context as well.

Keep working meeting-header section links. The latest browser audit confirmed those links now change sections correctly. Records must make historical meetings, minutes, decisions and documents findable; merely aliasing the document index is insufficient.

### Apply Rory's actual design contracts across the module

Inventory **every retained Governance page and dialog**, including member and administration surfaces. Apply the current DESIGN.md/design_styles rules using existing PageHeader, WizardShell, review/edit/success, focus, navigation, table/filter, status and other shared components.

The current search finds **56 Governance page files referencing PageHero**, and only one WizardShell file across Governance pages/components. These counts indicate unfinished coverage; use actual visual/behavioral review as acceptance. Do not declare the module consistent after replacing a couple of headers.

Do not edit DESIGN.md/design_styles, invent a substitute guide, or waive the rules. Verify desktop **1366×768 and 1920×1080**, light/dark, keyboard/focus return, 200% zoom and reduced motion. Keep existing feature parity and real persisted operations, not static mockups or fake successful actions.

### Reuse the real Sites calendar

Keep the **actual Sites SiteCalendar**, its established look/feel, and **Month, Week, Day, Agenda and Timeline** views. Preserve global/site/profile behavior and date/hour/committee creation seed. No copied calendar or new visual calendar system.

The current Governance calendar renders the shared component successfully. Complete the required context, recovery and regression verification rather than replacing it.

### Correct misleading UI contracts as part of this phase

- The home reports **12 critical risks above appetite**, yet its Risks tab says **All tracked risks are within appetite**. Data uses area **Risk Register**, while backend by_tab and frontend filtering match **Risks**. Use canonical kind keys and never infer a healthy state from a filtered/capped sample.
- **View all 58 priorities** opens personal My Work with **3** items. Use a complete authorized destination matching the displayed population/filter. Verify every kind and overflow beyond the ranked sample.
- Resolution index/detail now supply users, but Meetings/Show's NewResolutionDialog still omits them. Duplicate-name input falls back to proposer **user 1**, not intended owner **user 5**. Supply stable scoped-ID choices in every caller and validate eligible assignees server-side; never infer identity from names or silently substitute the proposer.
- Evaluation UI sends **Yes/No**, but the server rejects **Yes with 422**. Align the real typed payload contract and verify a successful legitimate submission.
- Records currently exposes denied document metadata. Home/Records navigation must preserve each record's permission and effective audience.
- Finish loading, empty, denied, unavailable, stale and retry states with truthful copy, correct counts and meaningful user recovery.

Prove this actual member journey in a disposable browser session:
**home → next meeting → pack/paper → conflict/vote → receipt/result → follow-up → same workspace**.
Show that members can finish the work without unrelated register jumps or losing their place. Verify non-meeting work and authorized administration too.

Update the full page/dialog inventory and the W/A ledgers with source/build identity, actual role, screenshots and completed interactions. Then continue immediately to Phase 2. UI verification is a checkpoint, not permission to stop or claim release readiness.

## Phase 2 — finish all remaining audit contracts

Preserve these verified improvements: completed-work privacy filtering, removal of attendance-based invitations, effective committee dates using appointed_at, frozen profile contents/opening electorate, new-voter exclusion, fixed-count calculation in VotingService, minute expected-version checks, public-file rejection, terminal completed-action guards, draft-policy denial, future-evidence completion rejection, settings prevalidation/atomicity, negative-threshold rejection, cross-hour reminder deduplication, kind normalization, shared UI fixes and working meeting header/return links.

Complete every remaining requirement, including these precise current failures:

1. **R01 — safe, meaningful verification.** Set isolation before Laravel bootstrap in every entry point and assert the resolved disposable DB. fixtures.ts still calls generic runLaravelJson, which bootstraps before injected isolation. Own config/cache/session/storage/mail/queue/assets/cleanup and reject missing/stale/mismatched state. Do not migrate/seed/drop through the normal application database. Replace heading/navigation smokes with completed workflows and persisted/denial assertions. Make collector outputs actual assertions; an arbitrary exception is not a passed business guard. Never overwrite prior independent evidence; create unique run directories.

2. **R02/R06 — exact record audience everywhere.** Records returns **200 with a denied document title** to an observer with governance.view=true and explicit documents.view=false; the direct document URL returns 403. Use one capability/effective audience for discovery, counts, search, history, children and files. Pack checks iterate a flat resolutions list, while the actual builder uses **content_sections.resolutions.items**. A private source paper yields a discoverable pack and **200 with private bytes**. Use typed versioned manifests, canonical source/revision/attachment identities and recipient-safe immutable editions; fail closed for missing/legacy sources. JSON string checks are not audience authorization. Test actual builder nesting, absent agenda flags, revocation, attachments, queued recipients and legitimate reader positives. Reconcile executive/performance creator/global-role shortcuts with the approved assigned audience; do not widen permission to simplify UI.

3. **R03/R08 — exact immutable authority.** A carried paper titled **New laundry appliances**, motion **That the Board approves the proposal as presented.**, still activates unrelated voting rules and approves a property strategy. An unbound **Annual budget increase** motion approves a threshold adjustment to **$106,000**. **Remove keyword/phrase/title/token heuristics as the authority mechanism.** Another deny list is not a fix. voting_profile_id describing rules used for a vote is not approval of that profile. Bind exact canonical subject id, immutable version, budget line, amount, direction and scope to the approving decision, atomically with correct single-use rules. Test legitimate varied wording and unrelated generic motions.

4. **R03/R04 — immutable decision basis and one calculator.** Parent meeting quorum_required changes an open vote from required **3 to 4** after 50→100 editing, even though the opening electorate remains four. The secondary rules calculator returns **2** for fixed-count **4**, while VotingService returns 4. Freeze every executable input and use one calculator; check current entitlement/access separately. Implement/verify controlled membership-change/cancel/reissue behavior. Test missing/stale requests, recusal, written unanimity, no-quorum, duplicate votes and simultaneous cast/conflict/close with persisted receipts/results.

5. **R05 — complete minute proof.** Keep mandatory version checks and UI version/hash. Verify review→approve→sign→archive, exact immutable content, full prior versions, correction lineage, wrong identities, duplicate replay and simultaneous editors. Do not reassert the corrected missing-version bug. A05 is unverified for the whole lifecycle.

6. **R07 — owned evidence and required action versions.** A path belonging to an inaccessible private action completes an unrelated evidence-required action and creates a receipt. Managed-file existence is not ownership/applicability. Nullable expected_version permits a stale HTTP request to overwrite progress after another save. Require canonical evidence relationships and versions across relevant mutations; verify atomic transitions, terminal states, replay ordering and overlapping requests.

7. **R09 — effective assigned policy versions.** An approved policy effective **1 January 2027** accepts attestation now. Require eligible assignment and the published/effective exact version. Preserve immutable receipts, correct denominators and meaningful new-version re-attestation. Keep draft denial.

8. **R10/R11 — complete truth and calendar recovery.** Finish all Phase 1 truth fixes through summaries/exports, unknown/partial/stale states and full authorized totals. Preserve transient same-context calendar retention and 401/403 clearing. Key last-successful data/availability to actual scope/range/committee; a failed changed-context request must not reuse old-context records. Verify delayed/racing requests, outage/retry, denied, all five views and creation seed. Two current Sites Aggregator tests at lines 147/172 lose credential/vendor reminders. Investigate against current contracts and concurrent vendor work without undoing unrelated work or suppressing tests; their cause is not yet assigned to Governance.

9. **R12/R13/R17 — finish remaining authoring/design/workflow proof.** Complete create/edit/publish field and attachment round-trips, server-side eligible owner IDs, concurrency, accessible review/success and focus return. Retain the Phase 1 one-home/one-workspace result and all authorized historical/admin capabilities.

10. **R14 — evaluation validity and effective membership.** Beyond the Yes/No mismatch, rating **5.9** saves as 5 and an inactive member can submit. Require all required question identities, typed answers and integer ranges without silent coercion; enforce effective eligible assignment/audience. Verify permitted real UI submissions and forbidden/closed/late attempts, immutable response versions and private projections.

11. **R15 — consistent evidence truth.** completeObligation rejects future evidence, but verify() on a future-dated missing file marks **verified=true and evidence_provided=true**. Derive validity consistently through upload/verify/complete/display using dates, canonical relation/ownership, bytes and applicable evidence kind. Do not invent blanket formal verification obligations; D3 controls actual applicability.

12. **R16 — recipients and failed delivery.** Escalation recipient **999999.5** persists. Require actual eligible integer identities. Reminder cache is claimed before send: a mocked first transport failure leaves an immediate retry sending **zero notifications**. Implement durable retry-safe delivery state/identity/window and audience rechecks, preserving successful-delivery deduplication across hours.

These findings are **not the entire scope**. Complete all original **W01–W24** and independently assess **A01–A28**, including supported-living assurance/provenance, retained administration, CEO/performance, documents and design/feature parity.

## Boundaries and completion evidence

- Single tenant, one organisation across sites. Roles, permissions, approved sites, canonical ownership and privacy are the boundary. No tenant product flows or fixtures; do not propagate/remove legacy tenant columns without a separately approved dependency audit.
- Preserve unrelated IT, My Day, vendor, HR, Finance, Roadmap and shared design work. No broad rewrite, permission widening, test weakening, fabricated completion or silent scope reduction. Report unrelated gates honestly.
- Use additive upgrade-safe migrations; verify fresh and existing-schema upgrades only on owned disposable data.
- D1 actual governing document/legal form/version, D2 approved restricted appointments/audiences and D3 applicable provider obligations remain external release gates. Research/synthetic fixtures are not approval. Keep defaults fail-closed/unactivated while completing independent engineering.
- **A25 is desktop accessibility/shared UI. A26 is security/concurrency/canonical boundaries.** They are not preservation checkboxes. A28 requires real representative-member comprehension; agent role-play cannot verify it.

Write meaningful regression tests that fail for the actual defect and pass after correction, including legitimate positive cases. Cover actual member/chair/secretary/appointed finance committee/CEO/observer roles, explicit denial, duplicate names, expiry/revocation, missing/stale input, retries and simultaneous writers. Assert persisted identity/version/amount/receipt and absence of denied titles/counts/bytes.

Run full Governance suites, affected canonical Finance/Roadmap tests, shared Sites regressions, TypeScript, fresh identified build and relevant shared UI tests. The failing attendance-only executive-access fixture expects the old insecure grant: replace it with proper explicit-invitation positive/negative tests, rather than restoring the bypass. Investigate the unrelated type errors/shared failures against current ownership and contracts.

Run real desktop browser journeys in the Phase 1 sizes/states and inspect actual file bytes/readable PDFs where required. Do not call navigation-only smokes workflow verification.

After each coherent slice, update implementation-progress.md and acceptance-checklist.md with changed files, actual commands/exits/assertions, source/build identity, browser role and unique evidence paths. Preserve all historical failures and 24/28 IDs. Never overwrite earlier independent raw outputs. Implemented is not Verified; a narrow green test cannot close a full criterion.

Finish with dispositions for every R01–R17, W01–W24 and A01–A28, actual validation and explicit external/unverified gates. **Continue after the UI phase until the remaining independently completable work is done.** Do not declare the module finished while reproducible failures or required implementation remain. Leave the files ready for the next independent audit.
