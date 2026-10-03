# Integrated browser verification — in progress

Synthetic preview only: `http://127.0.0.1:8765`, physical integration checkout, database `oblivion_emar_preview_20261003`. Server PID 37340. Built source `e52054efc`; backend advanced to `92466adc2` for the care-parameter and error-export seam repairs. The later error-export UI is not in these first assets. `EMAR_PERSON_RECORD=p02` applies to the preview process; the stock pack release gate remains off. Browser loaded `build/assets/app-BcAcDMgs.js`. No browser console warnings/errors appeared before the review-booking crash below.

The browser viewport override is scaled by the desktop environment: requesting 1800×1250 produced a measured CSS viewport of 1440×1000. All reported widths must use measured `innerWidth`, not the requested dimensions. Temporary overrides will be reset after verification.

## Checks so far

- Demo administrator sign-in succeeds and lands on My Day. It truthfully says no rostered shift; no administration permission is inferred from being an administrator.
- Medication sidebar has seven management hubs rather than the former long list. Routes to MAR, orders, reviews, stock and controlled register load.
- P02 person chart loads current orders and waiting-check states; the allergy warning remains visible. Support → Assessment → Back → Forward restores the query/subview and then its assessment content. Allergy and INR sections load. Other history, reload, worker and responsive cases remain pending.
- Stock currently uses the retained legacy page because the pack gate is off; this is not a new pack-workflow acceptance claim.
- Controlled register loads scoped synthetic medicines and retained entries, with explicit class-review and count-not-configured states. No clinical changes were submitted.

## Confirmed findings being repaired

1. **P02 header layout and contrast:** at CSS1152 the date control occupies 830×69 px across the header; its As-at caption is dark on the brand background. Owner P02 is replacing the trigger with the shared compact header treatment.
2. **P02 embedded chart:** the full record repeats the profile's Medication introduction, points the reader back to the page they are already on, and shows a duplicate recording control. Owner P02 is preserving allergy/report access while using a context-aware chart presentation. Assessment empty-state implementation jargon is also being removed.
3. **P04 contrast and permission explanation:** meter captions are dark on the header. Enter an order is disabled for this account with no accessible or visible reason. Owner P04 is repairing presentation without changing authorization.
4. **P05 first-open crash:** clicking Book a review on the empty review page blanks React. Console: `TypeError: Cannot read properties of null (reading 'value')`, `_review-dialogs-DWMxHzc8.js:1:7266`. Owner P05 is repairing the null initial state and adding a rendering regression. No save occurred.
5. **P07 contrast:** controlled-register As-at text is dark on the brand background. Main owns this small presentation repair after the frozen backend run. Check Witness overrides for the same pattern.

No complete browser acceptance is claimed. Required continuation: apply repairs, rebuild, repeat actual failure cases, inspect remaining hubs and meaningful dialogs, verify worker/house-lead scope and 1440/1280/narrow/reflow states, inspect final console, capture final screenshots.

## Further checks, 4 October 01:46 NZDT

- Reports > Print & exports > Make pack opens the two-step house/day preview. Kauri House preview returned scheduled/as-needed medicines and a truthful lack-of-allergy-records message. Make pack returned **Pack ready** with no new console error. The browser automation download event timed out; the saved PDF itself has **not** been inspected, so this is UI/server-response evidence only.
- Settings initially blanks React with error 130 (`undefined` component type). P11 traced missing icons for Records & reporting and Error triage and owns a focused fix. Pending integration and browser repeat.
- P01 administrator board showed four open undated PRN follow-ups but caption **None open**; its footer also claimed everyone was on the user's shift when the administrator was not clocked in. Navigation owner is correcting those two display claims, alongside NAV-01/02 and the P07 caption contrast.
- Demo support worker `sw1@demo.test` initially has no current shift. Sidebar exposes only Meds today for medication, with no management-hub overload. My Day's Open Meds today link lands on the scoped worker route; unrostered guidance is shown.
- Measured CSS390 phone width: cards, no horizontal page overflow. Header actions/search are 32px, filter 21px, grouping 17px, refresh 23px, selected rail 35px and More 34px. These fail the 44px frontline requirement. P01 owns an opt-in shared-header sizing repair; Main will wire the page after the navigation owner's edit.
- The eligibility dialog says non-given outcomes are always recordable while the worker has no shift. P11 owns wording that makes clear this describes competency only; dose/site/shift gates still apply.
- A guard-checked helper added a labelled **synthetic** fixture only to `oblivion_emar_preview_20261003`: existing demo worker9, Amelia10, Kauri Site1, shift13, ordinary preview medicine54. No operational database was used. Recording remained through the real browser UI and normal server checks.
- At phone width, the on-shift worker sees Amelia's six medicines and the synthetic preview dose. The three-step wizard shows identity, allergy, prescription instructions, dose window, outcome/time/amount and final signed review. A synthetic Given record saved successfully, showed Recorded, updated the card, and reopened the immutable outcome details. The record link landed at `/emar/mar?client_id=10&date=2026-10-04`, where the dose appears Given at1:44 am by Support Worker1. Screenshot: `screenshots/synthetic-dose-recorded-mobile.jpg`.
- The no-photo identity fallback currently displays **Not configured**; this requires policy/configuration review and is not certification that the identity workflow is ready for real care.

Pending source commits from browser fixes: P04 `9d2175255a44ab6302eccba702068c36fd8468ab`; P05 compiler-safe null selections `38552cf34ecfd7478d079655b6f1b4c2a6ad49d8`. Main's backend run remains frozen at `92466adc2` until it completes. The build shown above remains `e52054efc`.

## Worker journey continuation, 02:00 NZDT

- Person MAR phone overflow is a confirmed defect: CSS390 page scrollWidth974 from a day table954px wide. P02 owns local grid containment and the corresponding week check.
- Activity shows the synthetic scheduled dose with medicine, exact time and author. Activity, As-needed and worker Follow-ups currently rely on wide tables on phones. P01 owns mobile cards preserving their scoped rows/actions.
- The isolated helper added ordinary synthetic PRN medicine55 (one tablet, explicit test reason and limits). The browser required a reason and effect-check date/time; missing check timing kept the outcome step open and focused its date control with a clear validation message.
- Saving that synthetic PRN displayed Recorded, owner Support Worker1 and chosen check-by2:15 am. Worker Follow-ups then showed exactly one open check linked to the actual dose.
- The check opens the canonical P08a outcome dialog. Recording the synthetic Helped outcome with an explicit no-real-care note showed Done/Saved and returned the worker queue to zero open checks. The PRN row changed to **1 of2, last1:56 am, Too soon — from5:56 am**; today's record says **effect recorded**. This verifies the connected UI journey on the current synthetic preview, not every concurrency/clinical edge case.
- As-needed's former empty message claimed no orders exist although its reader filters by available scope/status. P01's copy fix now awaits integration; it will describe what this view can show instead.

Additional queued commits: P02 polish `628371aaa`; P02 historical reader `19d34d77d`; P04 fixed-pixel targets `eab90d638`; navigation/P07 captions `d48ccf2dd`; P11 missing Settings icons `15002cf0a`; P01 opt-in frontline header `211e3d924` and truthful identity/PRN copy `3359b37ff`. The integrated source has not changed during the backend run.

## 4 October 02:53–03:04 NZDT — compiled repair acceptance

Production type check and Vite build both passed at frontend snapshot `87c8102c5` (later `d51d5e9da` was test-only). Verified browser asset `app-CvNGummW.js`; synthetic local preview only.

- CSS 390 Meds today: document width 378; search, quick actions, filters, refresh and view controls measure at least 44px tall. Meter widths remain 146px, not accidentally 44px. As-needed cards and six recorded Activity cards fit without page overflow. Activity opened Amelia's canonical medication record.
- Record day chart still produced document width 853 despite local region width305/scroll838: absolute sr-only Actions label escaped its unpositioned scroller. P02 supplied `ccf87052c`, integrated `25b253d80`; rebuilt browser acceptance remains pending.
- Review booking now opens without the prior React compiler crash. Person directory loads; choosing Amelia plus Regular reaches step2 (due date, clinician and owner). Cancelled; no review booked in this check.
- Settings renders, including Records & reporting; no React130 blank screen. At CSS1440 document width1440.
- Safety canonical route is `/emar`, not `/emar/safety`. Shared header and single navigation rail render. Width checks:1440/1428,1280/1268,390/378 (viewport/document respectively). No page overflow. Screenshot `screenshots/updated-safety-desktop.jpg` captured before Main's plain-language copy-only `89f06b7c0` change.
- Copy follow-up `89f06b7c0`: team attention, medication outcomes, People, medication progress, Medication-trained staff replace clinician-only/med-pass/med-competent wording. Rebuild pending for these labels.

These are targeted browser observations; no claim of complete module acceptance or actual browser200% zoom.

## 4 October 03:39 NZDT — further stock inspection

The new pack reader at `/emar/stock/packs` loads on CSS390 with document width378 and the release flag still off. Its header filters measured21px and rail34–35px; the860px local table hides key stock facts/actions off-screen. Main prepared UI-only `c9c48d905`: existing Rory cards show the exact same column values/actions on phones, explain unknown quantities, preserve context menus and disabled reasons; the header uses the established frontline sizing. The desktop table remains. Source regression and rebuilt visual acceptance pending.

The controlled-checks deep link `/meds/today?view=controlled` also loads on CSS390 with document width378. Unconfigured count cadence is explicitly described and is not presented as overdue. The admin preview is not clocked in; recording is visibly unavailable. This is a reader check, not acceptance of held controlled writers.

### 4 October 2026, 04:18 NZDT — actual paper downloads

The IAB download event waiter timed out, but actual files were saved to Downloads. Verified the CSV medication-doses-20261004-034818-NZDT.csv and the fresh 34-page downtime pack produced through the real Make the pack workflow for synthetic Kauri House.

The old pack lost the person's name on PRN continuation pages and the controlled medicine identity beside orphaned closing counts. Added repeating table identity, page numbers and keep-together blank PRN rows (de460cdb5, b6210183e). The final downloaded downtime-pack-1-2026-10-04 (2).pdf contains the correct identity on all 24 person recording pages and all four controlled pages, with page numbers on all 34 pages. Rendered and inspected pages 2 and 31; the four PRN writing rows, controlled writing rows and closing-count/signature spaces remain. Proof: screenshots/paper-continuation-person.png and screenshots/paper-continuation-controlled.png. Original and intermediate downloads remain untouched.

These are synthetic preview exports. This verification does not activate the held paper-to-clinical writer or establish production clinical readiness.

### 4 October 2026, 04:40 NZDT — rebuilt phone checks

Using app-DaT9aSj3.js at CSS390: both the person day chart and loaded seven-day chart have document width378; their wide tables remain inside local scrollers. Saved person-day-chart-phone.jpg and person-week-chart-phone.jpg. The week range still uses ISO input formatting; a shared calendar-date formatter change is prepared for the next build.

Settings loads at CSS390/document378. Records & reporting opens successfully and explicitly labels unreviewed organisation defaults. No new console error; the only two retained error entries are the already repaired old review-dialog and Settings builds. No settings were saved in this check.

The rebuilt stock page exposed a width959 overflow from the outer implicit grid, despite its new mobile cards. Source3cadf9555 gives the grid an explicit minmax(0, 1fr) track and min-width0. Browser proof remains pending the next build; stock-phone-cards.jpg currently records the defect and must be replaced with the final verified view.

### 4 October 2026, 04:57 NZDT — final stock phone proof

The frontend build at a4eb818e2 passed full TypeScript and Vite (6m13s); current asset app-CKz5Hieu.js. A navigation during asset replacement briefly requested a stale/missing dynamic chunk. After a full reload the current stock screen renders successfully; this is a local rebuild-transition observation, not an unresolved React render failure.

Stock now measures CSS390/document378, header343.4; House, Show and More buttons are44px tall. Person-owned medicine cards show quantity, pack/expiry uncertainty, supply state and actions inside the viewport. Replaced screenshots/stock-phone-cards.jpg with the final verified view. The pack writer release flag remains off; this is read/UI acceptance only.

### 4 October 2026, 05:05 NZDT — retained history follow-up required

/emar/reports/history renders the restored clinical history at CSS390/document378, but the older AuditLog.tsx still uses an oversized greeting hero, nested print link/button and controls as small as14–38px. It says “immutable source records” and an empty gap list claims every record is attributed and witnessed, which is not supported by a filtered/limited read. Main queued a focused Rory PageHeader/touch/plain-language repair.

More importantly, AuditLog.tsx ignores the server filters prop, initializes its date window from the browser timezone, subtracts fixed86400000ms days and filters only the fetched events. The date/range controls need explicit NZ calendar dates and a scoped server visit that preserves person/site selection; moving the date must fetch the correct historical evidence. Backend already acceptsdate_from/date_to and returnsfilters. Changes must preserve all reader/export permissions, detail links and current 800-row coverage warning.

### 4 October 2026, 05:26 NZDT — week chart date proof

Current compiled app-CKz5Hieu.js shows the week range as 28 Sep 2026 – 4 Oct 2026 · Pacific/Auckland. CSS390/document378; the table stays within its scroller and earlier days correctly show unavailable coverage. Saved and visually inspected the updated person-week-chart-phone.jpg. No clinical write was made. The one initial guessed /emar/people URL returned404; the canonical person page is /emar/mar?client_id=10.

### 4 October 2026, 05:31 NZDT — final Safety overview copy

Current app-CKz5Hieu.js at CSS1440/document1428 shows the approved seven medication hubs and updated supported-living wording. Saved updated-safety-desktop.jpg. No new medication action was submitted. The long synthetic organisation wordmark overlaps the separate global-header date at desktop width; Main will bound the wordmark width when the current source freeze ends.

### 4 October 2026, 06:10 NZDT — medication history and keyboard checks

At frontend revision 43c66c616, compiled asset app-EgGhS2mP.js:

- The compact history header replaces the old greeting. Selecting Amelia Wilson fetched 14 records from the server (previously 123 across all people). Seven days fetched 28 September–4 October 2026 while preserving that person. Applying 3 October as both custom dates fetched nine records. Browser Back restored the seven-day person scope; Forward restored the one-day scope and nine records.
- Print and Change log links retain the selected person and supported NZ date filters. Unsupported event-type arrays are not passed to those destinations.
- CSS1440/document1428: bounded organisation wordmark ends at x242; the separate date starts at x304.6. They no longer overlap. CSS390/document378: phone filters, date fields, row actions and menu items are at least44px high, with no document overflow.
- The phone Table view is available in More views. Record actions open inside the viewport with keyboard focus on the first item. Escape closes the menu and returns focus to the originating record action button.
- A native date-input fill through this browser driver changes the DOM value without sending React's expected change event; ArrowUp does send it. The checks used native key entry before Apply. This automation behaviour was not treated as an application defect.

Browser findings led to two final UI repairs: badd1bb53 names the nested breadcrumb Medication history and hides absent optional outcome/witness text; 66777fe40 uses event-specific destinations in both the menu and drawer. The drawer's timestamp uses the shared NZ formatter in d90605140. These three changes require the final rebuilt browser check below. The earlier history-desktop.jpg is a checkpoint, not final proof of those repairs.
