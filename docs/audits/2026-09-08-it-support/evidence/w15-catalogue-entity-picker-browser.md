# W15 catalogue entity picker — browser evidence and visual follow-up

12 September 2026. Functional browser checks below passed on build1225; the complete slice remains short of browser Verified because the highlighted-row contrast needs the recorded follow-up.

## Exact environment

- Build1225 exit0 after successful final TypeScript; Vite5m25s, entry `app-h_IqAC9r.js`, manifest `dc8ec99cc733e646a8f892001bdb744c9791b06c1c2bcedab09b186daf544c32`. Thirteen source/ten protected design hashes matched before bootstrap.
- CatalogueFixtures token `2c1c543f3e8b45a0`, fingerprint `cba8198a9ac75269f9557c8b8cf2eae85f7968b66b029772f1088292363575a4`. Bootstrap66348 completed exit0, runtime41740. Identity endpoint confirmed exact checkout/schema/assets, ordinary CSRF, array mail, sync queue and disabled SSR; see identity/preview/bootstrap artifacts.
- Owned in-app tabs37 and38 only, normal fixture sign-in, desktop layout without resizing. No real communication or provider operation. Screenshot was displayed inline; no image file is claimed.

## Actual functional journeys

1. Requester `w06-requester@demo.test` opened the published synthetic entities form. Employee and User each showed only their own current profile/account, with the approved Site label. Enter selected both without submitting the request.
2. Equipment initially showed50 permitted records. Repeated Enter on Load more produced100,150,200,then201 options. Focus stayed on the pagination button, including the final No more choices control with aria-disabled. No implicit equipment selection or request submission occurred.
3. Synthetic equipment202, assigned at unapproved SiteC, was absent from all pages. Searching202 returned an explicit no-match state. Searching the literal percent character also returned no matches, without broadening the search.
4. Searching201 found the permitted record beyond the original discovery limit. Escape closed only the choices, restored focus to Equipment, preserved Employee/User and left Equipment unselected. Reopening, searching201 and pressing Enter selected its readable name.
5. Submit request with Enter created IT-000008. The normal ticket page showed all three readable labels and the equipment tag in the original report. The pending state was observed before navigation; the original submission was not retried.
6. Opened another unsaved form and selected equipment201. In tab38 used ordinary Log out. Reopening choices in tab37 displayed the sign-in/retry error and concealed the old selected label, without presenting failed search as no matches.
7. Signed tab38 in as `w06-other@demo.test`, confirmed its header, then pressed Retry search in the old picker. The actor-bound read was refused; the choice list stayed empty and the old equipment label stayed concealed.
8. Restored the original requester through normal sign-in. Closed/reopened choices in tab37;50 current permitted choices loaded and the selected equipment201 label returned. A first string-match check was false because the combobox additionally had `[active]`; inspecting the retained actual DOM snapshot confirmed the correct label. No application state was evaluated. Escape closed choices and Cancel discarded this unsaved second form.
9. Both owned tabs returned empty error-level console lists. No network-failure interception or controlled in-flight browser cancellation is claimed; those cases have focused UI tests. These checks cover picker context; the complete parent requester wizard/actor-bound submission lifecycle is still W15 work.

## Persisted result and cleanup

Guarded inspector exited0; complete output is `w15-catalogue-entity-picker-browser-records.json`. Exactly one catalogue submission exists: item5, requester1, schema1, immutable publication4, result ticket8/IT-000008 at Site1. The original workflow1 still points to template1/version1 and remains pending. The unsaved second form produced no submission. This inspector does not read audit counts; no browser audit count is claimed.

Closed only owned tabs37/38. Cleanup49150 exited0. Independent postflight exited0 and confirmed the exact schema and owned directory absent. Working Herd environment and other databases were unchanged.

## Visual defect and required follow-up

The screenshot exposed muted text on the highlighted option. Inspection confirmed the title used `.text-subtle` and the detail `.text-caption`, both of which explicitly set muted foreground, bypassing the intended highlighted foreground pair.

After exact runtime cleanup, the picker now lets the title inherit the shared CommandItem typography, applies the approved accent foreground when selected, and changes the detail to the existing PeoplePicker small-text pattern with the same selected foreground. Rows also retain at least44px height. No protected design files or shared global primitives were changed.

Rebuild and inspect selected/unselected rows in the actual desktop browser before calling this correction Verified. The previously passing search, permission, pagination and submission evidence remains scoped to the recorded build. Full requester preview/WizardShell, requested-for/attachments/recovery, provisioning lifecycle and W15/E14 release acceptance are still incomplete.

## Contrast retest — 12 September, 18:58 NZ

Verified the picker correction on build40063 (exit0,5m32s), app-DsFi5S-p.js, manifest59c85de0dca47b79400674dce41d0c3a4dae3458206b9504c4c87d8b564eda4f. All13 source and10 protected design hashes matched before runtime creation. Scoped lint passed; the class-only change did not require a duplicate full logic test run.

CatalogueFixtures bootstrap6455 exited0 for token829c14a077024d3d/fingerprintfd38edd57ba892f43e1631b37bae5c01219b9a94d56529a30c577180fa0cc9fd, server27764. Saved identity confirms the exact checkout/manifest/disposable schema, ordinary CSRF, array mail and sync queue. One premature navigation created owned tab39 before the server listened; it returned connection refused. Selecting its browser-generated data error page was refused by Browser Use URL policy; that tab was not used for acceptance and its closure is not claimed. Ready owned tab40 used the real HTTP app and normal requester sign-in.

Actual desktop screenshot (1280x720, existing surface, no resizing) shows readable foreground text for both title and equipment tag on the highlighted purple row; ordinary rows retain readable primary labels and secondary tags. Screenshot was displayed inline, not saved to an image file. Keyboard Enter opened Equipment; search201 returned the permitted record beyond the first page. Enter selected equipment201, Employee and User each offered only the current requester. The selected equipment label remained visible while the other fields were chosen. Submit request via Enter showed a pending state, then navigated to IT-000008. Original report displayed all three readable labels and equipment tag. Error console was empty.

Guarded inspector exited0 and saved contrast-records.json: exactly one submission (item5/schema1/publication4/requester1/result ticket8) and one catalogue ticket at Site1; original pending workflow1 still retains template1/version1. This is bounded picker verification, not complete requester intake/recovery/audit acceptance. Full W15/E14 remains open.

Closed owned tab40. Cleanup81273 exited0; independent postflight exited0 confirms the exact schema and directory absent. No owned database or runtime remains. Working database, real provider configuration and protected design sources were unchanged.
