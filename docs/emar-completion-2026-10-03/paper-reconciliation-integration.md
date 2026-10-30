# P10 paper reconciliation — minimum integration contract

Focused read-only design review, **3 October 2026, 23:58 NZDT**. The accepted ordinary cases can be completed through the canonical recorder without new permissions: unchanged scheduled doses, unchanged PRN doses after temporal safety repair, and explicit refused/withheld outcomes after reason mapping. Unchanged second-person/CD cases need shared evidence and historical stock/register adapters. Deleted, superseded, changed or otherwise unrecordable orders remain held; this review does not authorize that boundary to change.

One operating organisation across approved sites. Current permissions, canonical person/order ownership, covering assignment, employment/competency, direct-object denial and controlled privacy remain the authorization boundary. No new grants, policy exceptions, application edits, migrations, tests, servers or clinical data changes were made. The task reports an earlier automatic-review hold on deleted/superseded recording and new grants; that hold is preserved, not reinterpreted as authority from the mockup. Main must retain its original approval-review evidence for any future request to change it.

## Evidence and approved workflow

Exact P10 v1.1 design: `1c758eabc27c3e7844912432ba7e5e7ac4dbaab7`, Git-tree `docs/emar-design/P10/v1/`. `src/pages/contract.tsx:88–91` and `src/dialogs-paper.tsx:275–497` specify: scheduled targets plus individually added PRN records; explicit paper outcome/time/giver; entry time beside clinical time; giver confirmation when entered by a lead; named witness's own PIN now or later; controlled entries in clinical time order; closing controlled count; confirmations continuing in Follow-ups after collection finishes. Its old “build scope needs OK” caption is not a new approval gate for work already authorized in this programme, nor permission to expand historical-order authority.

Inspected moving worktrees, bounded by file hashes below:

- P10 `C:/Users/steph/.codex/worktrees/emar-p10-breakglass/oblivionfindings`: advanced from `b78bef5a05dd4dd51311463341fd6cea8a5a54d4` to `e5cad7de0613738a0b58791d0d54b6f68bae7254` during inspection.
- Main integration `C:/Users/steph/.codex/worktrees/emar-completion-integration/oblivionfindings`: `f3c0ad36a8b008112588a951b974682386c6363a`.
- P07 `C:/Users/steph/.codex/worktrees/emar-p07-controlled/oblivionfindings`: `ba21836b6c3a43c53e0e824fc769b68d18767238`.
- P06 contract `47cc811731b7a33856c8fd42971f3cc81acd074c`; see [stock integration map](stock-integration-map.md). Its service is unchanged in the inspected Main tree.

Method names are stable anchors; line numbers refer to the inspected source, not later owner edits. P10's `PaperAdministrationWriter` still holds all five unsupported categories. It already sends actual `given_at` and original `scheduled_for` through `MedicationScopeDecisionService::forAdministration`, requires the actual giver to apply, and passes the locked presence/time to `EnhancedMarService`. Do not replace this with a direct model insert.

## Supported cases and remaining authority boundary

| Case | Minimum owner action under existing authority | Required hold |
| --- | --- | --- |
| Unchanged ordinary scheduled **given**, no second person | P10 retains existing path; P01/Main integrate its final recording contract and P06 stock disposition below. Validate paper amount, observations and actual time. | Missing covering authority, changed order, safety failure without existing valid override, unknown stock disposition. Existing source success is not certification of historical stock reconciliation. |
| Unchanged ordinary PRN **given**, no second person | P01 repairs shared PRN checks to accept clinical time; P10 removes only the PRN availability hold after integration and supplies paper evidence/explicit amount. | Interval/24-hour violation, unreadable history/limit, changed order or missing actual authority. Do not treat current-time check success as historical permission. |
| Unchanged ordinary **refused/withheld** | P10 adds explicit `NotGivenReason` mapping, notes and optional evidenced follow-up time; P01 retains canonical non-given behavior. No stock allocation or given-only readings/witness requirement. | Existing given/effective outcome for that dose; unrecordable order or missing authority. A voluntarily named paper witness still needs its confirmation; do not discard it to get past a hold. |
| Unchanged ordinary **given**, named rule/amount witness or required competency co-signer | P01 adds an internal historical-attestation input to the canonical writer; P10 supplies immutable confirmations; existing eligibility and competency rules still apply. | Missing/ineligible signature, changed stricter requirements without matching evidence, unavailable historical presence/competency, or an attempt to use another actor's authority. No blanket “second person unavailable” or forgotten-PIN substitute. |
| Unchanged CD **given**, scheduled/PRN | P01 + P07 accept signed historical evidence; P06 + P07 reconcile exact physical quantities/lots and closing count. Existing CD recording/view/witness capabilities remain necessary. | No exact stock unit/quantity, waste evidence, opening/count basis, historical register adapter or closing reconciliation. No raw call to today's register subtraction. |
| Changed/deleted/superseded/ceased/unverified order; giver unavailable or no covering authority | Keep signed paper and a precise clinical-review next action. A reviewed link to existing effective evidence may resolve collection, without posting another dose. | **Held authority:** no `withTrashed()` recording path, `currentOnly=false` shortcut, successor substitution, invented shift/grant, new retrospective role, or lead posting as the giver. |

`DowntimeAccess::manages()` names admin/provider_manager/coordinator/clinical_lead/team_lead with `medications.view`. This permits scoped paper management, not signing another person's administration. `PaperAdministrationWriter::availability():34–39` requires `actor.id == given_by`, `medications.administer.record` and the unchanged administrable order. Keep this executable rule. The approved “lead enters for someone” workflow can be completed by the actual giver's subsequent confirmation and explicit posting; automatic posting after their own confirmation is a UI choice only if the same locked checks succeed.

CD additionally requires existing `medications.controlled.record`/view and a distinct eligible witness with `medications.controlled.witness`. P07 management/count permissions and stock grants are not supplied by the label “clinical lead”, paper collection, audit access or `scope_authorized`. A clinical lead needing unavailable authority receives the existing authorized-person route; do not seed a new grant. An ended emergency grant is not revived because its interval covered paper time: P10's queued-offline exception requires genuine captured offline evidence and excludes second-person doses. Paper is a separate source.

## One internal adapter, with server-resolved evidence

Extend P10 `PaperAdministrationWriter::{availability,withinAuthority,postAuthorized}` and P01/P07 internals; keep the existing preview/reconcile routes. Conceptual contract (names are proposed, not existing APIs):

`postConfirmedPaper(entryId, previewToken, authenticatedActor) -> PostedPaperResult | PendingPaperReason`

The server builds a `ConfirmedPaperContext` inside the canonical transaction. It contains the immutable entry ID/fingerprint, downtime/site/person/order/version, original slot or PRN identity, actual UTC instant/NZ offset, explicit outcome/amount/unit/notes/readings, entrant and entry time, actual giver, giver/witness confirmation IDs and confirmation instants, locked scope decision, and stock/count disposition. Do not accept this context, `scope_authorized`, witness eligibility or count coverage as client assertions.

1. Resolve readable objects and the same order/person/site; freeze the current non-deleted/non-superseded order and original scheduled target. P10 fingerprint currently covers identity/dosage/version/approval/CD/PRN (`PaperEntryService::orderFingerprint():38`), but omits schedule, start/end, PRN limits and some current safety requirements. P01/P10 bind the execution preview to those relevant facts/rule revisions too. Recompute under locks; reject stale preview. Do not retarget the original dose to a successor or a newly generated nearest slot.
2. Enter canonical `forAdministration` at `entry.given_at`, with original scheduled time and the complete giver/witness user set **before** locking Users/Profiles. Preserve Client → medication → presence Shifts → Rules → Users/Profiles → Site → administration/count → stock ordering; acquire the paper-entry mutex at the agreed point after the authority prefix. Include counterparties up front, not after the actor is locked. Recheck authority and clinical checks even on replay.
3. Lock entry/confirmations and recheck posting/conflicts. Confirmations must belong to this entry, kind and named actor, with permitted authentication method. Entry facts are immutable; any new supplement must bind its own fingerprint and accountable confirmation. No accepted signature moves to another entry or changed facts.
4. Invoke the **same** canonical clinical writer with server-owned historical context. Clinical time is `given_at`; current posting/attestation times remain current. `administered_by` is the actual giver; `posted_by`/paper entrant stay separate evidence. Normal HTTP/offline paths cannot supply the internal historical mode.
5. Resolve ordinary/CD physical disposition exactly once. Return canonical administration ID, effective evidence identity, register/movement IDs, stock disposition and closing-reconciliation state. Never return generic success before all required writes are present.
6. Create the unique `MedicationPaperPosting` only after canonical posting success. P10 event, P08a follow-ups and clinical/register evidence commit atomically; use P09's final collected append. If final audit/event/posting/lot write fails, roll back the whole attempt and replay claim. A safe pending reason retains paper with no partial clinical posting.

P10 already has global unique `dose_identity`, one confirmation per entry/kind, and unique entry→administration links (`2026_10_03_212000…:65,76,80–81`). Retain these. `conflicts():312` checks effective clinical evidence by the original scheduled minute, or PRN actual minute. P01's canonical dose-window/root-correction uniqueness remains authoritative as well: a different UUID, overlapping downtime, offline sync, historical correction or refused-dose re-offer must not create a second outcome/allocation. Existing evidence requires the explicit reviewed duplicate-resolution link, not silently attaching a materially different administration.

## Temporal PRN and structured non-given adapters

**P01 temporal PRN:** `MedicationSafetyService::performSafetyCheck` receives `$adminTime`, but `checkPrnLimits()` uses `prnCountLast24Hours`, and `checkPrnInterval()` compares the latest dose to `now()`. Main `ClientMedication::getPrnLast24HoursAttribute():538` has a lower bound only, so simply replacing `now` also admits later doses into the historical count. Thread an explicit instant into shared helpers; live callers default to current time.

Use effective given evidence for the same canonical person/order, bounded by clinical time, preserving the existing 24-hour boundary and existing dose-count semantics. Include the proposed paper dose once. Interval checks inspect chronological predecessor **and successor**, with signed elapsed minutes: absolute differences and today's latest dose cannot establish historical spacing. Re-evaluate later rolling windows affected by the insertion so a historical addition cannot silently make already recorded later doses breach the same existing limit. Serialize all evaluation and insertion on the order. If inserting the missing evidence creates a breach, retain paper and use the existing clinical error/exception route; this review does not invent permission to override it or rewrite later doses. Preserve source confirmation, actual amount and any evidenced effect-check deadline; a past deadline projects as overdue, never reset to tomorrow.

**P10 non-given:** current `PaperAdministrationWriter:48–52` blocks these before the canonical writer. Pass `reason_code=refused` or `withheld` only as an explicit, reviewed mapping of the selected immutable paper outcome, retaining the original note as detail. Those enum values exist; no underlying cause, doctor instruction or stock movement is inferred. Allow another existing `NotGivenReason` only when explicitly evidenced/selected and confirmed. Do not mutate existing immutable paper entries to add the code: use deterministic outcome mapping or an appended signed supplement when new facts are needed.

Main's `EnhancedMarService::validateRequiredObservations():2174` returns early for non-given; its safety/window restrictions prevent given only (`1169`, `810`). Use that behavior. Refusal follow-up/effect checks use their existing domain services; don't invent a historical personal-choice category or re-offer deadline from a free-text note. If the helper hardcodes a category, preserve explicit generic outcome evidence until P01 maps the evidenced reason correctly. No PRN count increment, ordinary/CD deduction, mirror-reading or successful-given witness stamp for refused/withheld.

## Historical stock/register and closing-count contract

**P06/P07 must supply the disposition; P10 must not guess it.** P06 `ordinaryDose():195–218` uses today's usable packs and FEFO, has no historical allocation/count-coverage mode and deduplicates by administration ID. P07 `ControlledRegisterService::write():562–574` writes current balances/`recorded_at=now()`, while P01's current CD dose path subtracts today's scalar stock. These cannot be used unchanged for an arbitrary paper interval.

For every given paper entry classify under stock/count locks:

- **Already allocated:** link the same canonical movement/register evidence; no second physical delta. Original dose identity matters even if a correction has another administration ID.
- **Not allocated and not absorbed by a later count:** apply one evidenced quantity against exact original stock unit/packs through the governed adapter; current balance changes once. Historical availability is established from actual lot/movement provenance, not today's FEFO. A pack expired today might have been valid then; a newly received pack cannot be used to explain yesterday. Unknown batch/unit is not converted or fabricated.
- **Absorbed by an evidenced later physical count:** associate the paper consumption with that count/reconciliation interval and preserve the counted current position; do not subtract again. This requires an explicit domain coverage link plus witnessed register/count evidence, not merely `last_counted_at > given_at`. Count shortfalls and subsequent loss/reconciliation entries must not be charged twice.
- **Unknown/contradictory:** retain pending stock reconciliation with an authorized next action. Do not choose no-op or deduction based only on whichever gives a plausible current balance.

For CD preserve signed paper clinical order and immutable entry provenance. Build/rebuild a **derived chronological register projection** for the affected interval using its established opening balance, all effective movements, explicit administered/wasted quantities and closing count anchors. Preserve original saved register/count rows and their posting times; do not rewrite them to pretend they were originally correct. P07 decides the canonical historical-entry/projection seam, P06 links exact physical lots. Never run both P01 current CD subtraction and P07 historical subtraction. Refused/withheld are stock-neutral. Expired physically present CDs remain in physical balance.

Closing reconciliation is its own state. A collection finish is not a matching controlled count or proof that all doses are posted. The matching witnessed closing count must cover the full relevant paper interval, all its signed CD entries and later movements, have valid participant authority, and retain first count/recount/discrepancy evidence. A mismatch routes through existing P07 discrepancy/loss resolution and remains due. A historical administration successfully posted while a closing check remains outstanding may say Entered from paper **with** that required follow-up; only the stock/register adapter may determine whether safe posting is possible before closure. No adapter/evidence means no posting. Do not close pending confirmation/count work when `finished_at` is set.

## Owner sequence and focused acceptance

1. **P10 + P01:** finish explicit ordinary refused/withheld mapping first; integrate P10's existing scheduled path with Main's final recorder. Keep unsupported holds specific. Giver confirms/applies in their own account; show actual paper time and separate entry time, preserved input on failure.
2. **P01:** implement temporal shared PRN checks and immutable historical-attestation validation, including current permission/employment and historical presence/competency checks. Current live PIN flow remains intact; P10 confirmation is a separate attestation channel. P10's current confirmation stores actor/method/current confirmation time; freeze or re-resolve eligible historical presence/assessment evidence under the same canonical locks. Never manufacture a live PIN or set `witnessed_at` to the clinical time when it actually means later confirmation.
3. **P06 + P07:** provide the stock disposition and historical register/count projection contracts above. Implement known unchanged-order, signed-evidence cases under existing actor capabilities. Unsupported balances/closed intervals remain precise pending reasons until domain evidence exists.
4. **Main:** integrate once, preserve held grants/orders, wire P08a giver/witness/count projections and P09 audit. Browser acceptance at 1440/1280/200% plus preserved mobile behavior covers own entry, entry for another giver, PRN add, non-given, confirmation later, conflict, stale preview, writer failure, and collection-finished-but-follow-ups-due.

Focused synthetic checks required from owners (none run here):

- Actual-time covering **completed** shift succeeds; missing/forged assignment, ended unqueued grant, future time and repeated/nonexistent NZ DST time do not. Clinical and entry/confirmation timestamps remain distinct.
- Same original slot/PRN minute across downtimes and lost-response retries produces one effective administration, one posting and one physical disposition. Changed UUID/payload, offline-sync conflict, corrected root and existing refusal remain governed; paper cannot become an automatic re-offer.
- PRN yesterday passes based on yesterday's bounded history even when today's usage differs; predecessor/successor interval violations and affected later 24-hour limits retain signed paper. Exact existing limit boundary, non-given and effective corrections are covered.
- Explicit refused/withheld preserve notes/reason and actual actor/time, allocate nothing and require no given-only reading. Follow-ups/deadlines are evidenced; no inferred doctor order or personal-choice reason.
- Named giver/witness confirms only their own immutable entry; wrong PIN, self-witness, foreign site, wrong confirmation kind, stale fact version and missing historical presence/competency cannot post. A current PIN creates a current signature, not retroactive presence. New grants and deleted/superseded recording stay denied.
- Paper consumed 2 from opening 10: no later count → one delta to 8; covered matching physical count 8 → no second delta to 6. Intervening receipt/return/waste/count/loss and expired physical CDs retain correct provenance. Missing count coverage stays pending rather than guessed.
- Fail register attachment, second lot save, follow-up, posting uniqueness or final P09 append: no partial administration/register/balance/posting/replay success. Closing-count mismatch and finished collection leave relevant follow-ups due.

## Snapshot hashes and limits

SHA-256 at final source capture:

| Checkout/file | SHA-256 |
| --- | --- |
| P10 `PaperAdministrationWriter.php` | `0EB6F02FE1C2C0953C8E373FC7E5E85CFCB4443724F11E84CA96A4B2A0B0AF49` |
| P10 `PaperEntryService.php` | `ED238C357AADC1E0A31AAFE9125719245181CB7475FE75BFAC3A23C2B775B9ED` |
| P10 `MedicationScopeDecisionService.php` | `D3DEB9638F1F86053CBCBAD9655CAC68512D14A2C652319895A0B3A1733F8121` |
| P10 `EnhancedMarService.php` | `4E8AD5F36F26062292FD5D747754323621CDCD5C44E180305CCF3CF1332BA926` |
| Main `EnhancedMarService.php` | `E2D5C622CDDECC39E708F998D23ECAF9ACAA21BA255838DE5EEF22A744C64D72` |
| Main `MedicationSafetyService.php` | `7A6EF2886F12940786B206F305B1CE65E2BD8B7FCA1C0D7351C6BD6E72522A35` |
| Main/P06 `MedicationStockService.php` | `BDADD1D11A998B2337F5E88CB4E255813BEA464D68209C801610155BD58BCB95` |
| P07 `ControlledRegisterService.php` | `111BC1095C9AC90231D6EBD4D7E92CB60AD4C30548E4AB3F5B13B2B083E2BE5D` |

This is an implementation contract for bounded accepted cases, not runtime certification or a new clinical policy. Owners were active; Main must verify final adopted sources and evidence before enabling a historical posting path.
