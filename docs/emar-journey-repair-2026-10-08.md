# eMAR journey repair — 8 October 2026

Scope: resolve the 28 entries in the four-reviewer journey audit (25 defects, two improvements and one runtime hypothesis), then independently review the combined implementation. Desktop web only; follow DESIGN.md. Single organisation, approved sites, canonical record ownership, role/permission and direct-object checks.

Original audit baseline: main `56856f069a42536c2692a007a7a141d61bd2bd78`. Integration was fast-forwarded to main `bc22a918e` before final validation. Work branch: `codex/emar-journey-fixes-20261008`, in the existing eMAR integration checkout. No changes to production data or the independent Workforce project.

## Ownership

- Root: UI, navigation, dose recovery, application entry points and browser verification.
- Allergy backend agent: 01, 23, 24 — severe allergy enforcement, clinical projections and canonical API commands.
- Connected backend agent: 09, 11, 12, 18, 20, 26 — record-specific destinations, report sources, reconciliation, recommendations and round printing.
- Application backend agent: 07, 08, 15, 16, 19, 21, 27 — scoped work filters, return context, calendar/handover links, round scope and legacy medical destinations.

## Acceptance ledger

Every original finding has an implementation and regression coverage. Local UI and browser gates passed. Final release acceptance requires fresh GitHub checks, including the three recording-status integration cases whose fixture correction is awaiting CI; implemented does not mean deployed.

| IDs | Required outcome | State |
| --- | --- | --- |
| 01, 23, 24 | Canonical allergy severity/review/absence meaning agrees across safety checks, API and clinical summaries | Implemented; canonical commands, projections, severe-allergy and stale-write regression coverage |
| 02, 28 | Uncertain recording preserves recovery identity; truthful messages; header recording refreshes chart | Implemented; same request UUID, frozen original attempt, actor isolation, fresh witness PIN, read-only receipt recovery, shared recording event; 29 recorder cases pass |
| 03, 04, 05, 06, 27 | Client allergy/medical and eMAR actions reach the promised person-specific task | Implemented; exact Medical/allergy destination, canonical order route, scoped chart, working Medical editors; browser verified |
| 07, 08 | Person, date, house and safe originating location survive navigation | Implemented; allowlisted internal return links, NZ chart dates, restored calendar and profile filters; browser round-trip verified |
| 09, 18, 26 | Transfers, recommendations and accepted requests open their exact next record/action | Implemented; authorised exact selections independent of page caps, explicit accepted-order link, recommendation action selection |
| 10, 11, 12, 13 | Stock, destruction, audit and export links keep source identity, date and scope | Implemented; server-authoritative accessible source links, exact pharmacy/controlled/destruction records, pack focus and export destination |
| 14, 15, 16, 17 | Visible entry points agree with destination permission and record access | Implemented; current client/site and controlled-medicine gates, read-only chart access, gated calendar and handover destinations |
| 19, 20, 21 | Urgent meters, selected-round print and round navigation preserve their actual scope | Implemented; actual due/overdue filters, exact round PDF scope, selected house retained |
| 22, 25 | Desktop action buttons, right-click and keyboard expose the same permitted actions | Implemented; accessible round action button/context menu and connected-care table action parity |

## Findings discovered during implementation and independent re-audit

- Medical forms previously accepted updates without the same Medical read authority and could overwrite unseen data. Direct routes and broad profile edits now require the correct client permissions; restricted edit payloads omit clinical fields and the UI omits those editor steps.
- Profile updates and canonical allergy commands now share row locking so a stale profile save cannot overwrite newer allergy evidence.
- Medical saves use transactional timeline updates and truthful completion feedback. A subsequent notification failure does not turn a committed save into a false failure/retry.
- Medical notifications and later reminders re-check current person/site Medical access before delivery, including forced delivery and older pending notifications.
- Client/profile/shift safety summaries now read canonical allergy entries, severity and review evidence. Hidden Medical data is withheld; an unreviewed empty list does not become “No known allergies”. All four production projection callers were inspected, and shift/risk callers now use explicit Medical and risk-section gates.
- Calendar expiry entries use live positive stock packs after pack tracking starts, excluding quarantined/depleted packs and obsolete legacy expiry dates. Exact pack links preserve medicine, person and house.
- Recovery now checks the original recording receipt without submitting another dose, even when the original PRN/order is no longer eligible. A changed order cannot silently relabel or replace the retained attempt. Witness PINs are removed from retained drafts and must be re-entered.
- The profile header now opens the same medication picker and recorder as the chart; successful recording refreshes the current person's visible chart.

## Independent review

Four Sol 6.1 agents reviewed the connected workflows across application navigation, canonical allergies/permissions, connected medication records, and frontend recovery/UI. Backend implementation remained with the backend agents; frontend implementation remained with the main session.

The frontend reviewer found and verified repairs for actor changes, uncertain-response recovery, stale order identity, PIN retention, delayed requirement responses, inaccessible menus, false save feedback and lost return context. Their final source review found no remaining concrete P1/P2 issue in the assigned scope. A further focused review accepted the canonical safety caption changes.

The connected-care reviewer independently checked Medical notification/reminder delivery, the recording-status endpoint, the final canonical safety projection and all four production callers. No blocking issue remained in those reviewed paths. Reminder execution itself was source-reviewed; the recipient predicate has pure regression coverage.

## Validation evidence

- Frontend: final combined affected suite **105 tests passed in 12 files**, followed by **8 safety ribbon/directory tests passed in 2 files**. The recorder's 29 cases cover uncertain responses, retry identity, permissions, changed orders, PRN recovery and fresh witness secrets.
- Full TypeScript and all changed-file ESLint checks passed on the integrated tree, followed by a passing full TypeScript check and focused lint after the final safety-caption follow-up.
- Production asset build passed on the final source (7m45s), including the safety-caption follow-up. The normal large-chunk advisory remains; it is not a build failure.
- Backend: combined follow-up had **108 passing cases**, with four fixture failures isolated to recording-status setup and the historical pharmacy fixture. The narrow rerun passed the historical pharmacy case. Three recording-status cases again stopped before reaching the endpoint because changing PRN prescription fields invalidates approval. Their fixture now saves prescription changes first and approval separately, matching the model lifecycle; fresh CI must prove those three cases. Earlier focused batches also cover exact record navigation, round printing, canonical allergies, medical permissions and concurrency. Failed run evidence is retained rather than presented as passing.
- Pure backend projection, notification and caller-gate tests: **14 passed, 129 assertions**. Internal return-path unit coverage also passed (21 cases).
- PHP syntax, agent-owned Pint checks and whitespace validation passed.
- The final isolated backend runner exited and its exact owned schema was removed; zero matching schemas/processes remained. No additional local database rebuild is running.

## Desktop browser verification

The verified preview is `http://127.0.0.1:8767`, served from this integration checkout with the dedicated synthetic eMAR database. Mail is captured locally and external delivery is disabled.

- MAR → Allergy record → Open health profile opens the same person's Medical section. Back restores the exact historical chart date and section.
- Allergy editor opens the shared Rory wizard; its empty state explicitly distinguishes missing evidence from no known allergies. Inspection was cancelled without an allergy change.
- Medical profile Edit and Conditions Manage open their actual editors. One clearly labelled synthetic condition was saved in the synthetic client record; the saved state and refreshed list were verified.
- Medicine details → Open Orders & reviews opens the exact order dialog. Return restores the original person, historical date and Medicines tab.
- Profile Add/log → Record medication dose opens the shared picker and gives a truthful empty state when no scheduled dose is due.
- Final rebuilt client directory shows “Allergies not reviewed” for the authorised synthetic record and does not reveal Medical status for people outside that actor's Medical access. Browser console: zero errors and zero warnings on this final page.
- The browser briefly received the expected Inertia asset-version conflict when the newly built assets replaced the old version; it reloaded successfully. No application exception was observed in these verified journeys.

## Final release gates

- Historical pharmacy narrow retest: passed. Corrected recording-status integration cases: pending fresh CI.
- Final safety-caption TypeScript and rebuilt desktop verification: passed.
- Fresh GitHub branch/PR checks: pending publication. The existing dedicated eMAR gate explicitly includes the complete ProfileAllergySafetyTest, CanonicalAllergyProjectionTest and ClientMedicalControllerTest files so unrelated broad-shard failures cannot hide these cases. Foundation CI also runs shared eMAR component and navigation/safety UI tests.
- Production deployment and external delivery configuration are outside this local journey acceptance. No production data was changed.

## Verification boundaries

Backend tests use the repository's isolated testing database mechanism, not the application or preview database. Shared preview stays synthetic and external delivery is disabled. Test results, browser provenance, independent review and any remaining limitations must be captured before sign-off. Existing tests that assert wrong destinations must be corrected to the intended journey.
