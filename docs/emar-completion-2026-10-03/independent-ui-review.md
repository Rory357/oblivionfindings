# Independent UI/UX completeness review — active P11/P03/P04/P06/P07

Source-only review, completed 3 October 2026, approximately 23:08 Pacific/Auckland. This review reads the approved mockup source and active application source. It does not certify rendering, browser navigation, focus restoration, 44 px hit areas, or end-to-end saves. No server, build, database command, browser session, or application edit was performed. Main owns integration and runtime verification.

The application boundary is one organisation, approved sites, roles/permissions, canonical person/record ownership and privacy. This report neither expands the held P06 receive/house-lead grant nor the held P01 unassigned-round authority. Routine approvals remain delegated; there is no new approval gate here.

## Findings requiring correction in the inspected implementation

### UIR-01 [P2] P07 can count an open safety task which its lists cannot retrieve

**Evidence:** `app/Services/Medication/Controlled/ControlledProductPayload.php:27,55–62,102–104`, SHA-256 `32318AE6DE99884710633149C4101142AD5D2715C053DA2DCB19BDDA8BABA410`, in the P07 worktree listed below. Discrepancies, losses, destructions and overrides are each selected newest-first and capped at 500 **before** open/closed state is considered. Open discrepancy/loss/awaiting-receipt totals count the full query. `resources/js/pages/emar/ControlledRegister.tsx:121–129,437–455` then filters the bounded arrays locally; `WitnessOverrides.tsx:56–76` does likewise. `components/emar/controlled/product-client.ts:105–117` refreshes the same endpoint without a cursor or state-specific query. No older-record retrieval control is implemented in these lists.

**Trigger:** leave discrepancy A open for a medicine, then accumulate 500 newer resolved discrepancies for the same medicine. The discrepancy meter can report one open item, while following it produces no open row for A. A search or house filter cannot recover a record absent from the payload. The same selection order can strand an older unresolved loss or pharmacy destruction receipt; an older waiting/sign-off override is also vulnerable. This is a present read/list defect, not a complaint about an unfinished Meds today adapter.

**Required change:** retain all outstanding work independently of bounded closed history, or provide real server pagination/search that can retrieve it. Keep bounded-history captions truthful. The inspected update now supplies `history_has_more.overrides`; that earlier caption omission is **not** a current finding. A “more records exist” caption alone does not provide a way to complete an open task.

**Acceptance:** place each outstanding task behind 500 newer closed records. Its meter must lead to a reachable row and the permitted resolve/receipt/sign-off action. Verify both the register and the medicine-specific route. Preserve site/person ownership and independent-resolution restrictions. Approved P07b provides actionable Open / With a manager / Closed and waiting-receipt views, rather than a newest-record-only work queue.

### UIR-02 [P2] P03 assessment errors navigate to a step which does not contain the field

**Evidence:** `resources/js/pages/emar/support/_dialogs.tsx:319–323,348–360,364–375,694–701`, SHA-256 `A002397E7A4F991E96662EB8FA43650426E7E2C0345C4DAAC5EF29F3265F2443`.

**Trigger:** complete the person and score fields, leave storage blank, confirm the assessment, then save from Review. Validation creates a `storage_location` error but sends the worker to step 0. The storage control lives on step 3. The server-error mapper similarly recognises score and medicine-scope errors, then sends every other error to step 0; `confirmed_with_person` belongs to the review step. Values survive, but the first-error focus cannot land on the absent field. The worker must discover and revisit the correct step manually.

**Required change:** map every field/error prefix to its actual step, use the earliest invalid step, then focus its mounted control. Storage/reassessment errors belong to Storage & next review; the confirmation belongs to Review & save. Keep the draft and discard guard.

**Acceptance:** reject storage, reassessment and confirmation separately, including server-shaped errors. Each rejection opens the relevant step and makes the correcting control visible and focused. P03's approved checklist explicitly requires retained values and first-error focus.

### UIR-03 [P2] P04 sends every rejected order back to its source step

**Evidence:** `resources/js/pages/emar/orders/_entry.tsx:66–71,81–99`, SHA-256 `7E5B68F41C0EE6F5D9D59EB003CF75BB4BBF8BBAE1D50BC78FE9BED649747230`. The save callback is `onError: () => setStep(0)` regardless of the error key. Medicine details are on step 1; a spoken order's read-back witness/PIN is on step 2. The visible PIN error is rendered only on that read-back step.

**Trigger:** submit a spoken order with an invalid read-back PIN, or an order rejected for a prescription field. The form preserves values but returns to Where it came from, leaving the control to correct off-screen. There is no first-invalid-field focus in this callback.

**Required change:** map source/attachment errors to Source, prescription/change errors to Medicine, and spoken witness/read-back errors to Read-back. Mount the correcting pane before focusing it. Do not turn a rejected submission into a closed dialog or a successful result.

**Acceptance:** send the actual order form with a rejected PIN, invalid dose time and missing source evidence in turn. Each error opens the correct pane, retains all other fields/files, and allows correction and retry. This is the approved P04 retained-draft/first-error contract, independently of the clinical controller findings.

## Contract and integration work still outstanding

These are acceptance gaps or active integration work, not additional claims that unfinished wiring is an implemented regression.

| Package | Source observations | Remaining acceptance work |
| --- | --- | --- |
| P11 | Uses the shared header, scoped search, meter targets, section navigation and settings review/draft model. The new alert log has cancellable partial reads, explicit loading/error/empty states, truthful pagination and one menu definition for table/context actions. Emergency policy copy names one person, the duration/extension limits and review obligations. No new release-blocking UI defect was confirmed in this pass. | Browser-check that changing log filters/pages preserves an unsaved settings draft, stale responses cannot replace current results, concealed alerts expose no source action, and readonly users retain readable histories. `Settings.tsx:781,814` still labels every date “NZDT”; use Pacific/Auckland or a date-derived NZST/NZDT label for year-round truth. This is carried-forward display copy. |
| P03 | Assessment, agreement, per-medicine support and change-history source exist. Named guardian reassessment prefill is now decoded correctly (`_dialogs.tsx:212–215,247–250`); the earlier draft concern is withdrawn. Medicines without a usable assessment/agreement retain explicit support explanations. Desktop register menus share actions. | Connect the register and Support section to the approved P02 hub/person shell: the current standalone `SelfAdmin` header and `SupportRecord` rail do not retain the approved common hub/record navigation and meter context. `SupportRecord.tsx:103` also uses `replaceState(null, …)`; apply the navigation correction already assigned under integration finding F7 here too. Mobile register cards (`SelfAdmin.tsx:342–377`) provide only Open support plan, without the desktop kebab/context action set; restore menu parity from one action definition. Finish approved offline consent/replay work already tracked by the clinical reviewer. |
| P04 | `Orders.tsx` now exists and is connected by `MedicationOrdersController`; do not report it as a missing page. The header contains scoped search, clickable six-meter row, house/show filters and a five-view rail. Row/context actions share one definition. Current checked versions remain explicit while a proposed change waits. Legacy dispensing/supply records remain reachable via `/emar/prescriptions/legacy`. | Correct UIR-03. Scheduled times still require comma-separated `HH:mm` text (`orders/_entry.tsx:88`), rather than the shared clock/manual interaction; compose repeatable shared time selections while retaining the prescribed schedule. Successful entry currently closes immediately (`:68`), without the approved success pane/next-action explanation. Verify keyboard focus and draft handling for all order/covert/reconciliation saves. The approved P04 scope is desktop; this review does not invent a new mobile acceptance scope for its management tables. |
| P06 | Pack receipt retains entered fields, separates the saved receipt from optional photo upload and provides photo retry. Stock tables retain shared action/context definitions. The new pack workflow is routed separately and gated by `stock_lots_enabled`; existing stock/supply access is retained. `days_supply` is explicitly unavailable rather than guessed from dose units. | Integrate the pack reader/writers, dose consumption and alerts together before enabling the gate. Preserve the held receive-role boundary. Cross-reference ICR-06 for the confirmed out-of-stock/low filter and meter mismatch; do not create a duplicate assignment. ICR-05's blind-count rail is now guarded in commit `47cc811…` (`_dialogs.tsx:134–155`): source-level addressed, keyboard/browser verification still pending. Runtime count concurrency belongs to ICR-01's separate owner. |
| P07a/P07b | The register and witness-override pages have real read models, shared header/search/filter controls, clickable meters, permission explanations and retained-history captions. `RecordList` has separate desktop/mobile surfaces with the same action/context menus. Missing count cadence is called Not configured. Load/offline/retry feedback retains the form. | Correct UIR-01. `ControlledChecks` exists but has no consuming Meds today import in the inspected P07 tree; the register/override meter links to `/meds/today?view=controlled` still require the P01 rail/body/data adapter. Wire and verify count, witness request/reply and later sign-off from that landing view. Main must also verify the actual form-shaped P07 action payloads after ICR-03 correction. Existing prototype actions alone are not task-completion proof. |

The current P03 agreement witness uses a Select. The approved P03 checklist explicitly allows the short, bounded on-shift witness list; do not replace that solely because Select exists. At integration, confirm the real server list is still bounded to eligible witnesses. A growing staff directory would require the searchable scoped selector under the current Rory contract.

For every worker-facing landing page/dialog, Main's browser pass should measure actual hit areas at the user's 13/14/16 px font settings. `min-h-11` or a class/comment naming 44 px is insufficient. Check modal close, rail steps, table/card menus, pagination, meter targets and error links; preserve picker-first Escape and dialog focus restoration. `DESIGN.md:698–709` requires rendered measurements. This report does not claim those checks passed.

## References and exact inspected source

Approved designs were read by immutable Git ref; working branches with similarly named designs were not treated as approval evidence:

| Package | Approved design ref | Directory |
| --- | --- | --- |
| P11 v5 | `12ecb24a2445faab3816471864e41d55b21f1c56` | `docs/emar-design/P11/v5` |
| P03 v1 | `9822d78b47bf71c27c2ff424079c52351cf9076c` | `docs/emar-design/P03/v1` |
| P04 v1 | `24d230ed94b6295f27901f0eac27bb8180be89ac` | `docs/emar-design/P04/v1` |
| P06 v1 | `871f06c3adc523d51c70e6147ff2e20dac1b3db0` | `docs/emar-design/P06/v1` |
| P07a v1 | `8520c08b4749089ac4f9e36d4b54360f8c5a9968` | `docs/emar-design/P07a/v1` |
| P07b v1.1 | `6fe3c0766c9c00d59579c0131921e0c254d1fecb` | `docs/emar-design/P07b/v1` |

Current primary `DESIGN.md` and Rory amendments govern shared primitives, placement, truthful meters, selector/date-time interaction and hit areas. Primary SHA-256: `DESIGN.md` `6F3461A67BA08662BB15E164CB662113FD5D1F3B10CFAAD6F94E3CC9A8238F2C`; `design_styles/POPUP_STYLE_GUIDE.md` `2B0CF90FE6D2F8697E59203533C0AB694F95AE7FF5F9A137D21C3BF3E485254E`. Also applied: primary `AGENTS.md`, `docs/architecture/single-tenant-application.md`, the chronological Approval-record, and `mockup-inventory.md`.

Worktree root prefix: `C:/Users/steph/.codex/worktrees/`. Every root below ends in `/oblivionfindings`. Files can contain uncommitted work; the hashes, rather than HEAD alone, identify the inspected UI. No edits were made in these worktrees.

| Package / root | HEAD at final inspection |
| --- | --- |
| P11 — `emar-p11-settings` | `fb540af09ef2b165498edc52335ac178e307f128` (UI completion commit `7ffe02da9cb45a1d25ebdb3bfe4e1c7d2852ba00`) |
| P03 — `emar-p03-support` | `5212bb330069e333b7c8a175998264dab8c119bd` |
| P04 — `emar-p04-orders` | `37618fbc44209465e7d560e2aefd9b9bff976882` + UI work in progress |
| P06 — `emar-p06-stock-pharmacy` | `47cc811731b7a33856c8fd42971f3cc81acd074c` |
| P07 — `emar-p07-controlled` | `8ccb4112e6d60204b4c107fc510cbb864f6c1511` + product work in progress |

Additional SHA-256 evidence (paths relative to the relevant root):

| Package | File | SHA-256 |
| --- | --- | --- |
| P11 | `resources/js/pages/emar/Settings.tsx` | `C266972B7FCC8DAB480B7E07EC189FD8D89E6C8F6ACD4A585D3DAF499A8D63B3` |
| P11 | `resources/js/pages/emar/settings/_alert-log.tsx` | `C082461965FAF115F199BDA41828A9977C6747D53583F97E42F372D6EF114387` |
| P11 | `resources/js/pages/emar/settings/_emergency.tsx` | `AF69375B8FB235BB11C070D01BC97C704AE2E9E790544E4FAABD8A28DDAA7B47` |
| P03 | `resources/js/pages/emar/SelfAdmin.tsx` | `3644BBEDE595A210FA0199F8B1055C096E8CFA52FF084EB10B135BEE37758E7C` |
| P03 | `resources/js/pages/emar/SupportRecord.tsx` | `D4D906649C4CEBBB84944ECA0820A0D390B758FECF5B81CA31F8680815E61C20` |
| P04 | `resources/js/pages/emar/Orders.tsx` | `8865C03A0F0F1A08A4AB73ED80ABF53771AA531A391CA3DDC905F904FF75B050` |
| P04 | `resources/js/components/lists/entity-table.tsx` | `A6F0BAB9141BF65D7ACB9D31552B3C4B3EE4299B3D47D17B7B8550F64765FDF1` |
| P06 | `resources/js/pages/emar/stock/StockHub.tsx` | `8A79C9E49354C49503EB60050F077BAC508522EBB37E0E541070F07F9D204755` |
| P06 | `resources/js/pages/emar/stock/_dialogs.tsx` (guarded revision) | `D221F3E9E03446D503749FFE9EB86671838B546558D002C11479D7AD35A241B6` |
| P06 | `resources/js/pages/emar/stock/_receive.tsx` | `11104C4A30661E5252B8F9A30CDFD51105D0EB77FFD9587E83E356888E6133E3` |
| P06 | `app/Services/Medication/Stock/StockReadPayload.php` | `A7FA43DE7DA4E17B8CE404422578128D31B21C5EFA7B69177DC2EA21CFFA0427` |
| P07 | `resources/js/pages/emar/ControlledRegister.tsx` | `BF84AE999E7010C9543F5C32A769408EC696B0752AC53936EDC9E8DEA084719F` |
| P07 | `resources/js/pages/emar/WitnessOverrides.tsx` | `8BABFA71966B265EAA825EA8D1A3E1A2DC5CF081C71E3BA6E0E44A8F143E9646` |
| P07 | `resources/js/components/emar/controlled/product-client.ts` | `D4AB891B46B3567512CA0501AAAACAFC9229BAC9F2F8A5E4A20DD50F3E9FEBBC` |
| P07 | `resources/js/components/emar/controlled/product-ui.tsx` | `13A5F095957B67811106BD655ABE0AEA21AA0B91584688A80027D50AF2C646C7` |
| P07 | `resources/js/components/emar/controlled/controlled-checks.tsx` | `AD7891747D13405D238AE0CF208A319F8B97FD169F53E19A83C0314FDD9A3EAE` |

## Existing review ownership and P01/P02 follow-up

The seven findings in `independent-integration-review.md` already have owners. This pass does not reissue them. P01's worktree reached `916cc6c44c840a9c5ca73ca963549e7937690704` through event/follow-up foundation commits above the original `6ca611155…`; P02 reached `7738f05d2ad93adac3a37d8869eab007426b5b62` through the event contract above `9957e19b3…`. Active P01 source edits are visible, but no published immutable remediation candidate was established for the seven findings in this pass. Re-review their exact fixed commits when the owners publish them; do not treat foundation commits or draft changes as closure.

Clinical findings ICR-01/02/03/06/07 and P03 offline/replay acceptance remain with `independent-clinical-review.md` and their existing owners. This report independently confirms the UI implications where noted, and records the newer P06 blind-count guard without duplicating the assignment. Neither report replaces Main's integrated task-completion and browser evidence.
