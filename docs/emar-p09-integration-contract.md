# P09 integration contract

Owned checkout: `codex/emar-p09-reporting`, product base `9747cf7cb`.
Approved design: P09 v1 at `57c2d221be9c720bfb770faabe81a456f37dd5ed`;
approval `806334f96`. This is a single-organisation application. Current
permissions, approved Sites and canonical person/medicine ownership govern access.

## Reports and downloads

- Hub: `GET /emar/reports`, shared workspace: `GET /emar/reports/builder`.
- New grant `medications.reports.view`; generic `reports.viewAny` does not open
  either surface. Finance receives aggregated stock only. Auditors can export
  the audit trail with `medications.audit.export`, independently of clinical CSVs.
- Shared record-page prompt: import `MedicationExportButton` from
  `@/components/emar/medication-export-button`; pass `clientId`, `dateFrom`,
  `dateTo`, and `type="mar"` or `type="doses"`. House/day callers pass `siteId`
  and `type="round_sheet"`. Existing page permissions still determine whether
  the button is offered.
- The button gets scoped context from `GET /emar/reports/export-options`,
  opens the same purpose/review dialog used by the hub, then POSTs
  `/emar/reports/export`. Types: `mar`, `cd_register`, `round_sheet`, `doses`,
  `errors`, `stock`, `audit`. The first three return buffered PDFs; the rest CSV.
- Request fields: type, optional site_id/client_id/medication_id; period
  (`custom` with exact date_from/date_to); purpose (`care`, `review`, `audit`,
  `incident`, `records`, `other`), purpose_detail for other, kind/q for audit,
  include_in_error for errors. Dates are NZ calendar dates. PDFs are at most
  31 days, round sheets one house/day, screens at most 12 months. No clamping.
- Retained PDF/CSV GET routes have purpose and final-scope middleware, including
  both medication route families and single-event audit exports. The legacy
  round-sheet route delegates to the new house/day implementation. P02 owns
  the historical correction in `EmarPdfController`; P09 has not edited it.
- Final release locks current RBAC/Site/person/medicine-classification evidence,
  rereads the complete exported data and compares its digest, then appends the
  export event with the chain head last. Nothing is released if access/data
  changes or recording fails. Existing streaming CSV callbacks are buffered.

## Clinical and settings seams

- Recorder API and transaction/deadlock rules remain in
  `docs/emar-p09-event-recorder-contract.md`. Main wires domain writers serially.
- P08b close uses `RecordsReportingSettings::confirmation(reached, harm, value)`
  inside its locked close command. Store confirmed_sac, sac_confirmed_by,
  sac_confirmed_at. `preselection()` proposes a value; it never confirms one.
  Severe/permanent has no preselection and accepts explicit 1 or 2 only. Near
  misses have no SAC. SAC starts off, retention starts at 10 years after last
  service, both unreviewed through P11. No AppSetting is inserted by definitions,
  and no retention prune runs here.
- P06 cost fields, P05 review lifecycle, P07 loss/destruction/count schema and
  P08b occurred-at/in-error vocabulary need reconciliation against their final
  commits. Facts must remain unknown when the source is unknown.
- Main integrates the retirement of dashboard ReportsModal/AuditLogModal triggers
  and the controlled register's overlapping Audit Trail view serially with the
  owners of those large page files. Settings retains its alert log.
- P10's downtime action will be mounted in Print & exports after its endpoint
  commit, using the shared purpose flow and its own authorised pack service.

## Verification status

Recorder prerequisite `d0c3d2b19`: 10 executed tests, 362 assertions, exit 0;
2 passed plus 8 warning-marked tests, no failure or skip (compact output).
The new focused reporting/recorder command includes warning details and runs
only through the shared heavy-command gate in an isolated test database.
PHP and new TSX syntax checks pass. Full frontend and browser checks belong to
Main's combined integration gate. This contract is not a readiness declaration.
