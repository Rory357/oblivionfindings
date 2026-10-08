# Workforce qualification rules — 8 October 2026

Status: saved as a local integration checkpoint, with native and frontend checks passing. Actual browser acceptance and main/GitHub delivery remain pending. This increment does not complete the full Workforce programme.

## User choices and resulting behaviour

Workforce settings now includes the organisation's handling of an unrecognised mandatory qualification. The default is **Warn — authorised manager may proceed**; authorised settings staff can select **Block until resolved**. Proceeding under Warn still requires the existing manager override permission, acknowledgement and reason. A recognised mandatory qualification that is missing or expired remains a blocking failure.

The House qualification default is **Choose per requirement**, as selected by the user. Settings also offers **Every worker** and **Minimum number of qualified workers** as defaults for new requirements. Existing requirements and copied requirements retain their stored choices, including unresolved legacy choices. Minimum coverage requires an explicit positive whole number; the application does not silently insert one.

House and Client requirements can be linked to a recognised HR qualification. Client requirements retain their exact Client and service context. Checks use current qualification evidence over the complete duty window. For minimum House coverage, distinct qualified workers must cover each part of the required interval. Planning can warn while the team is being assembled; publication blocks mandatory coverage shortages.

## Scope and recovery

Shared settings use reviewed before/after values, an explicit reason, current permission and source checks, saved history and durable eligibility rechecks. A staged recheck is not presented as completed delivery. Control Room and independent eMAR work are unchanged. Authorisation remains single-tenant: current roles, approved Sites, canonical records and privacy boundaries.

House create, edit and delete return a result bound to the current actor, request, Site, requirement, action and complete saved values. The backend checks actual persistence and the corresponding durable refresh state. The frontend closes only after a matching result and reads the current requirements. Unknown outcomes retain the draft or confirmation and require an explicit current read; that read does not claim to prove the earlier save. Duplicate submissions are held, and changed entries are protected during close/navigation. No global observer, permission or refresh-service contract was weakened.

## Verification

- One final native run passed **173 unique cases / 2,984 assertions**, with zero failures, errors or skipped cases. Both test and launcher exits were zero. It includes 48 House, 26 settings, 32 Client, 27 qualification-evidence, 19 House-outcome and 21 preservation/access cases.
- Independent cleanup verified the owned native processes, private schema and connections were gone, all 10,948 frozen entries unchanged and five protected previews untouched. Receipt: `test-results/workforce-main-qualification173-final-receipt.json`, SHA256 `d33782a5897dfb60407204c2c43293133c1fe41e92943d6fc5aaf5654043e8d1`.
- The original frontend package passed 77 cases. The final House follow-up passed 25 cases across two suites; 74 unaffected prior cases remain separate evidence. No combined 99-case run is claimed. Four-file follow-up lint and full TypeScript passed. The first TypeScript attempt exhausted its explicit 2,048 MiB heap cap; the 4,096 MiB attempt passed.
- The normal production build passed in 5m3s: `assets/app-BAdVAt7Q.js`. All 120 preview source pins matched before and after. Build receipt: `test-results/workforce-qualification-browser-build-proof.json`, SHA256 `f8caeb36832ee748f66413e01154ed3f1b4c2f6006b03cbbc5feb964ba6c71af`. The existing large-chunk advisory remains.
- Earlier failed native attempts remain archived and excluded. Corrections were an explicitly named short Client foreign key, the House fixture's required weekday and the evidence fixture's full-row baseline. Every domain assertion was retained. Isolated missing-environment warnings are disclosed.

## Browser acceptance still required

The reviewed isolated preview uses a fresh owned schema, normal seeders and synthetic accounts with canonical permissions. It does not modify shared preview grants. Required journeys are recorded in `test-results/wf32-browser-acceptance-20261008.md`: settings persistence/history, House and Client CRUD, Warn/Block/mapped failures, minimum coverage publication, restricted roles, Template writes, mobile layout and recovery.

The first resource admission stopped before any process/schema was created. Once independent workloads completed, setup reached an adapter import-order error before application or database bootstrap: `wf32BrowserAcceptance()` had not been loaded. The exact owned server 397916 was stopped; cleanup confirmed no schema or connections, with all five protected previews unchanged. This is a preview-tooling failure, not browser acceptance. Its full evidence remains in `test-results/wf32-template-browser-disposable-runtime-20261008`; the corrected attempt must use separate ownership.

Remaining programme work includes Template Apply/recurring commands, the separate modified-duty approval decision and the cross-module acceptance matrix. None is silently certified by this qualification increment.
