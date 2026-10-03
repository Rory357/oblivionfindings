# Independent clinical/code review — P03–P07

Read-only package review, 3 October 2026 (Pacific/Auckland). **Snapshot pass complete at 23:01 NZ**; package writers remain active. Findings identify an exact inspected HEAD and SHA-256 of working files; they are not a claim about later revisions. Only this review document is written. No package code, worktree, server, build, database, role grant, commit or push is changed by this review.

Authority: primary `AGENTS.md`, `DESIGN.md`, `docs/architecture/single-tenant-application.md`, `mockup-inventory.md`, and the latest chronological `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`. Routine approvals remain delegated. P06's held receive/house-lead role expansion and P01's held unassigned-round authority remain held; existing permissions are preserved. Clinical policy is the approved application contract, not a new medical/legal recommendation.

Exact design references: P03 `9822d78b47bf71c27c2ff424079c52351cf9076c`; P04 `24d230ed94b6295f27901f0eac27bb8180be89ac`; P05 v1.1 `22982b1ff81d252e446f543a3494ea918b03a047` (v1 directory); P06 `871f06c3adc523d51c70e6147ff2e20dac1b3db0`; P07a `8520c08b4749089ac4f9e36d4b54360f8c5a9968`; P07b v1.1 `6fe3c0766c9c00d59579c0131921e0c254d1fecb` (v1 directory). Current DESIGN/Rory amendments govern shared chrome.

## Findings and dispositions

### ICR-01 [P1] P06 accepts a stale physical count and can restore a legitimately consumed dose

Observed at **22:40 NZ**, HEAD `e5065f99c1d1c780fd766675daf20b4223e760f2`, working files:

- `C:/Users/steph/.codex/worktrees/emar-p06-stock-pharmacy/oblivionfindings/app/Services/Medication/Stock/MedicationStockService.php:283–295, 324–334`, SHA-256 `0323747EB0CE6F5568735A6BB59D0B27B3285AACDCAD6419D9D99ADAD03CB0B3`.
- `app/Http/Controllers/Emar/MedicationStockController.php:160` in the same checkout, SHA-256 `1FA197A4687B3B70589EBFB97DD6B50144F5150324631B750BD59A5442375F8F`.
- `resources/js/pages/emar/stock/_dialogs.tsx:139,151`, SHA-256 `91A55673320B0FA92883C2599B8D63ADF8806569FE878125446BBDE4010D3BD6`.

**Trigger:** open count with lot quantity 20/revision 1; physically count 20; a concurrent ordinary dose consumes 1 and changes the lot to 19/revision 2; submit the old form with `revision:1, quantity:20` and a difference explanation; lead signs it off without a later stock movement. The form sends its starting revision, but request validation does not validate that field and `count()` does not compare it. Instead, it stores expected 19 and revision 2 at submission. `reviewCount()` then accepts revision 2 and writes 20. A legitimate administration is effectively erased from the stock balance.

**Minimal fix:** require each submitted lot revision (or a count-start snapshot token), compare it against the locked pack revision before storing the count, and reject/recount on any change. Retain entered values and explain that stock moved. Keep the existing post-count review revision guard as well. Acceptance scenario: the interleaving above produces a conflict and no count correction; a fresh 19 count succeeds. This is incorrect existing logic, not a missing P01 wiring complaint. The approved P06 blind count/movement contract and the implementation's own “if stock moved while you counted, recount” text require this safeguard.

**Follow-up at 22:50 NZ — addressed in source, runtime verification outstanding:** same HEAD; controller now requires `lines.*.revision` (`MedicationStockController.php:183`, SHA-256 `98842B8D54484110F683C590681A26889C29F5D3C8FCC05B30ABC35E0A2FE680`), and `MedicationStockService.php:290–292` compares the submitted revision with the locked lot and rejects a changed pack (SHA-256 `BCA86E7061C9508F4D1050F1F59DC29BAA347AA84785A7752A423DD060164F90`). The original finding is retained for audit history. Main should exercise the stated concurrent-dose scenario before closing it.

### ICR-02 [P1] P04 reconciliation saves worker-local dose times as UTC

Observed at **22:47 NZ**, HEAD `9747cf7cb654c2ef441e8f60c7ea1b5918081925`, working files:

- `C:/Users/steph/.codex/worktrees/emar-p04-orders/oblivionfindings/app/Services/Medication/MedicationReconciliationWorkflow.php:87–106,146–148`, SHA-256 `FF10FE080AAF4D8DED1D9C8951135641022DEA7061790DD2679791F7DBAB1DF4`.
- `resources/js/pages/emar/orders/_reconciliation.tsx:32,46,48` in the same checkout, SHA-256 `1923181E122A8E10A8CFACD20E895DEC753F12520D6905FF07C885D366C23F7D`.

**Trigger:** the DateTimeField explicitly labels Pacific/Auckland and emits offset-free `YYYY-MM-DDTHH:mm` (`resources/js/components/fleet-assets/maintenance/date-time-field.tsx:39–40,47`). At 22:47 NZ on 3 October, record a last dose actually given at 21:00 NZ that evening. The `date|before_or_equal:now` validator interprets 21:00 in the application's UTC timezone (`config/app.php:99`) and rejects a genuine past dose as future. An accepted older local time is kept verbatim in the external-dose JSON. Likewise a next affected dose entered as `2026-10-04T08:00` is assigned directly to the datetime cast as UTC 08:00, meaning 21:00 NZ, and is then passed as the follow-up deadline. Reopening and saving a prefilled next-dose value can repeat this shift because the form converts UTC to local before submitting it again.

**Minimal fix:** normalize both incoming worker-local instants through the existing worker-time parser before validation/storage, preserve an unambiguous UTC/offset instant in external-dose evidence, and reject nonexistent/ambiguous DST wall times with actionable input guidance. Acceptance: a 21:00 NZ past dose saves and renders as 21:00; an 08:00 NZ next dose is stored as 19:00 UTC the preceding day; reopening and saving it does not move the deadline. Check both NZ offsets and DST transition cases. P04's actual-last-dose/next-dose contract and repository worker-time rules require this.

**Follow-up at 22:55 NZ — remained open at that snapshot:** HEAD `f8ab349b37715be7232d282241e17be04c6d99b5`; workflow SHA-256 `1407900F241AF507EACAD92868B95F4EBB69CA46B60E796F88D81E6C80399220`. New lines 88–90 correctly attempted to normalize before validation, but `instant()` at line 226 resolved `App\Services\Medication\MarScheduleService`. The actual service is `App\Services\MarScheduleService` (`app/Services/MarScheduleService.php:3,30`); there was no class at the invoked namespace. The caught resolution exception became “Enter a valid NZ date and time” for every supplied instant.

**Follow-up at 23:00 NZ — UTC conversion and namespace addressed in source, runtime verification outstanding:** same HEAD; workflow SHA-256 `2DDD2C625EDB33D236C6568647EB6E046FD3C02A1BC03540EA07EB4B634B1C35`; `instant()` at lines 276–282 now uses the actual `App\Services\MarScheduleService`. Main should verify the past-dose/next-dose round trip using the real form. That shared parser uses Carbon wall-time parsing, so nonexistent/ambiguous DST validation is still an acceptance assertion; the normalization change alone does not establish it.

### ICR-03 [P1] P07 safety workflows have incompatible form, endpoint and service contracts

Observed at **22:49 NZ**, HEAD `53d833d6125c9630b13d3a6326890573a83b9047`, working files:

- `C:/Users/steph/.codex/worktrees/emar-p07-controlled/oblivionfindings/resources/js/components/emar/controlled/action-dialog.tsx:94–105,121–126,131,134`, SHA-256 `0E1D8F3FA5BEEB53B8D5A8237C55E2A4FFB0FABE0CF8F680407FB953CB43420A`.
- `app/Http/Controllers/Emar/ControlledProductController.php:50–81` in the same checkout, SHA-256 `054D99B5473883A5042D9A9458E6DBF6F4AD8CAD3BC94AE21F371452135D125A`.
- `app/Services/Medication/Controlled/ControlledRegisterService.php:231,297,330–352,427,453`, SHA-256 `3E3BA04B52836D1E3FB3BFA8A23E01BEB91F16A7DE46CA2606A8BC64E202BF8B`.
- `resources/js/components/emar/controlled/product-client.ts:25–33`, SHA-256 `AE1AC1B47F6059F73661418BC3750BA1098623085034B12CA8D0D9EDEBE717A5`.

The UI serializes these values directly; neither client nor controller translates them. Concrete blocked paths:

| Action | Form submits | Server requires/rejects | Result |
| --- | --- | --- | --- |
| Report a loss | `circumstances`, `immediate_action_taken`, quantity | Controller drops `circumstances`; service requires `notes` | Valid loss cannot be recorded from this form. |
| Void/correct an entry | `reason="Wrong amount"` (or other displayed choice), `notes` | Controller's shared `reason` rule only admits destruction reasons such as `expired` | Every displayed void reason produces 422. |
| Close a loss | `outcome=accidental/unexplained/theft`, `resolution_notes` | Controller only allows discrepancy outcomes; drops `resolution_notes`; service requires `notes` | Manager cannot complete the approved closure workflow. |
| Add notification later | Police/regulator flags and police reference | Service requires `notes`, `authority`, `reference`; UI supplies none of the first three | Approved later-notification path cannot save. |
| Ask a colleague to witness | Colleague in `witnessed_by` | Service reads `witness_id` | Selected colleague is lost and the request fails. |
| Ask for an override | Required `reason`, optional `notes` | Service requires `notes` | Completing all required fields still fails; supplied optional notes become the reason instead. |

**Minimal fix:** define action-specific request contracts and translate/validate the actual product form fields at one explicit boundary. Preserve required narrative, selected witness, structured loss outcome and notification evidence rather than relaxing everything to arbitrary strings. Theft discovered during investigation must require the recorded police notification before closure, even if the initial report did not mark suspected theft. Verify each action using the actual UI-shaped payload, including retry after an uncertain response, rather than only service-shaped fixtures. This is a connected, present controller/form defect; it is not a complaint that an unfinished adapter is missing. It blocks the P07b approved loss/register/witness recovery workflows.

**Follow-up at 23:01 NZ — partially addressed; P1 remains:** same HEAD. New `resources/js/components/emar/controlled/product-actions.ts`, SHA-256 `D33B66D3979B33F5CA85D94C10DD5AF13F610D93F6CDF2EB45DDFB2A6018B5E4`, explicitly maps loss-report narrative, void reasons, recount, witness request/answer and override reasons. The dialog now invokes it (`action-dialog.tsx:601`, inspected SHA-256 `9564896CC827160E1869A9B7F292C5963D9DF186CBF884B7F9D0A681931A41BB`). Those original field-shape failures have source corrections and need UI-shaped endpoint verification.

Remaining concrete safety failure: start a loss without suspected theft, investigate it, then choose **Theft** in Close loss with no recorded police notification. The builder sends `suspected_theft=true`, `resolution_outcome=theft`, notes and `notifications_checked`. Controller SHA-256 `054D99B5473883A5042D9A9458E6DBF6F4AD8CAD3BC94AE21F371452135D125A` accepts the new theft boolean but drops the structured outcome/checked fields. Service SHA-256 `42BA0095920126AB2EADB52F40E632DC8755B2B8FFA8A246955A5B9E7920621D` at lines 330–336 checks only the **stored original** `$loss->suspected_theft`, ignores the submitted theft finding, and closes the loss. This can now succeed without the required police evidence. Validate/store the structured closure outcome and require police evidence if either the existing investigation or the chosen finding is theft. Also finish the later-notification contract: the builder copies `authority/reference/notified_at/notes` while that dialog still presents police/regulator flags rather than those required fields.

### ICR-04 [P2] P05 attributes a completed clinical review to a waiting or rejected prescription version

Observed at **22:49 NZ**, HEAD `0ddf02daa26191ea67c8bb451f95bf155ad2aa97`: `C:/Users/steph/.codex/worktrees/emar-p05-completion/oblivionfindings/app/Services/Medication/Reviews/MedicationReviewWorkflow.php:178–197`, SHA-256 `C2155BD6BDA76EF61B98C4D19AF2C64B9372121BE96771C2B95BECF459749F12`.

**Trigger:** the effective checked chart is version 1, while P04 has entered a proposed version 2 that is waiting for its check (or has been sent back). Complete a review of the current prescription, recording Continue or a recommendation. The workflow snapshots the canonical order's current name/outcome but links `order_version_id` to `latest('version_number')`, which selects version 2. The clinical-review history now states that the unapproved version was reviewed. P04 deliberately keeps version 1 effective while a change waits, and P05 records recommendations against the current order without altering it.

**Minimal fix:** identify the actually effective checked version by the canonical order's version and ownership, rather than the highest entered version. If an older current order has no saved version record, preserve that lack of evidence explicitly rather than attaching a later draft. Acceptance: pending/rejected version 2 does not change the completed review's link to checked version 1; newly checked version 2 is linked only after publication.

### ICR-05 [P2] P06's blind count can reveal the expected balance before anything is counted

Observed at **22:50 NZ**, HEAD `e5065f99c1d1c780fd766675daf20b4223e760f2`:

- `C:/Users/steph/.codex/worktrees/emar-p06-stock-pharmacy/oblivionfindings/resources/js/pages/emar/stock/_dialogs.tsx:144–151`, SHA-256 `91A55673320B0FA92883C2599B8D63ADF8806569FE878125446BBDE4010D3BD6`.
- `resources/js/components/wizard/shell.tsx:220–224` in the same checkout, SHA-256 `A13FDE4AF3DA052132F230CBAC856546EB665ED8583ABAC2AE2C3BC4A3AF7FB7`.

**Trigger:** open Count stock with all quantities empty, then click the rail's “Check differences” or “Review and save” step. `onStepClick={setStep}` allows it, because no step is marked disabled. `step > 0` immediately displays every pack's recorded starting amount. The worker can return and copy those values rather than completing the approved blind physical count. The Continue button's `complete` guard does not guard the rail.

**Minimal fix:** disable future steps and guard rail navigation until every physical quantity is entered; show recorded amounts only after that transition. Keep backwards navigation and entered drafts. Acceptance: keyboard/mouse rail navigation cannot reveal expected quantities before completing the physical count; the normal count → compare → reason → review path still works.

### ICR-06 [P2] P06's out-of-stock meter and filter disagree with live pack availability

Observed at **22:54 NZ**, HEAD `e5065f99c1d1c780fd766675daf20b4223e760f2`:

- `C:/Users/steph/.codex/worktrees/emar-p06-stock-pharmacy/oblivionfindings/app/Services/Medication/Stock/StockReadPayload.php:51–52,84,120–124`, SHA-256 `A7FA43DE7DA4E17B8CE404422578128D31B21C5EFA7B69177DC2EA21CFFA0427`.
- `resources/js/pages/emar/stock/StockHub.tsx:100` in the same checkout, SHA-256 `8A79C9E49354C49503EB60050F077BAC508522EBB37E0E541070F07F9D204755`.
- `app/Support/Medication/StockLotRules.php:20–25`, SHA-256 `60684A0489345E661466C8C17E7BB8640A50AF3A1F63E8D99EAFE8D27F031D6B`.

**Trigger:** an ordinary medicine's last usable pack has 10 units and expires today. No movement occurs overnight. On the next NZ day, the row correctly calculates usable stock as 0, because expiry is evaluated live. The meter and its Show: Out of stock filter instead query persisted `stock.on_hand=10`, so the meter says 0 and the medicine disappears when the worker follows it. Running-low filtering has the same mismatch. The existing stock-check command sends expiry alerts; it does not recalculate lot availability on this read.

**Minimal fix:** use the same current-NZ-day usable-pack quantity for the row, factual meters and associated selection predicates. Keep the controlled register's physical-balance semantics distinct and preserve unknown/unstarted stock explicitly. Acceptance: advance across an expiry boundary without a stock write; the ordinary row, meter and clicked list all agree that the medicine has no usable supply.

### ICR-07 [P2] P04 keeps superseded sent-back revisions in the actionable To check list

Observed at **22:57 NZ**, HEAD `f8ab349b37715be7232d282241e17be04c6d99b5`: `C:/Users/steph/.codex/worktrees/emar-p04-orders/oblivionfindings/app/Http/Controllers/Emar/MedicationOrdersController.php:61–67,328–339`, SHA-256 `A6C2FC68E5B4A0E75A50918541818BE1A6CC211DB55265656C3C80C92FBAEB12`.

**Trigger:** version 2 is sent back, the enterer corrects it as version 3, and version 3 is independently checked and becomes current. The old version 2 remains `sent_back` as it should for history. The To check query admits any historical sent-back revision, so this fully checked medicine remains in the work list forever. The updated summary already hides version 2 because it is below the current version; the resulting row has no pending change for the worker to resolve, while the pending meter can be zero.

**Minimal fix:** admit only a currently actionable pending/sent-back version and applicable unresolved check/written-confirmation work. Preserve historical revisions in the order history. Acceptance: after corrected version 3 is checked, the item leaves To check; an actual waiting version 4 or remaining independent check still brings it back.

## P03 candidate correctness pass for Main

The requested stable candidate is **`5212bb330069e333b7c8a175998264dab8c119bd`**, after P09 dependency `390632233442882ade9670182dd36ae931646340`. Exact committed backend and projection files were reviewed on **22:57 NZ**. The later unsaved UI UUID edits are outside that candidate. No P1 is confirmed in the requested P03 effective-support, withdrawal, new-medicine-default or controlled-cap invariants on this static pass:

| Boundary checked | Source evidence / conclusion |
| --- | --- |
| Actual effective support | `MedicationSupport::mode()` reads the latest effective per-person/per-order change, defaulting to `staff_given`. Assessment `med_scope` can retain requested Self-managed/Prompt while the effective change remains Administer pending agreement. |
| Consent withdrawal | `consent()` appends Administer with `effective_at=now`, keeping the person's reported `occurred_at` separate, and updates requested scope. Asking for more independence appends the unchanged effective mode and requests reassessment. A whole-person restrictive request still covers concealed medicines without exposing their identifiers. |
| New medicines and loosening | Unset orders read as Administer. `setSupport()` permits an unset medicine within the assessment cap/agreement only with explicit loosening confirmation. Established medicine loosening is rejected outside reassessment. |
| Score and controlled caps | Server computes the existing score result with willingness/consent first. `SupportMode::validate()` restricts editable controlled medicines to Assist/Administer and all medicines to the assessment cap. Concealed controlled support is preserved under the explicit approved mockup rule; submitted inaccessible IDs are denied. |
| Recording and projection | Projection and fallback readers use effective changes. An unrecorded owed slot today reads support now, allowing withdrawal to become actionable immediately; earlier days read support at their due time. The canonical administration model invokes SupportRecordingGuard after its existing order lock, preventing newly fabricated given/refused/withheld/missed outcomes for Self-managed doses. |

Candidate backend SHA-256 evidence: `app/Services/Medication/Support/MedicationSupport.php` `18FF139A57BC884F988E6251297F523CCF77CFF615443D28467521BDEBE58E40`; `SupportMode.php` `DBB8983CF6667CCA5C4BFCED2AE64466A3678B36229C3FEE2363CF0385406C5A`; `SupportRecordingGuard.php` `AAB4071420AE3FAB8614D47DA9F2DDB432741E928A7B073ECE1BF5D8D4AE7042`; `app/Services/Medication/DoseSlots/DoseSlotProjection.php` `159FFEC1AEDE5D76982E3A225A2176BC2B0EB7BBC2A833714E0F79FC8C11DA40`; `ScheduledDoseStates.php` `4166154569D6729470D22E2ABCB1B2EB5BFC700654A2E32B4895A253D2A3621A`; `app/Models/ClientMedicationAdministration.php` `A23FCA30EF776305EBE0DE3218B9AFD141D15F7329A0B51D2E62BE32AB244970`.

**P03 acceptance work remains:** integrate P08a's `reassess_support` type/lead ownership and source-authorised `completeFromSource()` before using the candidate at runtime; its adapter depends on those methods/mappings. Verify the actual recorder, overdue closure, generated-slot reader and fallback reader together, including an earlier-day Self-managed dose and today's overdue dose immediately after withdrawal. If legacy `MedicationDoseSlot.self_managed=1` rows exist without support-change history, the projection falls back to that flag while the writer/fallback reader default to Administer; reconcile that existing data boundary or remove the inconsistency deliberately rather than inventing historical support. Current generators/history write that flag false, so this is a conditional data assertion, not a confirmed production P1.

The candidate explicitly leaves consent offline as an unsaved in-memory draft. The approved P03 contract says the change saves on-device and sends later. Track durable queued consent and server-bound replay identity as an outstanding contract requirement, with actual reload/reconnect/uncertain-response tests; do not claim the mockup's offline flow is complete. No unsupported queue or role grant was added by this review.

## Review coverage and remaining verification

Initial working HEADs: P03 `390632233442882ade9670182dd36ae931646340`; P04 `9747cf7cb654c2ef441e8f60c7ea1b5918081925`; P05 `0ddf02daa26191ea67c8bb451f95bf155ad2aa97`; P06 `e5065f99c1d1c780fd766675daf20b4223e760f2`; P07 `53d833d6125c9630b13d3a6326890573a83b9047`. All had uncommitted package work when the pass started. Missing routes/pages/adapters in an actively written package are tracked as acceptance work rather than finished-code regressions.

Final checked heads at 23:00 NZ: P03 candidate `5212bb330069e333b7c8a175998264dab8c119bd`; P04 `f8ab349b37715be7232d282241e17be04c6d99b5`; P05 `be1263a76c225530195c4c4cc65c92d5fc900820`; P06 `307c9cdb12122968d7cdd4a0d31fd2d67b6d1b17`; P07 `53d833d6125c9630b13d3a6326890573a83b9047`. P05's ICR-04 query remains at lines 192–195 in SHA-256 `24BD5EE5FB0F78C7B0A3D78EE4CCEBAD3C4E46C0192E589BA9795AC0CF3C4A58`. P06's ICR-05/06 files retain their reported hashes; its stale-count fix remains at service lines 298–299 in SHA-256 `BDADD1D11A998B2337F5E88CB4E255813BEA464D68209C801610155BD58BCB95`, with revision validation at controller line 200 in SHA-256 `F775C52C85147E288A46D16B8469ECC9A5E96105B2EB37A19E6C1F91A8C394EE`. P04's ICR-07 controller retains its reported hash.

| Item | Disposition at the closing snapshot |
| --- | --- |
| ICR-01 P1 stale physical count | Addressed in source; concurrency verification pending. |
| ICR-02 P1 UTC reconciliation time | Normalization/namespace addressed in source; form round-trip and DST assertions pending. |
| ICR-03 P1 controlled recovery contract | Partly addressed; theft closure/notification paths remain open. |
| ICR-04 P2 review's effective version | Open. |
| ICR-05 P2 blind count rail | Open. |
| ICR-06 P2 live supply meter/selection | Open. |
| ICR-07 P2 stale order work list | Open. |
| P03 candidate core support rules | No P1 confirmed in the requested invariants; P08a integration, actual recorder/projection runtime and consent offline/replay acceptance pending. |

Reviewed source also retains the intended safeguards: P04 checked versions remain effective while edits wait; enterer/read-back/second-check identities are independently verified; P05 recommendations do not directly mutate prescriptions and concealed medicines get pending outcomes; P06 uses pack-level FEFO and explicit stock units without an invented dose conversion; P07 retains witnessed append-only reversal entries and independently restricts discrepancy resolution. These are source observations, not end-to-end release certification. Permission scope remains single-organisation/site/person based. The held P06 role expansion is preserved, and P07's manage migration is limited to the specifically approved team-lead/provider-manager roles.

No P0 confirmed. No tests/build/servers were run by this independent pass. Static source review does not certify integrated runtime behavior, database concurrency, 1440/1280/200% zoom fidelity, or retained mobile behavior; Main's isolated verification remains required. Main should reproduce each stated scenario against the final integrated head and revalidate changed hashes before closing findings or pushing.
