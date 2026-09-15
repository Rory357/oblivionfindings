# Findings — risk & assurance, policies, documents, records, reports

Code review, 14 September 2026, read-only. Part of audit.md in this folder.

- **[P0] The Attestations page lists every approved policy as "awaiting your sign-off", even ones that need no sign-off** — `app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:272-275, 299` + `resources/js/pages/Governance/Policies/Attestations.tsx:170`
  - *What a board member experiences:* Home's "Policy Attestations" block links here, and the page says, for example, "12 policies awaiting your sign-off" when only 2 actually need it. If they open one of the extra policies, there is no sign-off form, because `Show.tsx:246` only shows it when `requires_attestation` is true. Policies that take effect in the future are also listed, and signing one fails with a 422 error ("Policy is not yet effective", controller `:227-229`).
  - *Rule/heuristic:* H (truthfulness), C, B.
  - *Fix:* In `attestations()`, add `->where('requires_attestation', true)` and a filter for effective date ≤ today. Show future policies separately as "Coming into effect on {date}" with no button.

- **[P0] Compliance statuses never update over time, so the page says "On track" while items are overdue** — `app/Domain/Governance/Models/ComplianceObligation.php:64-84` + `app/Domain/Governance/Services/ComplianceEngineService.php:495` + `resources/js/pages/Governance/Compliance/Index.tsx:193-201, 510-515`
  - *What a board member experiences:* Status is only recalculated when someone saves the record. Nothing scheduled refreshes it: `governance:compliance-reminders` only sends reminders, and I found no other job. So the header chip reads "On track" and the Overdue block shows 0, while the table underneath (which works out days in the browser) says "12 days overdue". The Compliance Status report (`ReportController.php:111`) reads the same stale status.
  - *Rule/heuristic:* H — "Is anything wrong that the board must know about?"
  - *Fix:* Add a daily scheduled refresh of `status` for obligations that aren't complete or cancelled. Alternatively, work out overdue / due soon from `due_date` in the queries (`scopeOverdue`, `getComplianceStatus`, report summary) instead of the stored status.

- **[P1] The policy sign-off box is pre-ticked and can be submitted without opening the policy** — `resources/js/pages/Governance/Policies/Attestations.tsx:59-62, 74-76, 85-93`
  - *What a board member experiences:* "Attest to this policy" opens a box where "I have read and understood this policy." is already ticked. Two clicks from the list record a formal statement about a policy they never opened.
  - *Rule/heuristic:* C (confirming consequential actions), H (the record may be untrue), D.
  - *Fix:* Start with `acknowledged: false`. Replace the inline form with "Read and confirm", which opens the policy (`/governance/policies/{id}#confirm`). Keep the confirmation only on the policy page, under the wording.

- **[P1] "Accept risk" can never succeed** — `resources/js/pages/Governance/Risks/_dialogs.tsx:1166-1170, 1209-1213` + `RiskRegisterController.php:203-205` + `Risks/Show.tsx:176-180`
  - *What a board member experiences:* The button only appears for risks above appetite. The dialog says they "must be linked to a Board resolution" but has no field to pick one. The server always rejects it with "Please create and link a resolution first."
  - *Rule/heuristic:* C (dead end; blocked state doesn't say how to unblock).
  - *Fix:* Add a required "Board decision" picker (carried resolutions) to the dialog. If none exist, show "No board decision authorises this yet — ask the board secretary to add one to the next meeting" with a link to Resolutions.

- **[P1] After the board accepts a risk, the page still says "acceptance needed", and the counts stop matching the lists they link to** — `RiskRegisterController.php:218, 43-45` + `app/Domain/Governance/Models/RiskRegisterEntry.php:127-130` + `Risks/Show.tsx:200-203, 285-287` + `Risks/Index.tsx:173-248`
  - *What a board member experiences:* Once accepted, the risk still shows the "Above appetite" chip and "Above appetite · acceptance needed", because `within_appetite` stays false. The header blocks only count risks with status `active`/`open`, so accepted, being-treated and closed risks drop out of "Critical" and "Above appetite". But clicking a block opens the unfiltered register, which includes closed and accepted risks, so "Critical 1" opens a list of 3.
  - *Rule/heuristic:* H; F (every block links to the view where its number lives).
  - *Fix:* On an accepted risk, show "Accepted by the board until {expiry}" as the chip and block caption. Default the register to open risks (`status` not `voided`) and make the meter hrefs carry the same status scope as the counts.

- **[P1] Scoring terms are shown as bare numbers with no explanation** — `Risks/Index.tsx:183, 223, 234, 362-375` · `Risks/Show.tsx:212, 244-289, 369-419` · `Risks/_dialogs.tsx:125-130, 684-711` · `RiskRegisterController.php:145` · `RiskScoringService.php:38-43`
  - *What a board member experiences:*
    - Show gives "Likelihood 3", "Impact 4", "Control effectiveness moderate" and "Appetite threshold 12" with no words or explanation.
    - The list only shows the residual score, so a "catastrophic / almost certain" risk (25) with the default "moderate" controls silently becomes "13 · Medium" and "Within".
    - The wizard's Control effectiveness choices (None/Weak/Moderate/Strong) never say they multiply the score by 1 / 0.8 / 0.5 / 0.2. It also says "The server confirms both when the risk is saved" and "A guided wizard to register a new enterprise risk".
  - *Rule/heuristic:* A (jargon: residual, inherent, appetite, enterprise), B.
  - *Fix:* Use the labels the app already has: "Likelihood: 3 – Possible", "Impact: 4 – Major".
    - Rename "Inherent score" to "Risk before controls" and "Residual score" to "Risk after current controls".
    - Rename "Appetite" to "Board's limit for {category} risks: 12".
    - List column: "Before → after controls: 25 → 13".
    - Control help text: "How well current safeguards work. Strong controls cut the score to a fifth; none leave it unchanged."
    - Replace the server sentence with "Scores are recalculated when you save."
    - Add a one-line "How risk scores work" explainer (popover) on Register, Heatmap and Show.

- **[P1] The heatmap counts closed risks by default, uses a different score from the register under the same labels, and its cells can't be opened** — `RiskRegisterController.php:295` · `Risks/Heatmap.tsx:72-81, 143, 156-200, 292-310, 367-374`
  - *What a board member experiences:*
    - The default is "all statuses", so the chip says "3 critical" including closed risks.
    - The heatmap's "Critical" counts before-controls scores; the register's "Critical" counts after-controls scores. Two different numbers carry the same word.
    - Cells only show detail in a hover tooltip, can't be focused or clicked, so you can't find out which risks are in a cell.
    - High and Medium use the same warning background and text, differing only by border opacity. The distribution badges give both the `warning` variant.
    - The four band blocks all just scroll to the matrix.
  - *Rule/heuristic:* H, E, G (colour as the only signal, hover-only), F (decorative meter blocks).
  - *Fix:*
    - Default to open risks; offer a "Include closed" checkbox.
    - Title the card "Risks before controls (likelihood × impact)". Add a switch "Before controls / After controls", and label the blocks "Critical (before controls)".
    - Make each cell a link to `/governance/risks?likelihood=L&impact=I`, with `aria-label` "3 risks: Likely × Major".
    - Give High its own visual cue (hatched border, or the word "High" inside the cell).
    - Point the band blocks at `/governance/risks?severity=…`.

- **[P1] Risk Trends can never show anything, and the empty message wrongly says snapshots are scheduled** — `Risks/Trends.tsx:186-190` + `app/Domain/Governance/Jobs/CaptureRiskHeatmapSnapshot.php` (never dispatched or scheduled; `routes/console.php:745-782` has no entry)
  - *What a board member experiences:* The empty state says "Snapshots of the active register are captured on the reporting schedule; trends appear once the first one is taken." No schedule exists, so the page stays empty forever. When data does exist, the chart puts critical and high into one unlabelled red bar with hover-only values (`:202-230`).
  - *Rule/heuristic:* H, C, G.
  - *Fix:* Schedule `CaptureRiskHeatmapSnapshot` (for example monthly, on the 1st). Until the first capture, say "Monthly risk snapshots start on {date}". Stack critical and high as separate labelled segments with visible values.

- **[P1] Risk response and status names are unclear, and three status filters can never match anything** — `Risks/_shared.tsx:39-47` + `Risks/_dialogs.tsx:140-165` + migration `2026_02_06_100040_create_risk_register_entries_table.php:37`
  - *What a board member experiences:*
    - Strategy shows "Treat / Transfer / Terminate / Tolerate", while status shows "Mitigating / Accepted / Transferred / Avoided". "Tolerate" ("Accept the risk within appetite") and status "Accepted" (a board acceptance above appetite) sound like the same thing.
    - No code ever sets `mitigating`, `transferred` or `avoided`, so those filters always return nothing.
    - There is no UI to close a risk (route `risks.close` exists), so "Closed" is only reachable by other means.
  - *Rule/heuristic:* A, E, C.
  - *Fix:*
    - Strategy labels: "Reduce it (treat)", "Share it (transfer)", "Stop the activity (avoid)", "Live with it and monitor (tolerate)".
    - Status filters: "Open", "Accepted by board", "Closed". Drop the three unused values.
    - Add "Close risk" (ConfirmDialog with a required reason) on Show.

- **[P1] Treatment actions can never be marked complete** — `routes/governance.php:169-177` + `Risks/Show.tsx:116-121, 297-310`
  - *What a board member experiences:* The Treatments block shows "0 of 4 complete" forever. There is no complete or update route or button, so "overdue" and "complete" never appear, and the "Evidence required to close" tick box has nothing to close.
  - *Rule/heuristic:* C (next steps), H.
  - *Fix:* Add "Mark complete" (with the evidence check) and "Change due date" on each treatment. Until then, remove the progress bar and the "Evidence required to close" wording.

- **[P1] Compliance header numbers contradict each other and the list** — `Compliance/Index.tsx:166-169, 262-287` · `Compliance/_shared.tsx:10` · `ComplianceObligation.php:79-80, 142-146` · `ComplianceEngineService.php:475-478, 486-496`
  - *What a board member experiences:*
    - "Due in 30 days" also counts overdue and cancelled items, so it double-counts the Overdue block, and its caption shows a second, different number.
    - The status filter "Due soon" means 7 days.
    - "Compliance rate" divides completed by everything, so a future annual return that isn't due yet counts as non-compliance (for example 20%).
    - The summary skips the MSD and ACC funding frameworks, so "Obligations 40" sits above a list of 46.
  - *Rule/heuristic:* H.
  - *Fix:*
    - Use one definition: "Due soon = due in the next 30 days, not overdue, not cancelled", and relabel the filter "Due in 30 days".
    - Replace the rate with "On time: {complete + not yet due} of {total, excluding cancelled}".
    - Build the summary from `ComplianceObligation::frameworkOptions()` keys.

- **[P1] Uploaded compliance evidence can't be opened by anyone** — `Compliance/Show.tsx:376-431` + `routes/governance.php:182-195` (no evidence download route)
  - *What a board member experiences:* Evidence shows as a title with a green file icon, even when expired (`:384`), but there is no link to view or download it. The board can't check the proof.
  - *Rule/heuristic:* C, E.
  - *Fix:* Add an authorised download route (the private-attachment pattern already used for risk treatments) and make each evidence row "Open" / "Download". Use a warning icon for expired evidence.

- **[P1] Framework list is missing core NZ disability duties and uses outdated, inconsistent names** — `ComplianceController.php:336-350` · `Compliance/Show.tsx:122-133` · `ComplianceObligation.php:203-216`
  - *What a board member experiences:*
    - There is no Code of Health and Disability Services Consumers' Rights (Code of Rights), and no disability support funder. "MoH/Health NZ Funding" is the only health funder listed.
    - The same framework has three names in three places: "H&D Services (Safety) Act" vs "Health and Disability Services (Safety) Act", "Health and Safety at Work Act" vs "… 2015", "Employment Relations" vs "Employment Relations Act".
  - *Rule/heuristic:* A (NZ context), consistency.
  - *Fix:*
    - Add "Code of Rights (Health and Disability Commissioner)" and "Disability Support Services funding".
    - Relabel "MoH/Health NZ Funding" to "Health New Zealand funding".
    - Delete `FRAMEWORK_LABELS` in Show and use one server list (`frameworkOptions()`) everywhere, with full Act names and years.

- **[P1] Te Tiriti principles are paired with the wrong Māori terms and never explained** — `TeTiritiController.php:33-39` + `TeTiriti/_dialogs.tsx:505-514`
  - *What a board member experiences:*
    - The list mixes the older "partnership / participation / protection" with the Hauora (Wai 2575) principles, and uses Māori words as if they were translations: "Partnership / Rangatiratanga", "Participation / Mana Motuhake".
    - "Kowhiringa" is missing its macron (Kōwhiringa).
    - The principle picker has no descriptions, so a lay member can't tell what each principle asks of the organisation. A Māori board member is likely to lose trust in the page.
  - *Rule/heuristic:* A, B; POPUP guide (every tile has a one-line description).
  - *Fix:* Use the five Hauora principles — Tino rangatiratanga, Equity, Active protection, Options (Kōwhiringa), Partnership — each with a plain description. For example Options: "Māori can choose kaupapa Māori or culturally safe services". Have the organisation's Māori advisor confirm the wording. Add a one-line page purpose: "How we meet our Te Tiriti o Waitangi commitments under Ngā Paerewa section 1."

- **[P1] Clinical trend arrows compare part of this month with all of last month, with no explanation** — `app/Domain/Governance/Services/ClinicalGovernanceAutomationService.php:236-261, 263-275` · `Clinical/Dashboard.tsx:144-158, 357-399, 437-448`
  - *What a board member experiences:*
    - On 2 September every indicator shows "trending down" (good), because two days are being compared with 31.
    - Values are bare counts with a shouted "COUNT" unit and "Target ≤ 0".
    - The "Narrative" card is just the same numbers repeated as a sentence.
    - With no data the chip still says "On target".
    - "Clinical governance", "snapshot" and "Auto-fed from Health & Clinical clinical events…" mean little to a lay member.
  - *Rule/heuristic:* H, A, B.
  - *Fix:*
    - Label the current period "September so far (1–14 Sep)" and compare it with the same days last month, or only show trends for complete months.
    - Target copy: "Target: none". Hide the unit when it is "count".
    - Retitle to "Care quality & safety"; subline "Medication errors, falls, skin injuries and infections this month".
    - Remove the auto "Narrative", or replace it with a clinical lead commentary field.
    - Chip: "No data yet" when nothing has been recorded.

- **[P1] An approved policy can't be revised: the wording is locked and there is no "New version" action** — `Policies/_dialogs.tsx:520-526` + `GovernancePolicyController.php:176-178` (route `policies.version` exists, no UI)
  - *What a board member experiences:* The editor says "Changes to the wording need a new policy version", but no button creates one anywhere.
  - *Rule/heuristic:* C (dead end).
  - *Fix:* Add "Start new version" on active policies. It opens the same wizard prefilled, with a required "What changed" field, and posts to `/policies/{id}/version`.

- **[P1] Edit can make a policy "Active" without approval, and "Approve" has no confirmation** — `Policies/_dialogs.tsx:587-597` + `GovernancePolicyController.php:156, 187-189` + `Policies/Show.tsx:100-105, 153-160, 174-176`
  - *What a board member experiences:* A manager can pick Status "Active" in Edit, and the policy then shows "Active · Not yet approved". "Approve" publishes to every member in one click.
  - *Rule/heuristic:* C (confirm consequential actions), H.
  - *Fix:* Remove "Active" from the edit Status options (allow Draft / Under review / Archived only). Put Approve behind a ConfirmDialog: "Approve and publish this policy? Board members will be asked to confirm they've read it."

- **[P1] Policies vs Documents vs Records overlap, and nothing explains which to use** — `lib/governance-sections.ts:212-243` · `GovernanceDocumentController.php:52-60` · `Documents/Index.tsx:225-234` · `Records/Index.tsx:367-370, 481` · `components/app-sidebar.tsx:1822`
  - *What a board member experiences:*
    - Documents has a "Board Policy" type and a "Board policies" block, next to a separate Policies register.
    - Records lists policies and documents again.
    - The same place is called "Records" (sidebar), "Records search" (tab and breadcrumb) and "Governance records" (title).
    - A member can't tell where the constitution goes or which policy list is the real one.
  - *Rule/heuristic:* E, A.
  - *Fix:*
    - Remove the "Board Policy" document type (existing ones move to Procedure or Other) and the Documents "Board policies" block.
    - Sublines: Policies "Rules the board has approved — read and confirm them here". Documents "Reference files: constitution, terms of reference, templates, certificates". Records "Search everything the board has done: past meetings, decisions, policies and files".
    - Use "Records search" consistently for the sidebar, tab, title and breadcrumb.

- **[P1] Document file names show and download as random codes** — `GovernanceDocumentController.php:27, 75, 105, 133` · `Documents/Index.tsx:343` · `Documents/Show.tsx:94, 174-178, 191-193`
  - *What a board member experiences:* The list subline and "Filename" show something like `aZ3k9…Qx.pdf`, and the download saves under that name. Format is shown as `application/vnd.openxmlformats-officedocument…`.
  - *Rule/heuristic:* A (developer internals leaking).
  - *Fix:* Store the original client file name on upload and use it for display and in `download()`. Show format as "PDF" / "Word document", and rename the "Metadata" card to "Details".

- **[P1] Board Monthly Report shows "Freshness unavailable", zeros for missing data, and no date range** — `ReportController.php:27, 48` · `GovernancePresenter.php:838-841, 1696-1698, 1206` · `Reports/BoardMonthly.tsx:54-61, 81-111, 116-168`
  - *What a board member experiences:*
    - An empty freshness array is passed in, so risk, compliance and incident cards all carry a "Freshness unavailable" badge.
    - The "Critical risks" headline shows 0 even when the risk card says "This information could not be loaded. Figures are not shown as zero."
    - Data runs from the 1st of the month to today, but the report never says so.
    - Status badges read "good" / "unknown".
    - Cards don't link to their source, even though `href` is sent.
    - The meters "Sections 3" and "Headline KPIs 4" link back to the same page.
    - Highlights lead with codes ("R-2026-004 …").
  - *Rule/heuristic:* H, A, F (hand-rolled status pills; decorative meters; ad-hoc `text-3xl`/`text-xl`/`text-lg`; `space-y-8`/`gap-4`/`mb-8`), E.
  - *Fix:*
    - Pass real freshness, or drop the badge.
    - Show "—" / "Not available" in headlines when a widget is unavailable.
    - Subline "1–14 September 2026 (month to date)", with a month picker in the filter row.
    - Map status to StatusBadge with labels "On track" / "Needs attention" / "Serious" / "No data".
    - Make each card link to its `href`. Replace the meters with the four headline metrics as linked blocks.
    - Title "Board monthly report" (sentence case).

- **[P1] Risk Narrative report: raw colour classes, owner always "Unassigned", a broken link, and "all" that is really the top 10** — `Reports/RiskNarrative.tsx:50-61, 91, 117, 211, 271, 298` + `ReportController.php:125, 139`
  - *What a board member experiences:*
    - Every risk says "Owner: Unassigned", because the controller sends a name string and the page reads `owner?.name`.
    - The "Above appetite" block goes to `?appetite=above`, which the register ignores.
    - The subline says "all active risks" but only 10 are shown.
    - Strategy shows raw "treat".
    - It isn't a narrative; it's the register again.
  - *Rule/heuristic:* F (non-negotiable #1: `border-l-red-500/orange/yellow/green/gray-300`, `text-white`/`text-black`; hand-rolled pills; bare empty `<div>`), H, E, A.
  - *Fix:*
    - Read `risk.owner` as a string.
    - href `/governance/risks?above_appetite=1`.
    - Subline "The 10 highest risks after controls".
    - StatusBadge plus the `riskLevelVariant` tokens.
    - Use STRATEGY_OPTIONS labels.
    - `EmptyState`.
    - Remove the duplicate stat cards.

- **[P1] Compliance Status report: the "Complete" block opens an empty list, plus raw statuses and dates** — `Reports/ComplianceStatus.tsx:48-56, 91, 124-165, 205-207, 217, 229-236, 244-249`
  - *What a board member experiences:*
    - Clicking "Complete" goes to `?status=compliant`, a status that doesn't exist, so the list is empty.
    - Badges read "not due".
    - Dates show as "Due: 2026-09-30".
    - Empty outlined badges appear when there's no code.
    - Rows can't be opened.
    - Five stat cards repeat the header.
  - *Rule/heuristic:* E, H, A, F (hand-rolled pills; `text-3xl`; bare "No compliance obligations were found." card).
  - *Fix:*
    - `?status=complete`.
    - StatusBadge with `obligationStatusLabel`, and `formatDateOnly`.
    - Render the code badge only when present.
    - Link rows to `/governance/compliance/{id}`.
    - Drop the duplicate cards; use `EmptyState`.

- **[P1] The committee report and committee risk pages can't be reached, send users to the wrong place, and disagree on which risks each committee owns** — `Reports/Committee.tsx:94, 108-119` · `Risks/Committee.tsx:53-57` · `ReportController.php:61-65` vs `RiskRegisterController.php:326-330`
  - *What a board member experiences:*
    - Nothing links to either page. The sidebar only links Board monthly and Compliance status (`app-sidebar.tsx:2117-2129`).
    - On the report, the "Sections" block and the last breadcrumb go to the Board Monthly report.
    - The Finance committee risk view includes Operational risks; the Finance report doesn't.
    - Clinical and Reputational risks belong to no committee.
    - The mapping is a hardcoded committee type, not the actual committee membership (contrary to the approved workflow decision).
  - *Rule/heuristic:* E, H, F (decorative meter, hand-rolled pills).
  - *Fix:*
    - Drive both pages from real `BoardCommittee` appointments plus one shared category map that covers all eight categories.
    - Link them from each committee's page.
    - Fix the breadcrumb href to the page itself and drop the "Sections" block.

- **[P2] Four hub pages have no status chip in the header** — `Policies/Index.tsx:226-229`, `Policies/Attestations.tsx:147-150`, `Documents/Index.tsx:180-183`, `Records/Index.tsx:367-370`
  - *What a board member experiences:* There's no at-a-glance "all fine / something needs you" signal on these pages, unlike Risk and Compliance.
  - *Rule/heuristic:* F — Event Horizon header contract (title + one status chip).
  - *Fix:*
    - Policies: "{n} reviews overdue" (critical) or "Reviews up to date".
    - Attestations: "{n} to confirm" or "All confirmed".
    - Documents: "{n} documents".
    - Records: "{n} records".

- **[P2] "Attestation" wording, a frequency setting that does nothing, and counts that can pass 100%** — `Policies/_dialogs.tsx:164-167, 598-634` · `GovernancePolicyController.php:127-128, 279-280` · `Policies/Show.tsx:180-193, 246-306`
  - *What a board member experiences:*
    - "Attestation", "sign-off" and "Re-confirm attestation" all mean "I've read it".
    - "Attestation frequency: Annually" never asks anyone again.
    - "Completed" counts any user who confirmed, but the total is active board members, so "9/7 · 129%" is possible.
    - After confirming, the form stays on screen with an unticked box.
  - *Rule/heuristic:* A, H, C.
  - *Fix:*
    - Use "Read and confirm" throughout: tab "Policies to confirm", button "Confirm I've read this policy", success text "You confirmed version {n} on {date}".
    - Either implement re-confirmation by frequency or remove the field.
    - Count only active board members' confirmations of the current version.
    - Replace the form with a receipt once confirmed.

- **[P2] Attestations list is hand-built instead of using the shared list component** — `Policies/Attestations.tsx:276-324, 351-379, 286, 359`
  - *What a board member experiences:* The rows behave differently from every other register: no row menu, no right-click menu.
  - *Rule/heuristic:* F — Bespoke entity lists (LIST_STYLE_GUIDE); ad-hoc `text-[13px]`.
  - *Fix:* Use `EntityTable` with an "Open policy" / "Confirm" `MenuItem[]`.

- **[P2] Several header blocks don't go where their number is, or repeat the same scroll** — `Risks/Trends.tsx:94-98, 133-137` · `Clinical/Dashboard.tsx:209-218` · `Clinical/Trends.tsx:161-216` · `TeTiriti/Index.tsx:207-217` · `Documents/Index.tsx:235-246` · `Documents/Show.tsx:131-159` · `Policies/Show.tsx:195-206` · `Policies/Attestations.tsx:184-196`
  - *What a board member experiences:*
    - Six Trends blocks all scroll to the same timeline.
    - "Automated 4" opens the "No data" filter.
    - Te Tiriti "Implemented" counts implemented + embedded but filters implemented only.
    - "Updated · 30 days" opens the unfiltered list.
    - Document "File" / "Last updated" and policy "Effective" open unrelated lists.
    - For a normal member, "Board members" opens Policies.
  - *Rule/heuristic:* F — Dead or decorative meter blocks; H.
  - *Fix:* Point each block at a filtered view that matches its number (for example `?updated=30d`, `?status=delivered`), or drop the block.

- **[P2] Risk record page leads with codes and hides the board's reasoning** — `Risks/Show.tsx:212, 337-340, 578-603, 647-676`
  - *What a board member experiences:*
    - The breadcrumb ends in "R-2026-004" and the subline starts with it.
    - The acceptance card shows "Board Resolution / Accepted by / Expires" but not the required justification or conditions.
    - Linked events show a reference with no link.
  - *Rule/heuristic:* A (codes as the main label), E.
  - *Fix:* Breadcrumb uses `risk.title`; move the reference to the end of the subline. Show the justification, the conditions and a link to the resolution. Link events to their incident or record.

- **[P2] Compliance header has a confusing "Compliance centre" button, and the framework cards sit between header and list** — `Compliance/Index.tsx:211-226, 338-416`
  - *What a board member experiences:*
    - "Compliance" and "Compliance centre" are two different places. The approved nav decision removed operational Compliance from Governance.
    - Four glass/primary buttons compete in the header.
    - The framework cards' "View" buttons duplicate the Framework filter.
  - *Rule/heuristic:* B, E, F ("Lists render nothing between the header and their content").
  - *Fix:* Remove the "Compliance centre" button. Put the per-framework progress into the Framework filter options or a single donut block. Keep "Calendar" as a block link.

- **[P2] Compliance wizard locks most fields after creation and hides its defaults** — `Compliance/_dialogs.tsx:388-404, 427, 594-615, 644-666, 704-758` + `ComplianceEngineService.php:29-31`
  - *What a board member experiences:*
    - Framework, reference, requirements, frequency and priority all say "set at creation", so a mistake can't be fixed.
    - Leaving "Next due date" blank silently calculates a date from the frequency.
    - Every obligation is made "Evidence required" with no way to choose.
  - *Rule/heuristic:* D.
  - *Fix:* Allow editing these fields (log the changes), add hint "Leave blank to set from the frequency", and add a "Evidence needed to complete" toggle (default on).

- **[P2] Records search: Enter-only search, misleading empty text, wrong dates and codes** — `Records/Index.tsx:153, 260-266, 272, 300, 388-397, 431-436, 572` + `GovernanceRecordsController.php:63, 104, 120`
  - *What a board member experiences:*
    - Search only works on Enter, while every other page searches as you type.
    - An empty section says "Try another search term or record type." when nothing was searched.
    - Meetings "Held" includes cancelled meetings.
    - Draft minutes show a green "Minutes draft" chip.
    - The decision date is the record's created date.
    - Policy sublines show a random "POL-8KQ2ZD".
    - Names vary: "Decisions made" / "Carried resolutions" / "Decisions & resolutions" / "Decisions".
  - *Rule/heuristic:* H, A, C.
  - *Fix:*
    - Debounce search like the other pages.
    - Empty copy "Nothing recorded yet" when there's no search.
    - Exclude cancelled meetings, or show them as "Cancelled".
    - Map minutes status to the right StatusBadge variant.
    - Use the decided/carried date.
    - Replace the code subline with category · effective date.
    - One name everywhere: "Decisions".

- **[P2] Te Tiriti reuses the word "obligations" and has no owner** — `TeTiriti/Index.tsx:141, 154, 162` + `TeTiritiController.php:67` + `TeTiriti/_dialogs.tsx:139-150`
  - *What a board member experiences:*
    - "Obligations" is the same word as compliance obligations.
    - Every commitment is silently owned by its creator, and no owner is shown.
    - "Implemented" is blue (info) while "Embedded" is green, so it's unclear which is better.
  - *Rule/heuristic:* A, D.
  - *Fix:* Call them "Commitments" ("Add commitment"). Add an owner picker and an Owner column. Use a success tone for both delivered states, with the labels "Done" / "Part of everyday practice".

- **[P2] Clinical Trends repeats the Dashboard** — `Clinical/Trends.tsx:274-377`
  - *What a board member experiences:* The "Current snapshot" grid is the same as the Dashboard cards, so the page is long with no new information.
  - *Rule/heuristic:* B.
  - *Fix:* Drop the grid. Lead with the history table, labelled "Month by month (current month so far)".

- **[P2] Policy Index: attestation column has no denominator, and a menu item opens a generic page** — `Policies/Index.tsx:148-154, 210-221, 286-297`
  - *What a board member experiences:*
    - The "Attestations" column shows a bare "3".
    - The row menu "Attestations" opens the all-policies page, not this policy.
    - The "Attestation" block counts policies that need sign-off, not how many are outstanding.
  - *Rule/heuristic:* A, H, E.
  - *Fix:* Column "Confirmed: 3 of 7". Menu item "Who has confirmed" opens `/governance/policies/{id}#confirmations`. Block "Waiting on members: {n} policies".

- **[P3] Title Case and inconsistent names** — `Reports/BoardMonthly.tsx:78-87` ("Board Monthly Report"), `Compliance/Calendar.tsx:64` ("Compliance Calendar"), `GovernanceDocumentController.php:54` ("Terms Of Reference"), `RiskRegisterController.php:429-434` ("Client Safety", "IT/Cyber") vs `Risks/Trends.tsx:277-281` ("it cyber"), `ClinicalGovernanceAutomationService.php:113-168` ("Medication Errors"), breadcrumb "Clinical governance" vs tab "Clinical"
  - *Rule/heuristic:* A.
  - *Fix:* Use sentence case, one label source per enum, and the tab label in breadcrumbs.

- **[P3] "Today" uses the UTC date, so review-overdue highlighting is a day off on NZ mornings** — `Policies/Index.tsx:111`, `Policies/Show.tsx:77`, `Policies/_dialogs.tsx:201-209`
  - *Rule/heuristic:* H.
  - *Fix:* Use `toDateInput(new Date())` (local date), as Compliance does.

- **[P3] Developer wording in dialogs** — `Documents/_dialogs.tsx:127-128` ("downloaded through access checks"), `Compliance/_dialogs.tsx:481` and `Risks/_dialogs.tsx:512` ("A guided wizard to register…"), `Clinical/Dashboard.tsx:262-265` ("Automated source")
  - *Rule/heuristic:* A.
  - *Fix:* "Only people with access to board documents can open this file." / "Add a legal or funding requirement the organisation must meet." / "Where these numbers come from".

- **[P3] Small form friction** — `Risks/_dialogs.tsx:1172, 1256` (Accept disabled with no character count) · `Risks/_dialogs.tsx:1040-1042` (staff emails in the assignee picker) · `Compliance/Show.tsx:384` (green icon on expired evidence) · `Reports/ComplianceStatus.tsx:191, 223` ("obligation(s)", "day(s)") · `Policies/Index.tsx:235` (search mentions a "code" never shown)
  - *Rule/heuristic:* C/D/G/A.
  - *Fix:* Live counter "32 / 50 characters", names only in the picker, a warning icon when expired, proper plurals, placeholder "Search policies…".

## Patterns across these pages

- **Numbers without meaning.** Risk scores, appetite, control effectiveness, clinical counts, compliance rate and "snapshots" are shown as bare figures. A shared "How this works" popover and a plain-language glossary (before/after controls, board's limit, read-and-confirm) would fix most of the A findings at once.
- **Header counts and linked lists define things differently.** Active vs all statuses, stored vs calculated status, 7 vs 30 days, inherent vs residual, frameworks left out of totals. Each block's count and destination should come from one server-side query scope.
- **Chips that stay green when data is stale or missing.** "Within appetite", "On track", "On target" and zero headlines appear with no data or old statuses. Every chip and headline needs a "No data yet" / "Not available" state.
- **Flows that stop dead.** Accept risk, complete a treatment, close a risk, new policy version, open compliance evidence, re-confirm a policy: the button or wording exists, the action doesn't. Either finish each flow or hide the promise.
- **Too many names for the same thing, and the same name for different things.**
  - Obligations (Compliance) vs obligations (Te Tiriti).
  - Policies in three places.
  - Records / Records search / Governance records.
  - Decisions / Resolutions / Carried resolutions.
  - Attestation / sign-off / confirm.
  - Compliance labels hard-coded in three files.

  One naming table (with `governance-sections.ts` as the source) plus server-sent label maps is needed.
- **The four Reports pages were never moved to the new design.** They use hand-rolled pills, raw palette classes, ad-hoc type sizes, decorative meters, duplicate stat cards and bare empty states, and they carry the most obvious data bugs (owner always Unassigned, dead status links). Treat them as one migration.
- **NZ content accuracy needs a subject-matter pass.** The framework list is missing the Code of Rights and a disability funder, and the Te Tiriti principles are mis-paired. Have a quality or compliance lead and a Māori advisor check the wording before GOV-A28 comprehension testing.
