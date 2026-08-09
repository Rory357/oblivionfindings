# Compliance → Governance Surfacing — PLAN (Claude Code)

> **Owner: Claude Code (backend / routing / nav), NOT Claude Design.** This is the answer to "does `/compliance` need a place in the governance module, and if not, plan to add + surface it." Pairs with `COMPLIANCE_DASHBOARD_REDESIGN_PROMPT.md` (the Claude Design front-end redesign). Keep the two `BACKEND_AUDIT.md` files aligned.

---

## 1. Finding (the audit answer)

There are **two different "compliance" things**, and only one is in governance:

- **`/governance/compliance`** — a governance **compliance obligations register** (full CRUD: `index`, `create`, `calendar`, `show`, `edit`, `store`, `update`, `complete`, `evidence.upload`, `notifiable-incident.store`). Already in the governance module + nav ("Risk & Compliance" group in `buildGovernanceSubPanelGroups`, `app-sidebar.tsx:1595`).
- **`/compliance`** — the **operational compliance dashboard** being redesigned (`ComplianceDashboardController` → `compliance/index`; gate `compliance.view`). It rolls up frontline/clinical exception signals: open incidents, CD discrepancies, MAR exceptions, break-glass, care-plan reviews due, audit events, Control Room alerts.

**The operational dashboard is NOT surfaced in governance.** It is only in the **Health & Safety** sub-panel ("Compliance & Risk" group, `app-sidebar.tsx:1294-1314`), and its route is **registered in `routes/medications.php`** (`medications.php:51`) — the wrong file. So: governance has *obligations* but no view of the org's *operational compliance posture* the board cares about.

**Recommendation:** keep the two surfaces (different audiences — ops/clinical vs board) but **surface the operational dashboard into governance for board assurance, share one metrics source, and clean up the routing.** (Full merge is an option — flagged in §4 — but not recommended.)

## 2. Work to do (Claude Code)

**2.1 Relocate the route (no URL change).** Move the `/compliance` route out of `routes/medications.php` into its own `routes/compliance.php` (require it from `web.php` near the other compliance modules, ~line 204-207). Keep path `/compliance`, name `compliance.index`, gate `compliance.view`. Confirm no `route('compliance.index')` / `/compliance` links break (sidebar `app-sidebar.tsx:1298`, the page's own drill-links).

**2.2 Extract a shared `ComplianceMetricsService`.** Lift the KPI/trend queries out of `ComplianceDashboardController::index()` into `app/Domain/Compliance/Services/ComplianceMetricsService.php` (incidents, CD discrepancies, MAR exceptions, break-glass, care-plan reviews due, audit events, Control Room open/critical/escalated + recent + trend). Add `overdueObligations` / `obligationsDueSoon` by reading the governance obligations the register uses. Both the dashboard **and** the governance dashboard card (2.3) consume this service → **one source of truth, no divergent numbers**.

**2.3 Add a governance dashboard assurance card.** In `App\Domain\Governance\Http\Controllers\DashboardController` (+ its Inertia page under `resources/js/pages/Governance/`), add an **"Operational Compliance"** card/widget summarising the `ComplianceMetricsService` headline numbers (open incidents, CD discrepancies, MAR exceptions, overdue obligations, control-room critical) with a **"View dashboard" → `/compliance`** link. Gate: `governance.view || compliance.view`. Match the governance dashboard's existing widget/hero idiom (`docs/GOVERNANCE_HERO_GUIDE.md`).

**2.4 Add the governance nav entry.** In `buildGovernanceSubPanelGroups` (`app-sidebar.tsx:1554`), add **"Operational Compliance" → `/compliance`** to the **"Risk & Compliance"** group (next to "Compliance" `/governance/compliance`, line 1595-1597). Gate `can?.governance?.view || can?.compliance?.view`. Label clearly so the two aren't confused: governance register = "Compliance"; operational roll-up = "Operational Compliance".

**2.5 Cross-link both ways.** On `/compliance`: a CTA/panel into `/governance/compliance` + `/governance/compliance/calendar` (Claude Design adds the UI — give it the routes). On `/governance/compliance` (`Governance/Compliance/Index.tsx`): a link to the operational dashboard `/compliance`.

**2.6 Permissions.** Confirm `compliance.view` is granted to governance/board roles (or have the card additionally accept `governance.view`). No new permission needed unless product wants a dedicated `governance.compliance-dashboard.view` — flag if so.

## 3. Acceptance criteria
- [ ] `/compliance` route lives in `routes/compliance.php` (not `medications.php`); URL + name unchanged; nothing broken.
- [ ] `ComplianceMetricsService` is the single source for both the dashboard and the governance card; numbers match exactly.
- [ ] Governance dashboard shows an "Operational Compliance" assurance card → `/compliance`, gated correctly.
- [ ] Governance sub-panel nav has "Operational Compliance" under "Risk & Compliance".
- [ ] Bidirectional cross-links between `/compliance` and `/governance/compliance` (+ calendar).
- [ ] Tests/lint/typecheck clean; numbers reconciled across dashboard ↔ governance card.

## 4. Flag for product decision
- **Two surfaces vs merge.** Recommended: keep two (ops dashboard + governance obligations register) sharing `ComplianceMetricsService`. Alternative: fold the operational roll-up into a tab of `/governance/compliance`. Decide before 2.3/2.4 if merging.
- **Naming.** "Compliance" (governance register) vs "Operational Compliance" (dashboard) — confirm final labels so the sidebar isn't ambiguous.
