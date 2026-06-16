# Health & Safety Redesign — Workstream Plan: The other 8 wizards (WS7)

> Plan per `HEALTH_SAFETY_REDESIGN_LOOP_PROMPT.md` §4. Specs = `PROTOTYPE_DIGEST.md` §4.2–4.9. NZ-only.
> Multi-batch workstream — the generic engine lands first, then wizards convert from navigate→in-place a batch at a time.

## 0. Goal
Convert the launcher's 8 non-incident tiles into in-place Add-Client-style wizards on the same chrome, posting to their existing endpoints.

## 1. Engine (config-driven — done)
- **`components/form-wizard.tsx`** — `HsFormWizard`: one WizardShell engine driven by a declarative `WizardConfig` (steps → `FieldSpec[]`). Field types: text/textarea/date/time/datetime/number/select/segmented/tiles/chips/toggle; `source: sites|clients|staff` pulls options from reference data. Required-field validation per step, completeness meter, auto Review step (`key: 'review'`), posts `{…values, stay:true}` → `WizardSuccessPane`.
- **`components/wizard-configs.tsx`** — `WIZARD_CONFIGS` registry; field keys match each endpoint's validation so values post directly.
- **Reference data:** dashboard payload adds `staff` (alongside `sites`/`clients`); passed as `refData`.
- **In-place:** each target controller gets a `stay` flag → `back()` (the launcher tile flips from `href` to `inPlace`). Launcher → `onWorkflow(key)`; dashboard renders the matching wizard via `activeWizard`.

## 2. Wizard batches (status)
| # | Workflow | Endpoint | Status |
|---|---|---|---|
| 1 | Report incident / near-miss | `POST /incidents` | **done (WS6 — bespoke ReportIncidentDialog)** |
| 2 | Record first-aid | `POST /health-safety/first-aid` | **done (batch 1)** + stay |
| 3 | Record emergency drill | `POST /health-safety/drills` | **done (batch 1)** + stay |
| 4 | Log restraint event | `POST /health-safety/restraints/events` | todo |
| 5 | Log hazard + risk assessment | `POST /hazards` (sites.hazards.store) | todo |
| 6 | Injury → return-to-work (ACC) | `POST /health-safety/injuries` | todo |
| 7 | Add hazardous substance | `POST /health-safety/substances` | todo |
| 8 | Lone-worker check-in | `POST /health-safety/lone-workers/sessions` | todo |
| 9 | Worker participation / committee | `POST /health-safety/worker-participation/...` | todo |

## 3. Per-wizard recipe (remaining batches)
1. Read the endpoint's `store` validation → field contract.
2. Add a `WizardConfig` (keys = field names; `source` for sites/clients/staff selects).
3. Add a `stay` flag to the controller (`back()` when `$request->boolean('stay')`).
4. Flip the launcher tile `href` → `inPlace`.
5. Verify types/lint.

## 4. Verify
- php -l (each controller) clean; `npm run types` H&S-clean; `npx eslint` clean incl. raw-colour guard. Browser drive-through + each wizard's submit → post-merge.
