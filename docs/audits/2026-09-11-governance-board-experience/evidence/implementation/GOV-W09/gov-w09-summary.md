# GOV-W09 Implementation Summary — Recompose Board Overview and Permission-Aware Navigation

## 1. Overview & Purpose
GOV-W09 recomposes the Governance board overview (`/governance/dashboard`) and navigation structure into the canonical L1 layout contract defined in the Governance Audit:
- **Design Purity**: Replaced deprecated `PageHero` with standard `PageHeader` (variant="index", icon={Landmark}, title="Board overview", reporting period / capture subline).
- **One Full-Scope Meter Row**: 4 instrument blocks linking directly to their respective canonical surfaces:
  1. *My pending work* (`/governance/my-work`)
  2. *Overdue board actions* (`/governance/actions?status=overdue`)
  3. *Risks above appetite* (`/governance/risks`)
  4. *Obligations overdue* (`/governance/compliance?status=overdue`)
- **Action Hierarchy**:
  - Scoped search: `PageHeaderSearch` for Governance records.
  - Primary button: Context-aware—renders "Prepare meeting" for assigned secretary/chair and meeting managers; defaults to "My work (N)" with live count badge for ordinary board members.
  - Glass secondary: Refresh with spinning state.
- **Rule 1 Connected Tab Rail**: `PageHeaderRail` with real routes for `Overview`, `My work`, `Calendar`, and `Decisions`, plus built-in Find palette.
- **L1 Body Content Order**:
  - **Row 1**: Next authorised meeting (`MeetingReadinessPanel`) + "Needs my attention" (`MyNextActionsRail` with `showFallback={false}`, up to 5 items, View all -> `/governance/my-work`).
  - **Row 2**: "Board priorities" table (`PriorityOverviewPanel`, at most 8 rows, with scoped View all links).
  - **Row 3**: "What changed" (`GovernanceTimeline`, shown if permitted).
  - **Row 4**: Compact assurance groups (`FinancialGovernancePanel`, `RiskComplianceWatchlist`).
  - **Row 5**: Board pack panel (`BoardPackPanel` when pack exists).
  - **Row 6**: Progressively disclosed operational context (`OperationalSignalsAccordion`).
  - **Row 7**: Recently completed items (`RecentlyCompletedRail`).
  - **Removed Redundancies**:
    - Eliminated duplicate `KpiBand` in body (metrics now live in `PageHeader` meters).
    - Eliminated duplicate `MODULE_TILES` grid (all registers live in navigation and Find palette).
    - Eliminated bespoke mini-calendar (`GovernanceCalendar` in body; Calendar is accessed via header rail and sidebar).
- **Permission-Aware Navigation**:
  - `canDoGovernance` updated in `resources/js/lib/governance-permissions.ts` to fail closed (`return false`) on missing or unknown keys.
  - `resources/js/components/app-sidebar.tsx` updated to group Governance into 4 capability-checked groups:
    1. *Overview & My work* (`/governance/dashboard`, `/governance/my-work`, `/governance/meetings/calendar`)
    2. *Meetings & decisions* (Meetings, Board Packs, CEO Reports, Resolutions, Action Items, Board Evaluations)
    3. *Oversight* (Risk Register, Compliance, Operational Compliance, Clinical Governance, Te Tiriti, Budgets, Spend Approvals, Strategic Plan, Performance, Roadmap, Policies, Documents, Interests Register)
    4. *Board administration* (Board Members, Audit Log, Governance Settings)

## 2. Changed Files
- `resources/js/lib/governance-permissions.ts`
- `resources/js/components/app-sidebar.tsx`
- `resources/js/components/governance/MyNextActionsRail.tsx`
- `resources/js/components/governance/PriorityOverviewPanel.tsx`
- `resources/js/pages/Governance/Dashboard.tsx`
- `resources/js/pages/Governance/Cockpit/CockpitLayout.tsx`

## 3. Verification Results
- `npm.cmd run types`: Clean (exit code 0).
- `tests/Feature/Governance/GovernanceDashboardTest.php`: 8 passed, 48 assertions (exit code 0).
