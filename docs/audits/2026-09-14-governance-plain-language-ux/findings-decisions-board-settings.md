# Findings — resolutions, actions, packs, CEO reports, board & settings

Code review, 14 September 2026, read-only. Part of audit.md in this folder.

- **[P0] Adding a note to your vote records a false conflict of interest** — `resources/js/pages/Governance/Resolutions/Show.tsx:845-877` (+ `app/Domain/Governance/Services/VotingService.php:167`)
  - *What a board member experiences:* The ballot has a box labelled "Vote note (optional)" with the hint "An optional explanation for your vote…". Whatever they type is sent as `conflict_note`, and the server sets `conflict_declared => !is_null($conflictNote)`. So a member who explains why they voted For gets a "Conflict noted" badge on their receipt (`Show.tsx:935-940`), and the frozen official result says they had a conflict.
  - *Rule/heuristic:* H (truthfulness), A.
  - *Fix:* Send the note as its own field (e.g. `vote_note`) and never set `conflict_declared` from it. Only a real `DeclareConflictDialog` declaration should do that. Label the box "Reason for your vote (optional, shown in the minutes)".

- **[P0] A vote is final, but nothing says so and nothing asks you to confirm** — `Resolutions/Show.tsx:824-883` (+ `VotingService.php:153-159`)
  - *What a board member experiences:* One click on "Submit vote" records the ballot. Trying to change it later fails with the server message "Board member has already cast a vote on this resolution". The ballot never explains For, Against or Abstain. For example, an abstention has no effect on an ordinary vote but defeats a unanimous one (`Resolution.php:559-587`).
  - *Rule/heuristic:* C (confirm consequential actions), D, A.
  - *Fix:* Add a `ConfirmDialog`: "Cast your vote: **For**? You can't change a vote once it's recorded. Motion: '…'". Put one plain line under the choices, driven by the threshold. Ordinary: "Passes if more members vote For than Against. Abstaining doesn't count either way." Unanimous: "Passes only if every entitled member votes For. Abstaining, voting Against or stepping aside means it does not pass."

- **[P0] Actions that need evidence can't be completed by a normal person** — `resources/js/pages/Governance/Actions/_dialogs.tsx:624-706` (+ `app/Domain/Governance/Models/ActionItem.php:254-283`)
  - *What a board member experiences:* "Complete action" asks for "Evidence documents", hinted "Managed storage paths", with the placeholder `governance/evidence/signed-policy-v2.pdf`. The warning reads "Reference documents already held in managed Governance storage; public website files … are rejected." A member has no way to know such a path, and there is no upload. Any other value fails with "Evidence file '…' does not exist or has not been uploaded to managed storage." This task can't be done.
  - *Rule/heuristic:* C, D, A. DESIGN.md: reuse `components/ui/file-dropzone.tsx`.
  - *Fix:* Replace the path box with the shared `FileDropzone`/`AttachmentUploader` and add an upload route. Explain what counts, e.g. "Upload proof the action is done, such as the signed document, the email confirming it, or a photo or report." List the attached files by name with download links, not storage paths (`Actions/Show.tsx:619-639`).

- **[P0] Live voting is blocked, and the in-app instructions to unblock it go round in a circle** — `resources/js/pages/Governance/Settings/_dialogs.tsx:126-144`, `Settings/Index.tsx:437-446` (+ `VotingService.php:46-49`, `GovernanceVotingProfileService.php:141-144`)
  - *What a board member experiences:* The chair clicks "Publish & open voting" (`Resolutions/Show.tsx:407-428`; the button gives no warning). They get the flash "Voting rules not confirmed — live voting unavailable. A secretary or chair must activate an approved voting profile with governing document authority." In Settings, the Activate dialog says "Author a decision paper bound to the rules, carry it, then return here". But carrying a paper needs live voting, which needs activated rules. Unless an approval record is seeded outside the app, nobody can ever open a vote.
  - *Rule/heuristic:* C (blocked states must say who can unblock and how), H, A.
  - *Fix:* Offer a first-time path. For example, "Record the board's original approval of these rules": the governing document, the date and the approving meeting's minutes, done by the chair with a confirmation. Show a critical banner on Resolutions Index/Show and on Settings while rules aren't live: "Board voting is switched off until the voting rules are confirmed. Ask the chair or board secretary to confirm them in Settings." Disable "Publish & open voting" with that text visible, not in a tooltip.

- **[P1] Papers marked "For information — Noting only — no vote" still go to a vote** — `Resolutions/_dialogs.tsx:116-135` (+ `Show.tsx:407-428, 807-896`; `VotingService.php:25-70` never checks `purpose`)
  - *What a board member experiences:* The author picks "For information". The paper still gets "Publish & open voting", a ballot, quorum, and a Carried/Defeated outcome. Members are asked to vote on something that was only meant to be noted.
  - *Rule/heuristic:* H, B.
  - *Fix:* For `discussion`/`information` papers, hide the voting threshold, deadline and follow-up step in the wizard. Replace "Publish & open voting" with "Publish to members", and make the server refuse `openVoting` for non-decision papers.

- **[P1] Follow-up actions are promised when a motion passes, but never created** — `Resolutions/_dialogs.tsx:1907-1910` (+ `app/Domain/Governance/Models/Resolution.php:374`; `ResolutionController.php:196-221` never sets `auto_generate_actions`, which defaults to false)
  - *What a board member experiences:* The wizard says "Created as accountable actions when the motion carries." After a carried vote, no actions appear. The Actions empty state repeats the promise: "Actions are created from carried decisions…" (`Actions/Index.tsx:378`).
  - *Rule/heuristic:* H.
  - *Fix:* Set `auto_generate_actions = true` whenever `follow_up_actions` are supplied, or remove the promise. On the closed-paper result, list the created actions, e.g. "3 follow-up actions created → view".

- **[P1] Voting and CEO report deadlines are saved about 12 hours out** — `Resolutions/_dialogs.tsx:469-471, 1889-1900`; `CeoReports/_dialogs.tsx:380-386`; `CeoReports/Show.tsx:691-695` (+ `config/app.php:99` `timezone => UTC`; `ResolutionController.php:214, 328` store the wall time as UTC)
  - *What a board member experiences:* The secretary sets voting to close at 5:00 pm. The wizard review shows 5:00 pm, but members see it closing at 5:00 am the next day (the wall time is saved as UTC and then shown in NZ time). Reopening the wizard shows 05:00. CEO report deadlines and their default period dates come from `toISOString()` and drift the same way. `lib/datetime.ts:241-246` documents exactly this bug and provides `toDatetimeLocal`.
  - *Rule/heuristic:* H, D.
  - *Fix:* Prefill with `toDatetimeLocal()`. Convert NZ wall time to UTC on the server, as the Meetings wizard now does. Build CEO defaults with `toDateInput()`/`toDatetimeLocal()`.

- **[P1] Results say "Carried" or "Defeated" without saying why, and the vote-rule labels are wrong and inconsistent** — `Resolutions/_helpers.ts:79-86`, `Resolutions/_dialogs.tsx:85-107`, `Resolutions/Show.tsx:945-1008` (+ `Resolution.php:543-587`)
  - *What a board member experiences:* There are three tiles, "For (60%)", "Against (40%)" and "Abstain", plus an outcome chip. Nothing says what was needed. The labels contradict each other and the rules engine:
    - The wizard's "Ordinary" says "Simple majority (>50%)" but the table says "Simple majority (50% + 1)". The engine actually counts For > Against of votes cast.
    - "Special" is two-thirds in the wizard, while `special_majority` displays as "Special majority (75%)".
    - "Unanimous (100% entitled)" is jargon.
    - Written (no-meeting) papers need unanimity whenever `written_unanimity_required` is on (default true), but still display "Simple majority".
  - *Rule/heuristic:* A, H.
  - *Fix:*
    - Add a one-sentence outcome, e.g. "**Passed.** 5 voted For and 2 Against (1 abstained). It needed more For than Against votes, and at least 4 of 7 members taking part (7 did)."
    - Rename the options "More For than Against", "At least two-thirds For" and "Everyone entitled votes For".
    - Show the rule actually applied, including the written-resolution override.

- **[P1] The voting area goes silent for anyone who can't vote right now, and conflicts can only be declared from inside the ballot** — `Resolutions/Show.tsx:807-896` (+ `ResolutionController.php:395-398` allows declaring at any time)
  - *What a board member experiences:*
    - A member reading a draft, someone not in the electorate, a staff viewer, or anyone after the deadline sees no voting section and no reason.
    - Ballots still show once the deadline has passed but before a manager closes the vote. Submitting then fails with "Voting deadline has passed".
    - "Declare a conflict" only exists inside an open ballot, so a member can't declare while reading the paper before the meeting.
    - A member who declared a conflict but didn't withdraw sees no sign of it.
  - *Rule/heuristic:* C, B, E.
  - *Fix:*
    - Always render a "Voting" card with a plain state: "Voting hasn't opened yet — the chair opens it at the meeting", "You're not on the voting list for this paper because…", or "Voting closed on 12 Sept".
    - Hide the ballot once the deadline has passed.
    - Move "Declare a conflict" into the header actions for every draft or open paper the member can see, and show "You declared an interest on 3 Sept" when one exists.

- **[P1] Declaring a conflict uses legal jargon and hides the consequences** — `Resolutions/_dialogs.tsx:660-685, 753-757, 805-812, 836-840`
  - *What a board member experiences:* The dialog uses:
    - Choices "Material interest", "Related party: A related-party transaction." and "Bias or loyalty: Prejudicial bias or a loyalty conflict."
    - The description "Formally declare an interest in RES-2026-004. Withdrawing from voting excludes your seat without recording an abstention."
    - The note "Declaring a conflict and withdrawing does not reduce the quorum denominator."

    Nothing says that on a unanimous paper, stepping aside means it cannot pass (`Resolution.php:571`).
  - *Rule/heuristic:* A, C. Decision doc: "Declare a conflict and see its consequences for participation".
  - *Fix:*
    - Title it "Tell the board about a conflict of interest — [paper title]".
    - Use the choices "Money or business interest (you, or someone close to you, could gain or lose)", "Family or close relationship", "Other loyalty (another organisation, a past role)" and "Something else".
    - Replace the note with "If you step aside, you won't vote and your name is recorded as stepping aside. You still count towards the board's size, so the others need enough people to take part. On a paper that needs everyone's agreement, stepping aside means it cannot pass."
    - Pick one vocabulary shared with the Interests register (see the Patterns section).

- **[P1] The board pack page is a management dashboard, not somewhere to read the pack** — `resources/js/pages/Governance/Packs/Show.tsx:247, 270, 595-801`
  - *What a board member experiences:* The title is "Board Pack · Revision 2", with the meeting name only in the subline. The body leads with "Distribution & engagement" (everyone's read and download rates, `596-669`, not gated to managers the way the Index column is), then "Included sections" with meaningless "auto"/"attachment" type badges (`705`, `793-795`). The papers themselves are only titles plus counts ("3 pending resolution(s)", "Variance Unavailable"), with no link to open the CEO report or any decision paper, and no link back to the meeting. The only real way to read is "Download PDF".
  - *Rule/heuristic:* B, E, A. Decision doc: "Open and read the correct published pack/revision and supporting papers".
  - *Fix:*
    - Title it "[Meeting title] — board pack".
    - Put "Read the pack" first: Download PDF, then each section as a linked row ("CEO report → open", "Decision papers (3) → open each"), then supplementary documents.
    - Add "Go to meeting" (the meeting workspace).
    - Gate the Distribution card to `canManagePack` and drop the type badges.

- **[P1] Superseded-pack notice sends members to a page they can't open; the new-version dialog is misleading** — `Packs/Show.tsx:397-423, 837-859` (+ `BoardPackBuilderService.php:94-112` creates the new revision as current but undistributed; `BoardPackAccessService.php:56-60` hides undistributed packs from members)
  - *What a board member experiences:* After a manager clicks "Create new version", every member's copy says "A newer version (Revision 3) has been published" with a "View current version" button, which returns 404 until someone distributes it. The manager's dialog promises "New Revision 3 will become the active version for members" and "Board members will be required to acknowledge reading", which isn't true until distribution.
  - *Rule/heuristic:* H, E.
  - *Fix:* Only show the notice when the newer revision is distributed to this viewer. Change the dialog to "Revision 3 is created as a draft. Members keep seeing Revision 2 until you distribute Revision 3." Or distribute automatically when the previous revision was distributed.

- **[P1] "Record this paper approves" is written for lawyers, not board members** — `Resolutions/_dialogs.tsx:1415-1513, 2354-2363`; `Resolutions/Show.tsx:584-633` (+ group labels `GovernanceResolutionAuthorityService.php:138, 213-218`)
  - *What a board member experiences:* The picker groups are auto-generated from keys ("Voting profiles", "Budget adjustments", "Performance reviews"). The labels read like "Board voting rules — Trust Deed (1.0)", "Budget 2026 — Staff: Increase $5,000.00" and "Performance review — Jane · 2026". Help text: "A carried resolution can only approve the exact record and revision selected here. Choose 'No specific record' to remove a binding." Also "Currently bound". The paper shows "revision 3 · bound 3 Sept 2026, 4:12 pm" with "Awaiting decision" or "Applied".
  - *Rule/heuristic:* A, D.
  - *Fix:*
    - Rename the field "What will this decision approve? (optional)" with the default "Nothing specific — a general decision".
    - Group names: "A budget", "A budget change", "The strategic plan", "CEO performance review", "Voting rules".
    - Help: "If the motion passes, the app applies it to this exact version. If someone changes the record before then, the board will need to approve the new version."
    - On the paper: "This decision approves: [label] (version 3) — Not yet applied / Applied on 12 Sept".

- **[P1] Settings voting rules are unreadable, partly fake, and hide the switches that matter** — `resources/js/pages/Governance/Settings/Index.tsx:135-157, 297-411, 449-533`
  - *What a board member experiences:*
    - The card is titled "Governance rules & electorate (D1 authority)".
    - Its description reads "Candidate rules apply strict majority quorum (floor(N/2)+1)… Recused members are excluded from presence without reducing the denominator. Live voting remains blocked…" and stays the same even once rules are confirmed.
    - Fields show `charitable_trust`, "majority_floor_plus_one — floor(N/2)+1", and a free-text "Ordinary decision threshold" of "for > against of valid votes cast". The engine never reads that text (`Resolution.php:582-587`), so editing it changes nothing.
    - The reference defaults to "Candidate Governance Profile (Pending D1 Legal Authority)" (`GovernanceVotingProfile.php:96`).
    - `written_voting_permitted` (default false) and `written_unanimity_required` are in form state but never rendered, so standalone papers always fail with "Written / out-of-session voting is prohibited…".
    - The table header reads "Electorate denominator (N = 5 entitled voters)".
  - *Rule/heuristic:* A, H, D.
  - *Fix:*
    - Retitle the card "How the board votes".
    - Add a plain summary: "Right now 5 members can vote. A decision needs at least 3 of them taking part. Ordinary decisions pass when more vote For than Against."
    - Use dropdowns with readable options (Legal form: "Charitable trust / Incorporated society / Company").
    - Remove fields the engine doesn't use.
    - Add toggles "Allow decisions by written vote between meetings" and "Written votes need everyone's agreement".
    - Make the description depend on the status.
    - Rename the table "Who can vote" with a "Why not?" column ("Observer", "Term ended").

- **[P1] Other Settings ask for developer values** — `Settings/Index.tsx:547-598` (+ `GovernanceSettingController.php:28-86`)
  - *What a board member experiences:*
    - "Final escalation recipient: User ID receiving notifications…" wants a number typed in.
    - Every field shows "Key: `spend_approval.threshold.capex` · Default: 5000".
    - Money thresholds have no $ or NZD.
    - Labels include "Capex threshold", "Opex threshold" and "requires a Resolution sign-off".
    - The success flash "Updated 7 setting(s)." counts untouched fields.
  - *Rule/heuristic:* A, D.
  - *Fix:*
    - Use a person picker for the recipient.
    - Hide keys and show "Default: $5,000".
    - Relabel: "Big purchases (equipment, vehicles, buildings) over this amount need board approval"; "Single bills over this amount need approval".
    - Add a `$` prefix.
    - Flash "Saved 2 changes."

- **[P1] Server messages show raw codes and developer wording** — `app/Domain/Governance/Http/Controllers/ResolutionController.php:476, 493, 497, 512`; `VotingService.php:48, 54, 95, 99, 105, 121, 134, 145, 158`; `ActionItem.php:148-324`
  - *What a board member experiences:* Examples:
    - "Voting closed. Outcome: no_quorum"
    - "Cannot mark resolution as implemented unless outcome is carried (outcome is 'defeated')."
    - "Board member has recused and withdrawn from voting on this resolution"
    - "Board member was not part of the electorate when voting opened."
    - "A completed action item is in a terminal state and cannot be modified."
    - "Resolution finalized." (US spelling)
  - *Rule/heuristic:* A, C.
  - *Fix:* Examples of plain versions:
    - "Voting closed — the motion passed / didn't pass / no decision (not enough members took part)."
    - "You stepped aside from this vote, so you can't vote on it."
    - "You joined the board after voting opened, so you can't vote on this paper."
    - "This action is already complete."
    - "Decision finalised."

- **[P1] Consequential commands run with a single click** — `Resolutions/Show.tsx:407-428` (Publish & open voting), `1152-1171` (Archive); `CeoReports/Show.tsx:658-673` + `_dialogs.tsx:726-737` (Submit to board / Mark as presented); `Packs/Show.tsx:365-376` (Distribute pack); `Evaluations/Show.tsx:176-191` (Close / Launch)
  - *What a board member experiences:* Opening a vote freezes the paper for everyone. Submitting a CEO report locks it. Distributing a pack sends it to all members. Archiving ends a decision. Closing an evaluation stops responses. All of these happen instantly, with no "are you sure" and no preview of who is affected.
  - *Rule/heuristic:* C, F (POPUP guide confirmation dialogs).
  - *Fix:* Add `ConfirmDialog`s that state the effect, e.g. "Open voting on 'X'? The paper can't be edited after this, and 7 members will be asked to vote by 20 Sept, 5:00 pm." "Send the pack to 7 board members?" "Submit your report to the board? You won't be able to edit it afterwards."

- **[P1] "Escalate" claims to alert the chair, but notifies nobody** — `Actions/_dialogs.tsx:361-364` (+ `ActionItem.php:313-336`; `EscalateOverdueActionItems.php:49-51` notifies only the assignee)
  - *What a board member experiences:* The dialog says "Alert the board chair and secretariat that this action needs governance intervention." The action only bumps priority and records a reason; no one is told.
  - *Rule/heuristic:* H, A.
  - *Fix:* Notify the chair and secretary (and say so in the success toast: "Chair and secretary notified"), or reword to "Flag this action as needing the board's attention (it will be raised as high priority)". Replace "secretariat".

- **[P1] Evaluation answers fail with raw error screens** — `resources/js/pages/Governance/Evaluations/Show.tsx:246-388` (+ `BoardEvaluationController.php:177-221`)
  - *What a board member experiences:* Nothing is marked required, but leaving any question blank causes `abort(422, "Answer for question 3 is required.")`, which Inertia shows as an error page instead of an inline message. The form is still offered after the due date ("The submission deadline for this evaluation has passed." as a 422), and to viewers who aren't board members (403).
  - *Rule/heuristic:* C, D.
  - *Fix:* Mark every question required and validate before submit. Return a `ValidationException` keyed by question. Hide the form with an `EmptyState` when the deadline has passed or the viewer isn't a board member ("Only board members answer this evaluation").

- **[P1] Evaluation results hide every written comment** — `Evaluations/Results.tsx:113-161, 327-333` (+ `BoardEvaluationController.php:304-311`)
  - *What a board member experiences:* Free-text questions show "Free-text question — answers are not summarised here", and "Overall comments" appear nowhere, even though the answers are in the payload. The chair can't read the board's feedback, which is the point of an evaluation.
  - *Rule/heuristic:* B, H.
  - *Fix:* Under each text question list the comments anonymously and shuffled ("Comments (4)"), plus an "Overall comments" section, with a note: "Comments are shown without names."

- **[P1] CEO report main sections sit in a tab strip below the header, plus a duplicate status strip** — `resources/js/pages/Governance/CeoReports/Show.tsx:12, 145-251, 716-721`
  - *What a board member experiences:* Under the header sit six cards repeating Period, Deadline, Author, Status, Meeting and Submitted, then a tinted 10-tab `PageTabs` row ("Risk & Compliance" in Title Case). It's heavy and unlike the rest of Governance.
  - *Rule/heuristic:* F — "Main view tabs below the hero", record profile tier-2 sub-nav (`TierTwoTabs`), ad-hoc `text-[10px]` tiles.
  - *Fix:* Use the profile tier-2 strip (or render the report as one scrolling document with a contents list). Delete `StatusStrip`, since those facts belong in the subline and meters. Rename "Risk & Compliance" to "Risk and compliance".

- **[P1] CEO report KPI snapshot shows "0" in green when data is missing** — `CeoReports/Show.tsx:274-338` (+ `CeoBoardReportController.php:307-316` returns null per failed widget)
  - *What a board member experiences:* If a widget failed or had no data, the snapshot reads "Critical risks 0", "Overdue obligations 0" and "Budget variance 0.0%", all green. That tells the board everything is fine when the app simply doesn't know.
  - *Rule/heuristic:* H.
  - *Fix:* When the source object is null or missing, show "Not available" in muted text, never 0/green. Explain terms like "Risks above appetite" ("risks higher than the board has said it will accept").

- **[P1] Clearing a board member's term end silently does nothing** — `resources/js/pages/Governance/Admin/_dialogs.tsx:267-278, 445-458` (+ `BoardMemberAdminController.php:76-84`)
  - *What a board member experiences:* The Term end field says "Leave blank if ongoing". Clearing it shows "Appointment updated", but the old end date stays, because the transform drops an empty `term_end` (the code comment admits it). "Term start" is also greyed out with no reason.
  - *Rule/heuristic:* H, C.
  - *Fix:* Support clearing (nullable `term_end`), or block it with an inline message "To make a term ongoing, contact…". Add a hint: "Start date can't be changed — remove and re-appoint to correct it."

- **[P1] Board members can only be appointed from staff accounts, with no guidance** — `Admin/_dialogs.tsx:387-406`, `Admin/BoardMembers.tsx:225-234` (+ `BoardMemberAdminController.php:23-27` `User::staff()`)
  - *What a board member experiences:* "Appoint a staff member to the board" offers a "Staff member" picker. Volunteer, family and community board members usually aren't staff, so they don't appear. When the list is empty it says "Everyone eligible is already on the board", which is false and suggests no next step.
  - *Rule/heuristic:* D, C, H.
  - *Fix:* Label it "Person". Explain: "People need an Oblivion Care login first. Don't see them? Ask an administrator to invite them (Settings → Users)." Link to the invite flow, and say "No people without a board seat" rather than "Everyone eligible…".

- **[P1] Interest declarations can't be changed or marked ended, and rows have no menu** — `resources/js/pages/Governance/Interests/Index.tsx:325-337`, `Interests/MyInterests.tsx:279-291` (+ `BoardInterestController.php:76-93` update route unused)
  - *What a board member experiences:* A member who resigns from another organisation has no way to mark that interest "Ceased" or fix a typo. Rows have no kebab or right-click menu (`actionsFor={() => []}`).
  - *Rule/heuristic:* C, F (List contract: kebab + context menu on every row).
  - *Fix:* Add a row menu on your own declarations with "Update details" and "This interest has ended…", the latter asking for an end date. Other members' rows get "View".

- **[P1] Audit log is written for developers** — `resources/js/pages/Governance/AuditLog/Index.tsx:93-95, 150-199, 415-427`
  - *What a board member experiences:* Rows read "resolution voted" / "Resolution #12", "GovernanceSetting #0" or "BoardPack #4". Filters list class names ("BoardPack") and codes ("board_pack.attachment_added" → "board pack attachment added"). A "Kind: Action/Change" column and a prominent IP address column add noise. The Details column is often "—". Old and new values are never shown, rows don't link to the record, and there's no menu. The CSV headers are "EntityType, UserId".
  - *Rule/heuristic:* A, E, F (List contract).
  - *Fix:*
    - Write sentences: "Jane Smith voted on 'Approve 2026/27 budget'", "Settings: Big purchase limit changed from $5,000 to $10,000".
    - Link the record title and add a "What changed" expandable row.
    - Name the filters "Record type" and "Activity".
    - Move the IP address into row detail.
    - Export names, not IDs.

- **[P2] The same things have different names across the four hubs** — `lib/governance-sections.ts:97-110, 74-86, 252-274, 283-296` vs page titles and breadcrumbs
  - *What a board member experiences:* The sidebar says "Decisions & actions". Tabs say "Resolutions" and "Action items". Pages say "Resolutions" (`Resolutions/Index.tsx:220`), with the button "New decision paper" (`235`) and the list "Decision papers" (`382`), and "Actions" (`Actions/Index.tsx:217`) with the list "Action register" (`363`). The tab "Board packs" leads to the title "Board Packs" (`Packs/Index.tsx:219`) and the dialog "Generate Board Pack". The tab "CEO reports" leads to "CEO Board Reports" (`CeoReports/Index.tsx:163`) and the record title "CEO Report — …". "Members" becomes "Board members", "Interests" becomes "Interests register", "Settings" and "Audit log" become "Governance settings" and "Governance audit log". Breadcrumbs skip the hub ("Governance → Resolutions"), so the sidebar name never appears on the page.
  - *Rule/heuristic:* A, E.
  - *Fix:* One scheme, sentence case:
    - "Decisions" for the tab, the page and "New decision"; "decision paper" for the document.
    - "Actions" for the tab, page and list.
    - "Board packs", "CEO reports", "Board members", "Interests", "Evaluations", "Settings", "Audit log".
    - Breadcrumbs `Home → Governance → Decisions & actions → Decisions`.

- **[P2] Internal reference codes are used as names** — `Actions/Show.tsx:229, 252, 255, 367-377, 396, 401`; `Resolutions/Show.tsx:376, 921`; `Resolutions/_dialogs.tsx:754`; `Actions/_dialogs.tsx:457, 598`; `Resolutions/Index.tsx:360, 437`; `Actions/Index.tsx:409`
  - *What a board member experiences:*
    - Breadcrumb and tab title read "Action ACT-2026-004".
    - The "Source" meter's big value is "RES-2026-012".
    - Sublines start with codes; the action subline ends in "v3".
    - Dialogs say "declare an interest in RES-2026-004", "Sign off ACT-…" and "Transfer accountability for ACT-…".
    - The receipt says "Official record of your vote on RES-… (paper v2)".
  - *Rule/heuristic:* A.
  - *Fix:* Use titles everywhere ("Complete 'Issue the revised contract'"). Move the codes to the end of the subline as "Ref RES-2026-012" and drop "v3" from action sublines.

- **[P2] Raw lowercase or snake_case values show through** — `Resolutions/Show.tsx:642` (`{purpose} paper` → "decision paper"), `644-647` (`decision_type` "strategic"), `907` ("Nature: material"), `927` ("FOR"), `933` ("Method: electronic"), `1036` ("for"/"against"), `1056` ("— material"); `Actions/Show.tsx:504-507` ("no quorum", "carried"); `Packs/_dialogs.tsx:146` ("SCHEDULED"/"IN_PROGRESS"); `Settings/Index.tsx:483-485` (role "capitalize"); `app/Domain/Governance/Support/BoardPackPresenter.php:127-137` ("Variance Unavailable", CEO report "draft")
  - *What a board member experiences:* Database values appear in badges and sentences, cased inconsistently.
  - *Rule/heuristic:* A, F (StatusBadge labels via helpers).
  - *Fix:* Map everything through the existing label helpers (`resolutionOutcomeLabel`, `boardRoleLabel`, `CONFLICT_TYPES`, `DECISION_CATEGORIES`), and add a meeting-status label helper for the Generate dialog.

- **[P2] Jargon and a misleading "no cost" line on the decision paper** — `Resolutions/Show.tsx:524-535, 546-549, 575-579, 727, 772-776, 950-952, 1104-1106`
  - *What a board member experiences:*
    - "Frozen paper (v2). This text was locked when voting opened; every vote evaluates these exact terms."
    - "Immutable decision snapshot captured at closure."
    - "Single option justification".
    - "Reason remaining follow-up actions are not required (optional)".
    - Server readiness errors in red, such as "The exact motion (operative text voted upon) is required." and "…(or marked not applicable)", with no "not applicable" option in the wizard.
    - "Explicitly confirmed: no direct cost." even when cost was never filled in.
  - *Rule/heuristic:* A, H.
  - *Fix:*
    - "This is the final wording (version 2). It can't change while voting is open."
    - "Final result, recorded when voting closed."
    - "Why there's only one option".
    - "If follow-up actions are still open, say why the decision is done anyway".
    - Show "Cost not stated" unless `has_cost === false` was explicitly saved.
    - Reword server readiness errors to match the wizard's plain messages.

- **[P2] Record headers carry two or three status chips** — `Resolutions/Show.tsx:354-373` (v2 + status + outcome); `Actions/Show.tsx:232-249` (status + priority + overdue); `Packs/Show.tsx:249-268` (current + distributed + failed); `CeoReports/Show.tsx:561-578`
  - *What a board member experiences:* A row of chips competes with the title, and it's unclear which one is "the" status.
  - *Rule/heuristic:* F — "plain title + one `<StatusBadge>` chip".
  - *Fix:* One chip giving the most useful state ("Passed", "Overdue", "Superseded"). Put the version and priority in the subline or meters.

- **[P2] Board pack list copy and meters don't suit members** — `Packs/Index.tsx:84-89, 220, 255-275, 330`
  - *What a board member experiences:* The subline reads "Immutable, audience-safe packs assembled for meetings · decision papers, snapshots and reading receipts". Members only ever see distributed packs, yet get "Draft" and "Superseded" meters that are always 0 or irrelevant. "Draft" is warning-toned. Nothing shows which pack is for the next meeting or whether you've read it.
  - *Rule/heuristic:* A, B, F ("Dead or decorative meter blocks").
  - *Fix:*
    - Subline: "The reading pack for each board meeting".
    - Members' meters: "Next meeting pack", "Not yet read by you", "All packs".
    - Add a "Your reading" column ("Read 3 Sept" / "Not read").
    - Make Draft neutral.
    - Empty state: "Packs appear here once the secretary sends them for a meeting."

- **[P2] "Generate board pack" dialog is hand-rolled and lets you pick meetings that will fail** — `Packs/_dialogs.tsx:74-81, 108-176`
  - *What a board member experiences:*
    - Meetings with "0 agenda items" can be selected, and the click then fails with "Failed to generate the board pack. Make sure the meeting has at least one agenda item."
    - Status shows as raw uppercase.
    - The empty state is a dashed `div` that says "Every scheduled meeting already has a board pack".
    - Tiles are `Button unstyled` with hand-coloured `Badge`s.
    - Time uses the browser timezone, and nothing says whether generating sends anything to members.
  - *Rule/heuristic:* C, F (`TilePicker`, `StatusBadge`, `EmptyState`), H.
  - *Fix:*
    - Disable tiles without an agenda, with visible text "Add an agenda first".
    - Use `TilePicker`/`StatusBadge`/`EmptyState` and `formatDateTimeLong`.
    - Add "Generating creates a draft pack — members won't see it until you distribute it."

- **[P2] "Mark as read" is inconsistent and invites marking before reading** — `Packs/Show.tsx:378-389, 478-513`
  - *What a board member experiences:* The white header button "Mark Rev 2 as read" is the primary action before the member has opened anything. A second button below says "Mark Version 2 as Read" under the heading "Board member reading required". The same thing is called Rev, Version, Revision, "v2" and "edition" (`283`). No confirmation.
  - *Rule/heuristic:* A, C.
  - *Fix:* Make "Download pack" (or "Read the pack") the primary action. Put a single "I've read this pack" button in the reading section, confirming "Confirm you've read the pack for [meeting] (version 2)?". Use "version" everywhere.

- **[P2] Decision paper and CEO report wizard reviews can't actually be reviewed, and success leaves you stranded** — `Resolutions/_dialogs.tsx:2192-2236, 2266-2312, 1104-1131`; `CeoReports/_dialogs.tsx:636-652, 955-958, 1000-1006`
  - *What a board member experiences:* The review shows "Background: 523 characters", "Motion: Provided" and "Recommendation: Provided" instead of the text the board will vote on. The deadline appears raw ("2026-09-10 21:00"). Success says "Open it from the register to attach supporting documents" (or "Save the draft first, then attach documents from the report page") but offers only "Done".
  - *Rule/heuristic:* D, C (WizardSuccessPane follow-up actions).
  - *Fix:* Show the exact motion and the first lines of each section in `ReviewRow`s, and format dates. Add "Open paper to attach documents" and "Open report" actions on success.

- **[P2] "Decided at the meeting" appears for papers with no meeting** — `Resolutions/Show.tsx:485-489`; `Resolutions/_dialogs.tsx:1884-1887, 2339`
  - *What a board member experiences:* A standalone ("Standalone paper (no meeting)") paper with no deadline shows "Deadline — · Decided at the meeting". The wizard never explains that a standalone paper is a written vote.
  - *Rule/heuristic:* H, A.
  - *Fix:* Rename the option "No meeting — members vote in the app (written resolution)" and require a deadline for it. Show "No deadline set" only when that's true.

- **[P2] "Vote now" from the register skips the meeting workspace** — `Resolutions/Index.tsx:191-195, 366-374`
  - *What a board member experiences:* A meeting paper opens on its standalone page, so after voting the member has lost the meeting and agenda context the approved navigation decision requires.
  - *Rule/heuristic:* E.
  - *Fix:* For papers with `meeting`, link "Vote now" to `/governance/meetings/{id}?tab=resolutions&paper={id}`.

- **[P2] Buttons disabled with only a tooltip saying why** — `Resolutions/Show.tsx:1122-1133` ("Mark implemented" → `title=` only), `410-415`; `Actions/_dialogs.tsx:719-724`; `Settings/_dialogs.tsx:213-220`
  - *What a board member experiences:* Greyed-out buttons with no visible reason (tooltips don't work on disabled buttons or touch).
  - *Rule/heuristic:* C.
  - *Fix:* Put a caption next to each, e.g. "Only decisions that passed can be marked done", "Add completion notes to continue".

- **[P2] Action record page repeats controls and styles non-destructive steps as destructive** — `Actions/Show.tsx:269-285, 294-298, 653-661, 698-748`; `Actions/_dialogs.tsx:304-310, 388-394, 488-490`
  - *What a board member experiences:*
    - "Update progress" appears in the header, the Progress meter and the side card.
    - "Complete action" appears twice.
    - "Mark as blocked" and "Escalate action" use red destructive buttons.
    - Reassign lists every approved user with their email.
    - "Deliverable & scope", "Standard verification", "Audited sign-off… durable receipt" and "Setting progress to 100% does not close an action" are jargon-heavy.
  - *Rule/heuristic:* B, F (Button guide), A.
  - *Fix:*
    - Keep one primary "Mark as done" in the header and a small secondary set in the side card.
    - Use the default or outline button style.
    - Show names only in Reassign.
    - Rename: "What needs doing", "Evidence needed: yes/no", "Done — receipt".
    - Explain once: "When the work is finished, mark it done — progress alone doesn't close it."

- **[P2] Automatic escalations say the owner escalated their own action** — `Actions/Show.tsx:426` (+ `EscalateOverdueActionItems.php:32-35` passes `$item->assigned_to` as the escalator)
  - *What a board member experiences:* "Escalated by Jane Smith (the owner) on 12 Sept… Automatically escalated due to overdue status."
  - *Rule/heuristic:* H.
  - *Fix:* Pass null for system escalations and render "Escalated automatically because it was overdue".

- **[P2] Evaluations: vague types, no edit, no rating anchors, and a meaningless completion figure** — `Evaluations/_dialogs.tsx:39-64`; `Evaluations/Show.tsx:266-301, 249-253`; `Evaluations/Results.tsx:101-104, 205-221`; `Evaluations/Index.tsx:137-150`
  - *What a board member experiences:*
    - "Committee" and "Individual — Individual member reflection" never ask which committee or person.
    - A draft can't be edited, so a question typo means starting again.
    - Ratings are bare 1–5 buttons.
    - Nothing says whether answers are anonymous.
    - Results "Completion" is always 100% ("of started responses submitted"), because responses only exist once submitted.
    - The list doesn't show whether you've responded.
  - *Rule/heuristic:* D, H, F (edit reuses the same wizard).
  - *Fix:*
    - Add a subject picker for committee or individual evaluations.
    - Reuse the wizard prefilled to edit drafts.
    - Anchor the scale "1 = Strongly disagree … 5 = Strongly agree".
    - Add "Your answers are shown without your name; the board sees who has responded".
    - Base completion on active members.
    - Add a "You: Responded / Not yet" column.

- **[P2] The interest declaration form is confusing and its meters imply non-compliance** — `Interests/_dialogs.tsx:185-252`; `Interests/Index.tsx:193-210`; `Interests/MyInterests.tsx:169-183, 228-234, 262-267` (+ `BoardInterestController.php:66`)
  - *What a board member experiences:*
    - "Nature of interest" and "Description" are both required and feel identical.
    - "From" is saved as the declaration date, so declaring today an interest held since 2019 records it as declared in 2019.
    - "Members declaring 3/7" suggests 4 members are failing when they may have nothing to declare.
    - "Board register: Linked / Not linked" is a jargon meter.
    - The unlinked message ("…not linked to an active board-member record yet…") doesn't say who fixes it.
    - A linked member without permission sees "No personal interest declarations are available for this account."
  - *Rule/heuristic:* D, H, C.
  - *Fix:*
    - Fields: "Organisation or person", "Your role or connection" (e.g. "Director", "My sister works there"), "How could it affect board decisions?".
    - Store `declared_at = now()` and treat From as the start of the interest.
    - Replace the meter with "Members who have updated their declarations this year" (or drop it).
    - "Ask the board secretary to add you as a board member."

- **[P2] Validation messages are Laravel defaults naming internal fields** — `ResolutionController.php:362-365, 400-405`; `app/Domain/Governance/Http/Requests/StoreResolutionRequest.php` (no `messages()`); `BoardInterestController.php:49-58`; `BoardMemberAdminController.php:39-48, 76-80`; `CeoBoardReportController.php:253-278`; `BoardEvaluationController.php:83-92`
  - *What a board member experiences:* For example "The voting deadline field must be a date after now.", "The date to field must be a date after date from.", "The term end field must be a date after term start.", "The description field must be at least 20 characters."
  - *Rule/heuristic:* D.
  - *Fix:* Add `messages()`/`attributes()`: "Pick a voting deadline in the future", "The end date must be after the start date", "Add a little more detail about the conflict (at least 20 characters)".

- **[P2] Rules activation dialog repeats a field and doesn't explain the settings** — `Settings/_dialogs.tsx:118-122, 147-179`; `Settings/Index.tsx:449-533`
  - *What a board member experiences:* Activation asks again for "Governing document reference" already on the page, with service errors like "Profile activation rejected: an actual governing document reference (e.g. constitution or trust deed) is required for live activation." The electorate table shows "Not entitled" with no reason, and "Voting seat: No (administrative)".
  - *Rule/heuristic:* D, A, C.
  - *Fix:* Prefill it read-only, or edit it only in one place. Reword the errors ("Enter the name of your trust deed or constitution"). Add a reason column to the table.

- **[P2] Action register filters and columns are unclear** — `Actions/Index.tsx:89-97, 493-501, 218`
  - *What a board member experiences:* The Status filter has both "Any status" and "All open", and "Not started" is the stored `open`. The Evidence column says "Required" but not whether evidence has been provided. The subline "Board decisions and follow-ups tracked to completion" doesn't say what to do.
  - *Rule/heuristic:* A, B.
  - *Fix:* Options "Any", "Still to do", "Not started", "In progress", "Overdue", "Stuck", "Done". Evidence column: "Needed — not yet added / Added / Not needed". Subline: "Follow-up work from board decisions — open yours to update or mark done."

- **[P2] Sentence case and NZ English slips** — `ResolutionController.php:493, 512` ("finalizing", "finalized"); `Packs/Index.tsx:219, 313`, `Packs/_dialogs.tsx:99`, `Packs/Show.tsx:239, 247, 404-405, 510` ("Board Pack", "Mark Version 2 as Read", "Revision 2 (Superseded)"); `CeoReports/Index.tsx:163, 252`; `CeoReports/Show.tsx:513`; `BoardPackPresenter.php:111-119` ("Cover & Meeting Overview", "Executive Dashboard Snapshot"); `BoardEvaluationController.php:307`; `GovernanceSettingController.php:33`
  - *What a board member experiences:* Mixed Title Case, US spelling, and a capitalised "Resolution" mid-sentence.
  - *Rule/heuristic:* A.
  - *Fix:* Sentence case throughout, "finalise/finalised", "requires board approval".

- **[P3] Ad-hoc typography and hand-rolled tiles** — `Actions/Index.tsx:127-130` (`text-[11.5px]`); `Packs/Show.tsx:608-635` (`text-lg font-semibold` stat tiles), `537-546` (`Badge` version pills); `CeoReports/Show.tsx:234, 378, 425` (`text-[10px]`), `372-391`; `Resolutions/Show.tsx:976-1008` (`text-page-title` used for numbers); `CeoReports/_dialogs.tsx:224-228, 298-301` (dashed-div empty states)
  - *What a board member experiences:* Slightly different sizes and tile styles from page to page.
  - *Rule/heuristic:* F (typography helpers, `EmptyState`, `StatusBadge`).
  - *Fix:* Use `.text-caption`/`.text-section-title`, the list cell library or `ops-stat-card`, and `EmptyState variant="compact"`.

- **[P3] Decorative or low-value meters** — `Settings/Index.tsx:211-220` ("Configuration 7"); `Interests/MyInterests.tsx:169-183`; `Admin/BoardMembers.tsx:225-234` ("Eligible staff" opens the wizard); `Resolutions/Index.tsx:275-286` ("Carried of N papers" counts drafts); `Packs/Show.tsx:323-336` ("Downloads — PDF retrievals"); `Evaluations/Show.tsx:220-238` ("Responses due" links to other evaluations)
  - *What a board member experiences:* Numbers with no "so what", and blocks that open a dialog or an unrelated list.
  - *Rule/heuristic:* F ("Dead or decorative meter blocks"), B.
  - *Fix:* Replace with meaningful counts ("Not yet responded: 3" → respondents list; "Passed this year"), or drop them.

- **[P3] Board member page copy nits** — `Admin/BoardMembers.tsx:211, 241-261, 284-286, 373-386`
  - *What a board member experiences:* "voting seats filled" (there is no seat count), filter labels "All roles" and "Any standing" ("standing" is jargon), and the list is headed "Current appointments" but includes inactive people. The "Remove from board" confirmation isn't styled as destructive.
  - *Rule/heuristic:* A, F.
  - *Fix:* "5 can vote", "Status: Active / Inactive / Term ending soon", "Appointments", and `variant="destructive"` on the confirm.

- **[P3] "Today" is computed in UTC** — `Admin/BoardMembers.tsx:70-73`; `Interests/_dialogs.tsx:134`; `Evaluations/Index.tsx:108`, `Evaluations/Show.tsx:99`
  - *What a board member experiences:* In NZ mornings the "From" date defaults to yesterday, and overdue or ending-soon flags are a day off.
  - *Rule/heuristic:* H.
  - *Fix:* Use `toDateInput(new Date())` from `lib/datetime`.

- **[P3] Colour alone marks things overdue; odd currency choices** — `Evaluations/Index.tsx:184-192` (overdue due date only turns red); `Resolutions/_dialogs.tsx:176` (AUD/USD/GBP/EUR offered), `1757, 1807` (placeholders "e.g. 85000", "e.g. OPEX FY27 regional services")
  - *What a board member experiences:* Overdue isn't announced in text. The cost form suggests foreign currencies and finance shorthand.
  - *Rule/heuristic:* G, A.
  - *Fix:* Add an "Overdue" chip. Default to NZD only, formatted "$85,000", with the placeholder "e.g. 2026/27 operating budget — regional services".

## Patterns across these pages

- **Plain words are missing where they matter most.** Quorum, electorate/"N", recusal, "carried", thresholds, bindings, "candidate/D1", "frozen/immutable/snapshot", capex/opex. A shared `GovernanceTermHint` (inline "What's this?" popover) and one short "How board voting works" explainer, linked from the ballot, results and Settings, would fix most A-class findings.
- **The server and UI disagree about what happens.** Examples: follow-ups that aren't created, "no vote" papers that get voted on, escalations that notify nobody, "Conflict noted" on vote notes, the "active version for members" pack claim, the Settings formula that does nothing, 0/green KPIs for missing data. Every promise in the copy needs a test or needs removing.
- **Consequential one-click commands have no confirmation.** This covers vote, open voting, distribute, submit, present, archive, launch and close. Adopt one confirm pattern that states the effect, who is affected and whether it can be undone.
- **Blocked or ineligible states are silent.** Voting sections disappear, buttons disable behind tooltips, and the voting-rules dead end is unexplained. Every blocked state should say what's blocked, why, and who can fix it.
- **Naming and casing drift across hubs.** Resolutions, Decision papers and Decisions; Actions, Action items and Action register; Rev, Revision, Version, v2 and edition; Title Case vs sentence case; internal RES/ACT codes used as names. One glossary in `governance-sections.ts` and label helpers per enum would stop raw `snake_case` leaking.
- **Timezone handling is inconsistent.** Several wizards and pages use `toISOString()`/`.slice(0,16)` or `toLocaleString` without the NZ timezone. Standardise on `toDatetimeLocal`/`toDateInput`/`formatDateTimeLong` plus server-side NZ wall-time parsing.
- **Record pages are built for managers first.** Distribution stats, audit-style receipts, IP addresses, storage paths and version fingerprints appear ahead of the member's actual task: reading, deciding, declaring, completing. Order record pages by the member's job, and gate management panels by permission.
- **Several rows break the list contract.** Some rows have no kebab or context menu (Interests, Audit log), and some flows have create but no edit (Evaluations drafts, interest declarations).
