# Gemini fresh-context completion prompt — second return audit, 13 September 2026

You are Gemini 3.8 in a fresh context. Complete and verify the remaining Governance work in **C:\Users\steph\Herd\oblivionfindings**. Continue the existing implementation, preserving useful fixes and unrelated changes. This is an implementation request, not another planning-only pass.

The latest independent audit verdict is **Not ready, with useful progress**. Current baseline: HEAD 7fee576d3685188c0290c18eba250b0262253cb1 plus the existing working tree. Recheck identity before editing. Governance tests pass **282/2,156 assertions**, Sites tests pass **16/90**, and a fresh build passes. TypeScript has four non-Governance test errors; shared UI has **14 passing/1 failing** tests. None of that proves the adversarial cases or complete user journey. Current full acceptance is **23 Failed, 4 Not tested, 1 Blocked**.

## Read the current contract

Publication context: the user requested a Governance checkpoint on main and a normal push before this handoff. See publication-checkpoint-2026-09-13.md. The audit HEAD above is the historical review identity; obtain the current main/remote HEAD and working-tree state before starting. Unrelated uncommitted work remains and must be preserved.

Read these actual files before editing:
1. Repository AGENTS.md, CLAUDE.md, DESIGN.md, relevant design_styles guides and docs/architecture/single-tenant-application.md.
2. Under docs/audits/2026-09-11-governance-board-experience:
   - **astra-second-return-review-2026-09-13.md** and astra-verification.md: latest independent conclusions, improvements, precise reproductions and limits.
   - **navigation-workflow-decision-2026-09-13.md**: the user's approved outcome.
   - audit.md, implementation-plan.md, implementation-tasks.md: full original scope.
   - implementation-progress.md and acceptance-checklist.md: all GOV-W01–W24 and GOV-A01–A28. Earlier same-day Verified claims are historical.
   - evidence/governance-practice-research.md: D1/D2/D3 research and external authority limits.
   - evidence/astra-verification/2026-09-13-second-return-review: source identity, raw tests, second-pass-probe-results.json, pack-variant-results.json, browser observations and reproduction scripts.

Do not interpret a historical probe's name as an assertion. Read the actual payload and latest audit explanation. In particular, the old policy receipt now correctly reports version 1, and the old written-unanimity probe's default profile is false. The new rule-mutation case is the actual current failure.

## Required product outcome — already approved, do not ask again

Deliver **one Governance home for ordinary members**, containing **My Work and Next Meeting** with concise organisational assurance. Papers and actions open in context.

Deliver **one continuous meeting workspace** for pack reading, paper review, conflict declaration, eligible voting, receipts/results and follow-up. Preserve selected meeting, agenda/paper revision, tab/filter, reading position and focus where appropriate. Back/forward, refresh, safe deep links and completion should return members to the same meaningful context.

The current 21-link member navigation, new query strings, row highlight and separate paper page with a return link are only partial progress. Do not stop after hiding links or replacing headers. Actual chair/secretary/committee administration, historic records and canonical authorized URLs must remain usable.

Follow **Rory's current rules** across all retained Governance pages, dialogs and workflows using the existing PageHeader, WizardShell, review/success and other shared patterns. Do not edit DESIGN.md/design_styles or invent an override guide. Desktop scope remains 1366×768 and 1920×1080 with accessible keyboard behavior.

Reuse the **actual Sites SiteCalendar** for Governance, with its Month, Week, Day, Agenda and Timeline views and established look/feel. Keep global/site/profile behavior and seed context. No copied calendar or alternate calendar design.

## Work order and concrete acceptance

First repair the harness, privacy and decision integrity. Then finish the member journey and all remaining original tasks. Work in coherent slices, updating truthful evidence after each. Continue until all independently completable work is done.

1. **R01 — trustworthy verification.** The server guard improved, but fixtures.ts still continues on missing/bad state and setup bootstraps before full isolation. Fail closed in every entry point, assert the resolved disposable database, isolate cached config/storage/mail/session/cache/queue and own temporary assets/cleanup. Do not run the old E2E helper against the normal app. Replace heading-only smokes with completed workflows and assertion-driven failure paths.

2. **R02/R06 — exact audiences everywhere.** Active My Work and inspected CEO raw list/detail redaction are improved. Completed My Work and My Day still expose the private action title. Present attendance and expired committee membership still grant private access. A denied pack remains in visibleQuery; a manufactured historical/corrupt pack with a private paper but no confidential agenda flag downloads private bytes. Apply exact effective, assigned, non-recused record audience across discovery, counts, all task providers, children, files, notifications and exports. Attendance is not invitation. Build recipient-safe immutable pack editions and recheck every contained source/attachment, not just agenda flags. Preserve legitimate readers' permitted editions.

3. **R03/R04 — immutable decision basis.** Changing the active rule profile after opening changes the outcome; removing an opening member changes quorum 4/3 to 3/2. Freeze full executable rule/document version and opening electorate, apply the approved membership-change/cancel/reissue policy, and use one rules calculator. Keep rejection of new voters and fixed-count quorum. Current entitlement and access must still be checked.

4. **R03/R08 — exact authority, no keyword guesses.** A printer-paper resolution activates rules; a vehicle-capital decision approves bathroom equipment; a wellbeing proposal approves property strategy. Conversely an exact-ID legitimate catering adjustment is rejected by the deny list. **Remove keyword allow/deny lists as the authority mechanism.** Bind canonical subject ID, immutable version, amount/direction/line/scope and governing approval record, atomically. Add both positive legitimate cases and negative cases with varied wording. Do not add more special words merely to pass the supplied examples.

5. **R05 — preserve corrected minute concurrency and finish proof.** The old unseen-approval case now rejects and UI sends version/hash. Do not reintroduce it. Verify exact review→approval→sign→archive, full prior versions, correction lineage, wrong identities, stale edits and duplicate/racing requests. Reconcile nullable hash/service guard paths with the exact-content contract. A05 is Not tested for the full lifecycle, not proof that the old bug remains.

6. **R07 — canonical action evidence and lifecycle.** Empty/missing evidence and stale supplied versions now reject, but public robots.txt completes an evidence-required action; completed actions can become blocked while retaining completion receipts. Require owned applicable evidence records with valid bytes and required version checks on every mutation, terminal-state guards and replay-safe receipts.

7. **R09 — effective policy assignments.** Keep approved-content immutability, view-level reader access and actual receipt version. Draft policies currently accept attestations. Require the correct published/effective version and eligible assignment; preserve immutable versioned receipts and meaningful denominators/re-attestation.

8. **R10 — consistent home truth.** Finance unavailable display and overdue query improved. Overdue filter still says All Statuses; 58 open priorities versus All 15 and View all 15 linking only to Actions misrepresents the list. Use audience-safe totals, explicitly labeled samples and matching cross-kind/filter destinations. Test unknown, partial and stale source propagation through summaries and exports.

9. **R11 — repair calendar recovery regression.** Current shared SiteCalendar clears events for all errors. Clear restricted content on authorization loss; retain appropriate last successful data for a transient failure in the same authorized context, labeled stale. Do not reuse data across changed scope/range/filter. Test race ordering, delayed responses, forbidden, outage/retry, cancelled/implemented statuses and creation date/hour/committee seed. Retain actual shared component reuse.

10. **R12 — stable owner IDs and complete authoring.** Create/edit now share the wizard and dirty/success components. Callers still omit users, so free-text names select the first matching user. Intended user 5 becomes user 3 when both names match. Supply scoped stable-ID choices, validate eligibility and round-trip all fields/attachments/owners. Never infer identity from a name. Verify saved success, review/edit links and focus return.

11. **R13/R17 — finish Rory and continuous navigation.** Fifty-eight Governance files still use PageHero and only one uses WizardShell. Audit every retained page against the current guides. The new meeting header “View resolutions” only changes #tab-resolutions while Agenda stays selected. Fix actual section behavior and implement the complete approved member workflow, with context restoration and accurate receipts/counts, not merely more route parameters.

12. **R14 — evaluations.** Keep persisted dates and closed/late rejection. Validate every required typed answer: null rating and array yes/no currently save. Define/enforce the supported audience/assignment contract server-side, including effective membership, immutable response versions and privacy.

13. **R15 — compliance evidence validity.** Missing bytes now reject. Real evidence whose valid_from is in the future still completes an obligation. Validate both date bounds, canonical ownership/relation, evidence kind and applicable verification rules; derive evidence_provided consistently.

14. **R16 — settings and reminders.** A mixed valid/invalid settings request returns 422 after saving the first value; -999 threshold saves. Validate the whole payload and allowed ranges/recipients before atomic writes. Reminder keys change hourly, so 10:59 and 11:01 both send inside the claimed four-hour window. Use retry-safe durable delivery identity/window, current audience checks and failure recovery.

These findings are not the entire scope: complete every original W01–W24 requirement and independently assess every A01–A28 criterion, including supported-living assurance and all retained management workflows.

## Boundaries and validation

- Single-tenant organisation across sites: permissions, approved sites, canonical ownership and privacy are the boundary. No tenant product model, selectors or fixtures. Do not propagate/remove legacy tenant columns without a separately approved audit.
- Preserve unrelated IT, My Day, HR, shared design and other existing work. No broad rewrite, permission widening, fake completion, test weakening or silent scope reduction. Inspect the unrelated type errors/shared UI mismatch against current ownership and Rory contracts; report any remaining gate honestly.
- Use additive upgrade-safe migrations. Verify fresh and existing-schema upgrade paths without touching live data.
- D1 actual legal form/governing document/version, D2 real approved restricted appointments/audiences and D3 provider applicability remain external release gates. Research and synthetic fixtures are not organisational approval. Implement fail-closed/unactivated defaults while finishing independent work.
- A28 needs actual representative-member comprehension. Do not claim agent role-play as user acceptance.
- No deployment, production signatures/votes, real communications, destructive live data, commits or pushes unless separately requested.

Create meaningful regression tests that fail before the correction and pass after it. Cover real ordinary member/chair/secretary/appointed finance committee/CEO/observer roles, duplicate names, expiry/revocation, missing/stale input, retries and simultaneous writers.

Run Governance suites, affected canonical Finance/Roadmap tests, shared Sites regressions, types, fresh identified build and relevant shared UI tests. Run actual desktop browser journeys in light/dark, keyboard/focus return, 200% zoom and reduced motion. Verify persisted identities, versions, amounts, receipts, privacy absence and actual download bytes, plus loading/empty/denied/failure/retry states.

The member must complete **home→next meeting→pack/paper→conflict/vote→receipt/result→follow-up→same workspace**, without unrelated register jumps. Verify non-meeting My Work and administration too.

Update implementation-progress.md and acceptance-checklist.md after each slice with actual changed files, commands/results, source/build identity, browser role and evidence. Preserve historical failures and all 24/28 IDs. “Implemented” is not “Verified”; a green narrower test cannot close a full criterion.

Finish with dispositions for R01–R17, all W01–W24/A01–A28, actual validation and remaining external/unverified gates. Do not claim completion while reproducible failures or independently completable required work remain. Prepare the files for the next independent audit.
