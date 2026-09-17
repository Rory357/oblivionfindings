# W15 catalogue submission account binding — in progress

Confirmed gap: the existing catalogue form submitted values without the identity of the account that opened it. The field-search transport already checked that identity, but ordinary free-text submissions did not.

StoreCatalogRequest now requires actor_user_id, requires current approval and IT request/manage permission, and applies the existing BindsItBrowserActor comparison before the command. Missing identity fails validation; mismatched or malformed identity fails authorization. The form sends its original actor and remounts its private state on actor change. This does not replace current Site/item/result authorization inside the canonical submission service.

Updated31 existing HTTP submission test calls with their explicit actor so required identity cannot mask the existing publication, field, Site and replay expectations. Added service-request and provisioning cases for a permitted different login, missing/malformed identity, original-user completion and replay after a refused account switch. Added UI proof that account change removes the old private form, reopening starts empty and subsequent submission identifies the new actor.

Actual results: scoped Pint and Prettier pass; UI33 tests across3files pass in20.35s; scoped lint and source whitespace check pass. Backend full catalogue suite7995/tokenit_87333bf8d1534c25 is running after14 isolation checks. TypeScript then Vite90053 is running. No backend/type/build/browser pass is claimed yet. Hash bundle records4 source and10 unchanged protected design files. No new migration or provider action.

Next: inspect those exact process results, fix any regression, verify the fresh build in the isolated desktop browser with ordinary account switching, then continue full requester WizardShell/review/attachments/requested-for and canonical recovery. Full W15/E14 remains open.

Backend result: isolated suite7995 terminal0,29 diagnostic Test Passed and no Failed/Errored events. All14 postflight checks passed, including exact schema absent. TypeScript passed; Vite90053 remains live. This supersedes the pending backend/type notes above.
