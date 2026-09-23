# PKG-02B v13 — B01–B03 correction evidence

22 September 2026. Synthetic desktop preview only. Candidate returned for Main review before the user design gate; no implementation, integration or activation approval.

## Authority and scope

Main's v12 review and Designer handoff revision 4 requested these three bounded corrections. Actual continuation configuration was verified before writes from turn_context at 03:28:33.935 UTC, turn `01a0c728-7a05-7050-8e31-0ba7ef189671`: model `gpt-6-astra`, reasoning and collaboration effort `xhigh`. Same Designer task/worktree/branch/base. No workers or subagents.

Only v13 preview/evidence and an external delivery receipt were written in this continuation. Prior guide additions remain read-only; their three hashes match Main's stated values. All 497 entries across the ten available earlier manifests (v1, v4–v12) match bytes and SHA256, including all 67 v12 and 109 v11 entries. v2/v3 have no manifest at these paths; no files under any v1–v12 directory were edited. See preservation-check.json.

## B01 — unresolved evidence and readiness

The preview-only readiness-contract.ts now treats applicable outcomes other than Passed/Recorded as unresolved, irrespective of retained references or future dates. It also retains missing, failed, expired and unknown-applicability checks. Not applicable requires a recorded basis and bypasses evidence/coverage use checks without deleting older evidence.

operations.tsx reuses the same compliance assessment for the booking request route, explicit approval, checkout and independent release. main.tsx uses it for readiness, vehicle-calendar.tsx receives the resulting readiness label, and studio.tsx uses it for evidence tone/icons. The booking workspace states the blocking reason; requests may still be saved pending review.

Browser observations:
- Ready scenario → WoF Needs assessment, retain reference/future date → header Needs assessment; calendar agrees.
- Approval not required with authority reason and acknowledgement → Pending approval, not Confirmed.
- Explicit Review & approve → blocked with “WoF: assessment is unresolved.”
- Restore Passed → explicit approval succeeds. Change back to Needs assessment after confirmation → checkout blocked.
- Independent Release for use → blocked by the same unresolved WoF reason.
- Clear evidence reference and due date while Needs assessment → observation saves successfully, remains unresolved. No fabricated evidence required.

Evidence: B01-*.txt and B01-approval-blocked.png. These are actual UI saves/transitions, not just status snapshots or pure helper assertions.

## B02 — recorded RUC coverage

Before correction, independently reproduced on v12: Ready scenario; record RUC 80,000–82,000 km while retained manual reading is 82,460 km; request a 22 September 09:30 booking; explicit approval saves Confirmed and checkout saves Checked out. B02-v12-approval-baseline.txt and B02-v12-checkout-baseline.txt capture the resulting states. This newly reproduces the paths Main previously marked source-only; baseline release was not separately reproduced.

v13 uses the same recorded-coverage assessment for header, automatic confirmation, explicit approval, checkout and release. It retains the existing `observed > recorded end` boundary; equality is not redefined. It does not adopt a new legal threshold or infer applicability from fuel/type. Invalid ranges and missing observations require review. A checkout observation greater than the saved reading is checked too, explicitly identified as a checkout observation. Checks do not replace or rewrite original readings. Tracker estimates remain separate in Mileage/planning and are not promoted to observed dashboard evidence.

Browser observations with the exhausted 82,000 km range:
- Header Needs assessment; compliance describes the recorded-odometer overrun.
- Approval-not-required request stays Pending approval; explicit approval is blocked.
- Restore coverage to 90,000 → approval succeeds. Enter checkout observation 90,001 → blocked with that exact source/value.
- Change recorded coverage back to 82,000 after confirmation → checkout at 82,460 blocked.
- Independent release is blocked with the same coverage reason.
- Keep restricted remains savable. Record Not applicable with an assessment basis → valid independent release succeeds. Old range evidence remains retained; final display says Not required · basis recorded, not active remaining coverage.
- The subsequent valid checkout succeeds at 82,460 with the source-backed Not applicable assessment.

Evidence: B02-*.txt, B02-checkout-blocked.png and positive-*.txt. No tracker/provider/vehicle actions occurred.

## B03 — booking time correction

The general toolbar default is 22 September 10:00–11:00 against the fixed 09:30 preview clock; a future selected day keeps 09:00. Explicit calendar context selections retain their date/time. The existing past-context menu continues to disable creation actions.

Time validation is shared between the time step and final submission. It returns to Vehicle & times and requests focus on the offending date/time trigger. Browser changed the default to 09:00 and pressed Continue: stayed on step 1, showed the past-start error, and DOM activeElement was `op-start-time`, label “Pickup / block start time: 09:00 AM”. Correcting to 09:30 allowed progression. Screenshot and read-only DOM evidence are in B03-time-error-focus.*.

Existing booking Change times was changed to an earlier 09:00 pickup with a change reason, then saved successfully; its existing approval-not-required route re-confirmed only with valid readiness and acknowledgement. Checkout then succeeded. This preserves supported historical edits while new past records remain blocked. B03-historical-edit-saved.txt and positive-not-applicable-checkout.txt record the result.

## Verification and limits

- TypeScript noEmit: pass.
- Vite final build: pass. Existing mixed Leaflet import and bundle-size warnings remain.
- readiness-contract.test.mjs: 27 assertions passed for evidence states, boundaries, Not applicable basis, retained provenance, booking defaults, historical edits and field targets.
- The inherited document-workflow regression was copied to target v13 imports and passed: dates/owners, grouped uploads, edits/replacements, reminder identity/pause and source boundaries.
- Browser transition checks described above passed. Final bundle identity and not-applicable display were rechecked after the final display-only adjustment. Final assets: index-CoWuwo4M.js and index-BaqBSZ-_.css. Screenshot final-compliance.png and final-build-identity.json identify the rendered candidate.
- Browser error log: zero during this verification. No broad CI run or wait; no production integration, real delivery, hardware/protocol validation, native file-picker transfer, native Excel image rendering, unchanged export-engine rerun, or genuine browser zoom claimed. Desktop screenshots are not zoom evidence. Earlier PDF font/transliteration and other production prerequisites remain open.

The broader shared geofence, universal checklist, catalogue, shared calendar/Tasks, GV500CG capability/provenance, Control Room, score policy, road-limit licensing, evidence/Finance and export contracts in Main's v12 review remain unchanged. Earlier Maintenance and Client Location operating acceptance remain open. Single organisation with roles, approved sites, canonical ownership and privacy; no tenant boundary added.
