# Targeted browser observations — second return review
Date: 13 September 2026, roughly 12:02–12:15 Pacific/Auckland. Reviewer: Astra.
Host: guarded loopback http://127.0.0.1:8781; synthetic review database and non-admin personas.
Current script src observed in DOM: /build-gov-audit2-20260913/assets/app-DPNGbP_E.js.
All actions used the in-app browser controls. Dark appearance was already active. No real identities or communications used.

## Ordinary member at 1366×768
- Login reached My Day. Other assigned work visibly included **REVIEW PRIVATE assigned action**. A separate HTTP/service probe denies the underlying action; this is discovery leakage, not direct access.
- Governance navigation exposed **21** links: Overview, My work, Calendar, Meetings, Board Packs, CEO Reports, Resolutions, Action Items, Board Evaluations, Risk Register, Compliance, Clinical Governance, Te Tiriti, Budgets, Spend Approvals, Strategic Plan, Performance, Policies, Documents, Interests Register, Audit Log.
- Overview: next-meeting readiness, separate My Work tab and Needs my attention exist. Member work still links to separate registers/details. Screenshot visually inspected; image rendered inline by the tool, not exported to a local PNG.
- Financial card displayed UNKNOWN and em dashes; Sites over budget displayed UNKNOWN/Unavailable. Positive correction for the inspected unavailable source.
- Overview showed 41 overdue board actions. Clicking opened /governance/actions?status=overdue and **Action Register (41)** with overdue rows. The browser's Filter by Status control displayed **All Statuses**, with no Overdue option. Backend count/selection now works, but visible filter state does not represent it.
- Priorities: **58 open, 13 critical, 42 overdue**, but tab **All 15** and footer **View all 15 priorities**. Footer points at /governance/actions although priorities contain risks and compliance.
- Action register due cells rendered raw ISO dates such as 2026-07-24T00:00:00.000000Z.
- Meeting /governance/meetings/1: new PageHeader present. Clicking **View resolutions** changed URL fragment to #tab-resolutions; read-only DOM confirmed **Agenda aria-selected=true**, Resolutions false. Selecting the real Resolutions tab worked, adding ?tab=resolutions.
- Paper **View →** opened /governance/resolutions/1 as a standalone page. It now has a positive Back link /governance/meetings/1?tab=resolutions&paper=1. This does not embed pack/paper/conflict/voting together.
- The fixture paper was draft. No live vote was cast in the browser; mutation cases were confined to rolled-back service/HTTP probes.

## Chair at 1920×1080
- Signed out and logged in as the synthetic board_chair (no admin override).
- Opened the same paper. **Edit Paper** opened shared **Edit Decision Paper** wizard with existing title/context/meeting prefilled and five steps.
- Changed only the local unsaved title, pressed Escape: **Discard Unsaved Changes?** appeared.
- Clicked Discard Changes. Original paper title remained, dialog closed, focus returned to **Edit Paper**.
- Browser DOM measured viewport 1920×1080 and document width 1905: no page-wide horizontal overflow on this inspected paper screen. Theme dark.
- Final page error/warning log was empty. This is not a whole-session zero-error assertion.

## Limits and cleanup
No full saved-edit/attachment round trip, success-pane submission, calendar outage injection, all calendar views, light mode, zoom, reduced motion, PDF rendering, accessibility sweep or simultaneous writers were exercised in this browser pass. A25 and full A05 remain Not tested. Source/service/test checks have separate evidence.
The temporary viewport was reset and the audit-created tab closed. Only disposable local fixture records were viewed; the unsaved title was discarded.

