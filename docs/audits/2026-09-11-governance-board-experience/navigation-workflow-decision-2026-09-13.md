# Approved Governance navigation and workflow decision — 13 September 2026

**User decision:** ordinary board members should use one Governance home containing My Work and Next Meeting, opening papers and actions in context. The whole meeting journey—pack reading, papers, conflicts and voting—must remain together with the member's place preserved.

The user selected both options explicitly during the independent return audit. This is required implementation scope, not an optional future enhancement. It refines GOV-W07–W14, GOV-W22–W24 and GOV-A07–A14, GOV-A22–A26, A28; it does not remove any existing task or acceptance criterion. It complements Rory's current component rules without overriding them.

## Ordinary member experience

The primary sidebar entry is Governance. It opens one home with:
- A concise organisational assurance summary: material changes, risks, source coverage and what needs board attention.
- My Work: prioritized actionable obligations with accurate totals, due dates, why the task matters and a direct action. Completed work has a receipt/history reachable in context.
- Next Meeting: date/time, readiness, essential papers and a clear Prepare for meeting entry. If there is no next meeting, provide a meaningful empty state and access to other meetings.
- The existing shared Sites calendar reachable from that home using the same component, views and interactions. Do not invent a second calendar to support this navigation.

Do not expose the complete administrative register list as the ordinary member's main menu. Discovery of older meetings, decisions, policies and records must remain available through contextual links and a clearly secondary records/search entry. Existing authorized deep links must keep working. Hiding navigation must never be used as the security boundary.

My Work and board-wide priorities have different meanings and must be labelled accurately. A personal obligation, including a policy acknowledgement or non-meeting follow-up, should open the appropriate paper/action in the approved contextual UI and return to the same position. Complete totals must not be replaced with the number of cards currently displayed.

## One meeting workspace

Prepare for meeting opens a workspace for that exact meeting. Use Rory-approved tabs/panels/dialogs and shared components; do not build another UI framework.

The member can:
1. See the meeting purpose, date/time, readiness and ordered agenda.
2. Open and read the correct published pack/revision and supporting papers.
3. Read each decision's exact motion, alternatives, recommendation, costs, safety/service-user and risk/equity implications.
4. Declare a conflict and see its consequences for participation.
5. Cast an eligible vote when voting is open, receive the exact version/time receipt, and return to the next required item without finding the meeting again.
6. See the frozen result, relevant approved minutes and assigned follow-up actions as the lifecycle progresses.
7. Open an action, supply valid evidence/notes and complete it in context; the home/workspace counts and status refresh consistently.

Retain the active meeting, selected agenda item/paper/revision, tab, filter and appropriate reading/scroll position across panel close and ordinary back navigation. Support addressable deep links and safe reload. Opening or returning from a record must not silently switch to a newer revision that the member has not reviewed. Stale-content approval/voting must require the defined re-review.

The workspace orchestrates existing canonical records and services. Do not copy votes, documents, actions or financial records into a parallel meeting-only data model. Shared record views/components should work both in the workspace and through their canonical authorized URLs. Explicit permissions and record audiences apply to every item, aggregate and mutation.

## Chair, secretary and committee work

Provide role-appropriate management access for scheduling, agenda/pack preparation, minutes, rule settings, membership, financial oversight and other retained features. Keep common meeting work in the same meeting workspace. Secondary administration can use a management area; it must not clutter the ordinary member path or require broader permissions.

Create/edit entity flows must use the same prefilled WizardShell and the current review, errors, dirty-close and success patterns. Role-specific actions need explicit server authorization. Committee work uses actual appointments and assigned record audiences, not role-name or committee-type shortcuts.

## Required evidence before marking complete

- A normal member starts at Governance home, identifies what changed and what to do, opens the next meeting, reads the correct pack and paper, declares a conflict or votes where eligible, sees a receipt/result, opens a follow-up, and returns to the same workspace. No unrelated register navigation is required to finish that sequence.
- A non-meeting policy/action obligation is completed from My Work and the receipt/counts update without losing place.
- Record identity, revision and audience are unchanged by contextual opening. Private titles/counts/files never leak; a revoked member cannot keep cached material.
- Back/forward/reload/deep links and failed requests preserve or explicitly recover useful context. Dirty content is guarded, duplicate submissions cannot create duplicate decisions/receipts, and stale content cannot be approved.
- Chair/secretary/finance-committee journeys retain every required management feature and all existing canonical data boundaries.
- The shared Sites calendar still passes global/site/profile behavior, five views, source filters, dates, create seed, stale/error/retry and authorization-loss tests.
- Desktop checks at 1366×768 and 1920×1080, light/dark, keyboard/focus return, 200% zoom and reduced motion cover the final integrated journey.
- Show the final experience to actual representative board members for GOV-A28. Agent browsing alone cannot satisfy comprehension.

## Implementation sequence

First repair the authority/privacy/evidence contracts identified in astra-verification.md. In parallel with ordinary source work where safe, map the retained routes/components to the approved home/workspace so the final UI uses those contracts. Complete one real ordinary-member journey through the shared components, then finish chair/secretary/committee management and the remaining records. Preserve existing features and passing tests. Do not declare success after reducing the sidebar or replacing PageHero alone.

