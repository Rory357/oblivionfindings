# GOV-W03 Evidence Summary: Board Rules, Appointments, and Electorate

## 1. Work Implemented
- **Task ID**: GOV-W03
- **Acceptance Criteria**: GOV-A03
- **Findings Addressed**: GOV-F04, GOV-F17, GOV-F22

### Key Changes:
1. **Governed Rules Profile (`governance_voting_profiles`)**:
   - Created database migration `2026_09_12_000032_create_governance_voting_profiles_table.php`.
   - Created `App\Domain\Governance\Models\GovernanceVotingProfile`.
   - Created `App\Domain\Governance\Services\GovernanceVotingProfileService`.
   - Formulated candidate D1 rules:
     - `quorum_mode`: `majority_floor_plus_one`
     - `quorum_formula`: `floor(N/2)+1`
     - `ordinary_threshold_formula`: `for > against of valid votes cast`
     - `unanimous_denominator_formula`: `assent from all entitled voters`
     - `recusal_policy`: `exclude_from_presence_and_tally_without_reducing_N`
     - `is_active`: `false` for unconfirmed candidate default. Live activation blocked without explicit governing document authority (`governing_document_reference`) and formal approval evidence (`approved_by_resolution_id` or `approved_at`).
2. **Explicit Membership & Electorate (`BoardMember`, `CommitteeMembership`, `BoardCommittee`)**:
   - Added `has_voting_seat` column to `board_members` and `committee_memberships`.
   - Updated `BoardMember::canVote()`:
     - Observer is always non-voting (`canVote() === false`).
     - Expired terms (`term_end < today()`) or unstarted terms (`term_start > today()`) cannot vote (`canVote() === false`).
     - Treasurer has member voting entitlement (`canVote() === true` when active).
     - Secretary voting depends on explicit voting appointment (`has_voting_seat === true`). Administrative secretaries without a voting seat cannot vote (`canVote() === false`).
     - Added `scopeEligibleVoters` on `BoardMember`.
   - Updated `CommitteeMembership::canVote()` and `BoardCommittee::votingMemberships()`:
     - Committee resolutions restrict electorate strictly to active, appointed voting committee members whose terms are active.
3. **Voting Quorum & Electorate Resolution (`VotingService::calculateQuorum`)**:
   - Resolves eligible voter IDs ($N$) dynamically based on committee or full board context.
   - When $N=0$: required = 0, `met = false`. Zero eligible voters NEVER satisfies quorum.
   - When $N > 0$: required = `floor(N/2) + 1`.
     - Examples: N=0 -> 0 (met: false); N=1 -> 1 (met: true with 1 vote, false with 0); N=4 -> 3; N=5 -> 3.
   - Participating count: recused members who withdrew from voting are strictly excluded from presence and participation count; distinct voter IDs prevent any double counting.
4. **Permissions & Seeding (`GovernancePermissionsSeeder`)**:
   - Added `treasurer` role handling with full voting rights and budget/spend management capabilities (`governance.budgets.create`, `governance.budgets.submit`, `governance.spend.request`).
   - Reconciled canonical budget keys (`governance.budgets.view`, `governance.budgets.create`, `governance.budgets.submit`, `governance.budgets.approve`).
   - Added candidate default profile creation.
5. **UI & Navigation (`GovernanceSettingController`, `resources/js/Pages/Governance/Settings/Index.tsx`)**:
   - Rendered "Governance Rules & Electorate (D1 Authority)" card.
   - Prominently displays StatusBadge: "Rules not confirmed — live voting unavailable" until activated with legal document authority.
   - Displays full Electorate Denominator Table with members, roles, voting seats, term status, and voting entitlement ($N$).
   - Displays exact formula explanation: $N$ entitled seats, `floor(N/2)+1` required quorum.
   - Allows chair/admin to update candidate values or activate with authority; non-admin members view read-only.
   - `ResolutionController::openVoting` traps `DomainException` to keep draft resolutions safe while alerting that live voting is blocked pending rule confirmation.

## 2. Verification Results
- **Command**:
  `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/VotingServiceTest.php tests/Feature/Governance/GovernanceBoardMemberAdminTest.php tests/Feature/Governance/GovernanceBoardMemberSelfServiceTest.php --compact`
  - Exit code: 0
  - Results: 17 passed (85 assertions), Duration: 341.00s
- **Command (Preference & Snapshot integrity)**:
  `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/BoardMemberPreferenceTest.php tests/Feature/Governance/ResolutionQuorumDecisionSnapshotTest.php --compact`
  - Exit code: 0
  - Results: 7 passed (41 assertions), Duration: 287.21s

## 3. Boundary & D1 Status
- Candidate profile implemented with verified D1 defaults.
- Actual constitutional authority, real committee appointments, and statutory service applicability remain recorded external gates (GOV-A27).
