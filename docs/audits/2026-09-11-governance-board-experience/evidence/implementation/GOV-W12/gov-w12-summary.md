# GOV-W12 Summary of Evidence: Role-Appropriate Meeting Preparation & Workflow Integrity

## 1. Overview
GOV-W12 / GOV-A12 addresses meeting preparation, attendance tracking, and workflow integrity across governance bodies. It ensures:
1. Role-appropriate preparation workflows: distinguishes board member preparation obligations (RSVP, reading board packs, reviewing resolutions) from administrative/secretary workflows (recording attendance, drafting minutes, publishing packs).
2. Committee-scoped attendance, quorum, and invitation boundaries: Committee meetings restrict quorum eligibility and invitation verification strictly to committee members (and meeting chair/secretary). Uninvited board members cannot RSVP to or affect quorum of a committee meeting.
3. Attendance truthfulness: Roll call defaults to "unrecorded" rather than presuming presence. Unrecorded status deletes existing attendance attribution rather than storing falsified presence. Late arrivals count towards quorum alongside present members.
4. RSVP lifecycle with durable receipts: Members can submit or update RSVPs (Attending, Apologies with reasons, Tentative) with dietary options, and receive verifiable receipt IDs (`RSVP-{meeting}-{member}-{timestamp}`).
5. Checklist truthfulness: CEO report requirements are accurately marked `not_applicable` for committee meetings, and previous meeting follow-through correctly scopes to the prior meeting of the same committee/body.

## 2. Changes Made

### Backend Models & Services
- `app/Domain/Governance/Models/GovernanceMeeting.php`:
  - `calculateQuorum()`: Scoped to active members of the associated `board_committee_id` (or all active board members for full board); counts both `present` and `late` arrivals; returns complete status array (`present`, `required`, `total`, `total_members`, `percentage`, `met`, `is_met`).
  - `isInvited(BoardMember $member)`: Checks active committee membership or chair/secretary role when `board_committee_id` is set; all active board members for full board meetings.
  - `updateQuorumStatus()`: Synchronizes `quorum_met` boolean.
- `app/Domain/Governance/Models/BoardMember.php`:
  - Added `committeeMemberships(): HasMany` relationship to `CommitteeMembership::class`.
- `app/Domain/Governance/Services/GovernanceWorkflowService.php`:
  - `previousMeeting()`: Scoped to same `board_committee_id` or `meeting_type` (no cross-committee pollution).
  - `meetingChecklist()`:
    - CEO report truthfully marked `not_applicable` for committee sessions.
    - Quorum/attendance item blocked status tailored to viewer permissions (members see "View Attendance" with detail that attendance is pending roll call).
    - `follow_through` detail accurately reports the title of the specific previous meeting of that committee.
- `app/Domain/Governance/Services/GovernanceWorkQuery.php`:
  - Default pending feed properly includes `blocked` work obligations (with explicit blocked badges and reasons) alongside `pending`, `due_soon`, and `overdue`.
- `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php`:
  - `submitRsvp()`: Strictly authorizes invited committee members (aborts 403 for uninvited board members); accepts `attending`, `apology`, `unsure` mapped to schema enum; records `decline_reason` and `dietary_notes`; flashes `receipt_id` on redirect and returns it in JSON response.
  - `recordAttendance()`: Handles `status => 'unrecorded'` by deleting existing records rather than recording presence; recalculates quorum.
  - `UpdateMeetingRequest.php`: Allowed `meeting_type`, `board_committee_id`, `quorum_required`.

### Frontend Components
- `resources/js/pages/Governance/Meetings/Show.tsx`:
  - Meeting Cockpit: Added interactive RSVP modal and status banner with options for Attending, Apologies (with reason), and Tentative, with dietary requirement toggles.
  - Attendance Record dialog: Defaults to `unrecorded`; supports `unrecorded`, `present`, `late`, `apology`, and `no_show`; displays each member's submitted RSVP status badge.
  - Attendance Tab: Renders clear empty state when attendance is unrecorded; displays members' recorded attendance alongside their RSVP status; includes dedicated "RSVP Responses" card with badges, decline reasons, and dietary notes.
- `resources/js/pages/Governance/Meetings/Create.tsx` & `Edit.tsx`:
  - Added Committee selection dropdowns and `meeting_type` controls.

## 3. Verification & Evidence
- **TypeScript Verification**:
  - `npm run types` (`tsc --noEmit`): exit code 0.
- **Pest Automated Tests**:
  - `tests/Feature/Governance/GovernanceMeetingsTest.php`: 16 passed, 122 assertions.
    - `test_invited_member_can_submit_rsvp_with_receipt` (PASSED)
    - `test_member_can_submit_apology_rsvp_with_reason` (PASSED)
    - `test_uninvited_member_cannot_rsvp_to_committee_meeting` (PASSED)
    - `test_attendance_status_unrecorded_removes_existing_attendance_record` (PASSED)
    - `test_late_arrival_counts_towards_quorum_and_committee_quorum_is_scoped` (PASSED)
    - `test_meeting_show_checklist_marks_ceo_report_not_applicable_for_committee` (PASSED)
    - `test_previous_meeting_follow_through_scoped_to_same_committee` (PASSED)
  - `tests/Unit/Governance/GovernanceWorkflowServiceTest.php`: 7 passed, 39 assertions.
- **Single-Tenant Boundary**: Verified zero tenant scoping, selectors, or multi-tenant code introduced.
