# W15 template version browser evidence — 12 September 2026

Scope: isolated desktop web journeys, owned in-app tabs 29 and 30, no resizing. Runtime token `946ded3259c44517`, fingerprint `84519e4491f11be8af7a02aac5beb122000fc68ad673848019304f11c3b2f8c4`. Identity before and after agrees with this checkout, isolated schema, normal CSRF, array mail, sync queue and manifest `14fa54053648ffaa8b57d6766871c2e971064e4075572adaeb0216af4d11a81b`. No provider action or real communication.

## Actual journeys

- Technician signed in through the normal form. Existing provisioning workflow displayed `Template v1` and its original verification step.
- Two tabs opened the same version 1 template. Editor A changed the name and task instructions, advanced through Details / Workflow steps / Review changes, reviewed Site, version 2, manual instructions, unassigned team, approval and evidence requirements, then saved. The Workflows tab stayed selected and `Template saved` appeared with the persisted version 2 card behind it.
- Editor B changed its stale version 1 draft and attempted Save. The server rejected the stale version; no success pane appeared. The draft name and original instructions remained visible alongside the reload action; the background card showed the actual version 2.
- Reload required explicit discard confirmation. Keyboard Enter on Cancel retained the stale draft. A subsequent explicit Discard loaded saved version 2 and its current name/instructions. A reviewed current-version save produced version 3.
- The attempted `fill('')` did not clear the input in this browser tool, so that save was an unchanged version 3, not evidence of required-field validation. This limitation was corrected by using Control+A / Backspace and inspecting the empty textbox before continuing.
- With the name actually empty, direct Review / Save returned to the focused Template details form with `Enter a template name.` and no save. A corrected name retained the old validation alert: confirmed UI defect, subsequently fixed by clearing only that field's error on edit. Focused tests pass; rebuilt browser follow-up pending at this checkpoint.
- Dirty Cancel showed the shared discard confirmation. Cancelling retained the unsaved name; an explicit discard then closed the wizard without another saved version. Rapid consecutive interactions initially targeted the disappearing confirmation; settled DOM was reread before the final action. No repeated write was issued.
- Original workflow still displayed `Template v1` after both later template saves. Read-only database reconciliation confirms three immutable author-saved versions, current pointer 3, workflow pointer 1, and unchanged original copied notes, approval and evidence flags. The stale draft name and cancellation name were not saved. See `w15-template-version-browser-records.json`.
- Normal logout/login as the requester without `it.manage` returned 403 for the direct setup URL. Navigation back to the permitted service desk recovered successfully; setup navigation and template contents were concealed. This is role denial/recovery evidence, not a timed session-expiry or live revocation test.
- Desktop screenshot inspected in tool output: shared WizardShell rail, review cards, readable field values, internal content scrolling and fixed save/back footer. No mobile or viewport-size claim.

## Loading observation and cleanup

Editor A console errors: none. Editor B recorded one `Failed to fetch dynamically imported module` for `index-5OzKqRkz.js` at 04:39:14 UTC during its first blank navigation. A subsequent ordinary navigation recovered. The file existed and the manifest hash remained unchanged afterwards. Root cause is unproven; this initial failure is not represented as a clean-console pass.

Both owned tabs closed; existing user tabs preserved. Cleanup session 21198 exited 0 and reported only the owned schema/directory removed, no Herd change. Independent cleanup postflight exited 0: schema absent, owned directory absent, no database mutations performed. Bootstrap session 83737 had exited 0 before the journeys. No owned test runtime remains.

This evidence verifies the recorded version/edit/recovery behaviours only. Full W15 remains incomplete, including durable uncertain-create recovery, intake and remaining provisioning lifecycles.

## Rebuilt validation follow-up — 16:53 NZ

Fresh runtime `1722a9ce12644520`, fingerprint `baa0d7ad81f4a3807652d17dbcce42785ac63bcca87d94b64cdbc153bde04397`, owned desktop tab 31. Identity matches this checkout, normal CSRF, array mail, sync queue and rebuilt manifest `1233805704e8596d4e20c48616f19a46b413c3ea23fd5984837e466b2c29be9a`. Build 28137 exited 0 in 3m27s; subsequent TypeScript 27191 exited 0. Focused UI 15 tests / 2 files / 4.80s and scoped lint passed. Protected design hashes remain unchanged.

Normal technician login; opened fixture template v1. Cleared the name with Control+A / Backspace and inspected the empty textbox. Review / Save returned to Template details with the required-name error. Entering `W15 corrected validation name` immediately removed the obsolete error; the corrected value remained present. Review also had no stale error. Enter on Save produced the confirmed `Template saved` pane. Console errors: none in this tab. Read-only reconciliation confirms one template at version 2 with the corrected name, two immutable versions, and the original workflow still bound to version 1. See `w15-template-version-browser-fix-records.json` and identity JSON. Tab 31 closed; no user tabs closed or resized. Exact environment cleanup and independent postflight follow in the current progress note.
