# P07 policy integration notes

Working integration note, 3 October 2026 (Pacific/Auckland), based on product snapshot `9747cf7cb654c2ef441e8f60c7ea1b5918081925`. This is a bounded seam inventory for Main; reference designs and primary audit records stay read-only. One operating organisation across approved sites; no tenant boundary or clinical policy is added.

## Approved rules and policy interface

- P11 v5 `12ecb24a2445faab3816471864e41d55b21f1c56`, `docs/emar-design/P11/v5/src/settings.tsx` (`ControlledDrugs`): house choices are `org` (Follow the organisation), `on` (Always), `off` (Not required). The effective value is the house choice unless it is `org`; the organisation default is `on`. A house can independently remove the default witness check. The explicit order flag still wins.
- P07a v1 `8520c08b4749089ac4f9e36d4b54360f8c5a9968`, `src/pages/contract.tsx`: every shift change; due 30 minutes before, overdue 60 minutes after; actual house roster changes, not fabricated 7 am/3 pm/11 pm defaults. Until cadence is saved, nothing is due or overdue. Counts and movements remain witnessed regardless of dose policy or overrides.
- P07b v1.1 `6fe3c0766c9c00d59579c0131921e0c254d1fecb`, `src/pages/contract.tsx`: return to pharmacy is the default; on-site denaturing requires an explicit organisation policy and two witnesses. NZ class A/B/C is explicitly reviewed; do not auto-map historic schedule values.

`App\Services\Medication\Controlled\ControlledPolicy` is the canonical reader. `ClientMedication::requiresWitness()` delegates to `witnessRequired(ClientMedication)`. Other public methods are `doseWitnessRequiredAt(?int)`, `cadence(?int): ?string`, `overdueMinutes(): int`, `onsiteAllowed(?int): bool`, `countStatus(ClientMedication, DateTimeInterface, ?DateTimeInterface): array`, and pure `countStatusForChanges(cadence, now, lastCount, changes, overdueMinutes=60, timezone='Pacific/Auckland', notBefore=null)`.

Count status fields are `status`, `cadence`, `change_at`, `due_at`, `overdue_at`, `next_change_at`, `last_count_at`. Statuses are `not_applicable`, `not_configured`, `schedule_unavailable`, `upcoming`, `due`, `overdue`, `complete`. Dates include their offset. A count completed in the 30-minute due window covers that upcoming change; a future-dated count never satisfies it. The caller supplies the last completed witnessed count, never a draft. Current site shifts use direct `site_id`, with the existing canonical fallback to the primary client's site only when shift `site_id` is null; cancelled, draft and on-call shifts are excluded.

Main accepted daily timing as the earliest actual morning start (before noon) on the NZ date. Daily morning selection happens **before** filtering out changes before the order's entry. This avoids converting a later staff start into a second daily count for a newly entered medicine. Empty or fully filtered schedules return `schedule_unavailable` with no due time. Weekly has no approved weekday/anchor: return `schedule_unavailable` until that is explicitly configured, rather than drifting seven days from the most recent count.

## Remaining witness reconstruction seams

Line references are the initial P07 worktree version and can move as Main integrates. Replace clinical dose booleans with the canonical effective requirement; retain explicit order flags for order editing and retain historic attestation snapshots for audit. Do not replace every mention of `controlled_drug` indiscriminately.

| File / initial line | Existing reconstruction | Integration action |
|---|---|---|
| `app/Services/Emar/MedsBoardPayloadService.php:178,462` | raw order flag OR controlled status | Scheduled and PRN `requires_witness` use `$med->requiresWitness()`. Preserve access/concealment and administration-rule countersigning where the payload requires it. |
| `app/Services/GuidedRoundService.php:533` | raw order flag OR controlled status | `requires_witness` uses the canonical method. |
| `resources/js/components/clients/profile/emar-dialog.tsx:235` | controlled status OR raw flag | P01's shared recorder requirements replace this old dialog. If retained temporarily, consume a server effective `requires_witness` field; do not calculate policy in the browser. |
| `resources/js/pages/emar/components/mar-governance-dialogs.tsx:56-62` | `syringeDriverRequiresWitness` claims controlled always requires witness | Replace the helper's reconstruction with server effective policy, and remove the stale comment. Its `ChartMedicationOption` and producer must agree on the effective field. Syringe composition still applies any medicine-rule confirmation requirements. |
| `resources/js/pages/emar/components/add-medication-modal.tsx:152` | saves `witness_required: witness==='yes' || controlled==='yes'` | Preserve the person's explicit order choice only. Automatically setting the order flag for every controlled medicine makes organisation/house policy ineffective. Coordinate with P04's replacement order editor. Do not rewrite existing order flags or clinical history. |
| `resources/js/pages/fleet-assets/transports/create.tsx:443-444,1163` | server effective field OR controlled status | The controller already computes `witness_required` with the canonical method at lines 370/559. Use that boolean for the packing form and payload; controlled status still selects controlled-specific wording, permissions, PIN rules and register handling. |
| `resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.tsx:311-314` | selected effective requirement OR controlled status | Packing dialog consumes the server requirement directly. |
| `resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.tsx:1154-1156` | transit-log snapshot OR controlled status | For dose recording, consume a server **current effective** requirement (including the historic packing snapshot and countersign rules where applicable), or use P01 requirements. Removing the OR without supplying the current requirement can hide a newly required witness. |
| `resources/js/pages/fleet-assets/transports/medications.tsx:639` | transit snapshot OR controlled status controls packing-correction button | Use the authoritative packing-attestation requirement snapshot; do not invent one from controlled status. |
| `resources/js/pages/fleet-assets/transports/show.tsx:927` | same packing-correction button reconstruction | Same packing snapshot rule. |
| `app/Services/Fleet/ResidentTransportJourneyService.php:812` | packing correction allowed for snapshot OR controlled status | Use the packing snapshot/explicitly defined packing requirement, not a second controlled-dose policy. Existing snapshots stay intact. |
| `app/Services/Fleet/ResidentTransportJourneyService.php:1817-1818` | governed packing gaps SQL includes every controlled log | Use the authoritative packing `witness_required` snapshot rather than manufacturing historic missing-attestation gaps when packing did not require it. No historic snapshot migration is authorised here. |
| `app/Services/Fleet/ResidentTransportJourneyService.php:981-986` | historic log witness flag OR canonical current medication requirement OR rule countersign | This is already partly canonical. Preserve historic attestation obligation and countersign; remove no historic requirement casually. Ensure the frontend receives the same effective result. |

Already-canonical consumers include `EnhancedMarService.php:368,410,2271`, `RecordingContractEnforcer.php:137`, `DoseRecordingRequirements.php:280,748`, `EmarController.php:992,1507,1536,1570`, and Fleet service packing calls at 180/689/1537/1676. Preserve rule-driven countersigning in these expressions.

`EnhancedMarService.php:432`, `EmarController.php:2494`, `ClientMedication` order snapshots, and `MedicationOrderLifecycleService` carry the **raw explicit order flag**. Keep it for editing/versioning; add a distinct effective field to clinical consumers if needed. `DoseRecordingRequirements.php:886` uses controlled OR witness status to disallow offline replay; it is a separate stock/connection safety gate, not proof of a witness requirement. Do not loosen controlled stock/offline safety by mechanically replacing that expression. `AuditLogController.php:549` currently guesses historic witness requirements by event type and excludes counts/receipts. Prefer recorded event-time requirement metadata, not today's policy applied retrospectively.

## Completed narrow clinical and transport seams

The scheduled/PRN Meds board and guided round booleans now call `ClientMedication::requiresWitness()` directly. The isolated Fleet controller binds the already authorised canonical resident relation for available order payloads, including `client_id` where the prior select omitted it. It continues to send packing's effective `witness_required` field, and supplies a separate `requires_administration_witness` on both transit log payloads.

`ResidentTransportJourneyService::requiresAdministrationWitness(log, medication, lockForUpdate=false)` keeps the original administration rule: historical packing requirement OR current canonical order requirement OR current countersign rules. Read payloads do not lock rules; the recording transaction passes true and retains the publication mutex. An existing medication client relation from the locked mutation is preserved. Packing correction, missing-attestation queries and correction buttons use the recorded packing requirement without changing snapshots. The create/packing UI consumes the server current requirement, and the administration wizard consumes the separate effective administration field. Raw explicit order editing/versioning flags and controlled offline/stock guards remain intact. Remaining legacy dialog, syringe and order-editor seams in the inventory need their owning integration workers.

## Generic P11 Settings integration

`App\Services\Medication\Controlled\ControlledSettingsFragment::groups()` returns `MedicationSettingGroup` objects. It imports `MedicationSettingDefinition` as `Definition` and uses existing `MedicationSettingsRegistry::VIEW_RULES`; no new store or endpoint is needed. Main's shared registry patch can add `...ControlledSettingsFragment::groups()` in `MedicationSettingsRegistry::build()` with the fragment import.

Groups and storage:

| Group / key | Scope | Storage key | Values / current proposal |
|---|---|---|---|
| `controlled_witness.organisation` | organisation | `medications.controlled.witness_required` | `on` / `off`, default on |
| `controlled_witness.house` | site | `medications.controlled.house_witness` | `org` / `on` / `off`, default org |
| `controlled_counts.cadence` | organisation | `medications.controlled.count_cadence` | shift / day / week; no runtime value before deliberate save |
| `controlled_counts.overdue_minutes` | organisation | `medications.controlled.count_overdue_minutes` | 60; whole minutes 1-1440 |
| `controlled_destruction.onsite` | organisation | `medications.controlled.onsite_destruction` | off / on, default off |

All definitions are view `rules`, section `controlled`. Organisation values use `app_settings`; house values use `medication_site_settings` scoped to the canonical site. Definitions mark witness disabling, less frequent counts, longer overdue delays and enabling on-site destruction as loosening; generic `MedicationSettingsStore::apply` provides stale-write detection, mandatory destructive confirmation and history/audit. House `houseManaged` remains false: `medications.alerts.manage_house` is not authority to loosen controlled witnessing. Existing settings authority and approved site checks apply; `controlled.manage` alone is not a new settings grant.

Shared editor seams Main/P11 must integrate:

1. `resources/js/pages/emar/settings.tsx:293` still lists only overview/medicines/safety for rules. Add `controlled` and route its section to the P07 editor; `_nav.ts` already knows the controlled section label/icon. Match the frozen P11 ControlledDrugs rows while using current shared controls.
2. Use Settings draft/context `siteDraftValue`, `siteSavedValue`, `siteSlot`, the ordinary Review changes save and history; organisation and house rows keep distinct scope. Site rows need canonical visible site choices, not unscoped medicine counts. The generic controller registry resolution handles group/key/scope and rejects forged site IDs via `assertCurrentSettingsAuthority`.
3. `settings.tsx:470` currently grants group edits only with `s.can_manage_organisation` except alertExtras/quietHours. A house-controlled editor must expose site-scoped authority without accidentally granting organisation authority. `MedicationSettingsController` already checks settings.manage plus current approved site for site definitions; expose permitted site IDs explicitly if needed. Do not reuse the alerts-only grant to broaden controlled policy editing.
4. **Unreviewed cadence uses an empty sentinel.** The fragment now defaults cadence to an empty string, with exactly three actual choices. Main patches `_model.ts` `notConfigured` to recognise this option sentinel, alongside existing numeric off values. Selecting Every shift change changes empty to shift and writes a reviewed row; it is shown as a recommendation while nothing is selected. Keep today's value remains unavailable for this setting. Both backend `MedicationSettingDefinition::format('')` and frontend `_model::format` must display “Not configured” instead of blank in Review changes/history. The runtime still returns null until a valid cadence is stored.
5. The count-overdue alert description should read the same policy instead of a second cadence. Weekly must truthfully show timing unavailable until a reviewed anchor exists. It must not fabricate a weekly overdue time.

## Batching without stale safety values

Current runtime readers intentionally do not cache witness settings across writes. A long list using `ClientMedication::requiresWitness()` will otherwise issue repeated policy queries; `countStatus` will repeat roster loads per medicine.

For payloads, construct a bounded snapshot only for one payload assembly after person/site access is established: organisation settings in one query, house rows for visible site IDs in one query, roster starts once per site/window, latest witnessed counts in a grouped query. Reuse `countStatusForChanges` with each order's `notBefore` cutoff; never cache a status solely by site because last count and order entry differ. Do not make this snapshot a process/global cache, and rebuild it after a write if the same request returns a refreshed payload.

Mutation paths keep fresh authority, competency, PIN lock, stock and witness policy reads inside their transaction. If a request-scoped policy cache is later introduced, add invalidation for **both** `AppSetting` and `MedicationSiteSetting` writes/deletes and clear it after generic Settings apply/restore/keep. A scoped container binding alone does not prevent stale values during same-request settings changes. No cache or shared model invalidation seam has been added by this policy worker.

## Focused verification

`ControlledPolicyTest` covers defaults, explicit order flags, canonical model delegation, cadence not configured, due/overdue edges, early/future counts, daily selection and entered-at cutoff, empty/fully filtered schedules, NZ DST elapsed windows, on-site policy and loosening definitions. Pure tests need no DB/application boot. The temporary pure bootstrap points Composer's optimized `ClientMedication` classmap at the owned checkout so verification does not accidentally exercise primary's old model.

`ControlledLocalTimeTest` covers NZ offsets, DST gaps/folds, malformed dates/suffixes, explicit seconds, future-event prevention, optional default-now and UTC `Z` as an absolute instant. The parser accepts one to six fractional second digits, preserves microseconds through NZ candidate resolution, rejects overprecision, and rejects a timestamp even 1 ms in the future. Fractional UTC and explicit NZ timestamps pass at one, three and six digits; fractional DST gaps/folds are checked and even a future 1 microsecond is refused.

`ControlledTransportWitnessTest` covers historical packing requirements, current explicit order requirements, house overrides, current countersign rules, transaction rule locking and preservation of a fresh canonical resident relation. The three pure scripts pass **28 tests / 163 assertions** without application/DB boot. The bootstrap also points the Fleet service classmap at the owned checkout. Six affected PHP files passed syntax checks; six Settings/transport TSX files passed syntax parsing. The new focused transport UI regression case is authored but remains for Main's coordinated frontend verification slot. No functional DB suite, full typecheck, build or browser verification ran in this worker.

## Isolated Settings editor handoff

`resources/js/pages/emar/settings/_controlled-product.tsx` exports `ControlledProductSettings`. It uses only existing P11 context/draft helpers and shared Settings/UI primitives, with named Choice groups, 44 px choice targets and focus rings. It displays organisation and house witness policy, the three count cadence choices with a truthful Not configured state, overdue minutes and on-site destruction. It has no separate transport or save button; the page's Review changes flow saves its persistent draft. The fragment was formatted and its TypeScript grammar check reported zero syntax errors; full tsc, interaction/browser checks and shared wiring remain in Main's verification slot.

Minimal page integration (names for access payload may be chosen by Main):

```tsx
import { ControlledProductSettings } from './settings/_controlled-product';

// Within the existing rules/section rendering chain:
<ControlledProductSettings
    q={query}
    show={f.show}
    clear={() => {
        clearQ();
        setF({ ...f, show: 'all' });
    }}
    houses={props.sites}
    houseIds={controlledSettingsAccess.manageable_site_ids}
    readOnlyAudit={readOnlyAudit}
/>
```

`houses` contains approved visible houses only. `houseIds` must come from the server's current settings-management/site decision, not the alerts-only house-manager grant. Global organisation settings authority can edit approved house rows; otherwise only those IDs are enabled. Organisation rows still use existing `canEdit(group)` authority. Main adds the controlled section to rules navigation, imports/renders this component, wires the registry fragment and sentinel formatting; this worker has made no shared Settings-file edits.