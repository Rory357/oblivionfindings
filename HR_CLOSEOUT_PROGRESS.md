# HR Close-out Loop Ledger

> Executes the close-out after the HR Audit & Fix loop (merged to main `c2f6bbef`, HR pest 723/0):
> deploy verify → Decisions D-1..D-11 per the approved DECISION DEFAULTS → housekeeping → END.
> One slice per run: map → implement → gates → ledger → commit → stop.
> Row status: ⬜ not started · 🔶 partial/blocked · ✅ done.

**Decision defaults (approved by launching the loop):** D-1=surface-only aggregation · D-2=block admin/external-persona assignment for non-admin actors · D-3=audit user writes via existing mechanism · D-4=archive-not-delete sweep · D-5=lock-after-submit + addendum notes · D-6=SanitizesCsvOutput on payroll exports · D-7=CLOSE no action · D-8=read-only profile surfacing · D-9=WIRE read-only · D-10=CLOSE no action · D-11=CLOSE no action.

## Slices

| # | Slice | Status | Evidence |
|---|-------|--------|----------|
| C0 | Deploy smoke-check (oblivionfindings.com) | ✅ | Deploy of `c2f6bbef` HEALTHY (checked 2026-07-10, Chrome MCP as Demo Admin — session was live, no login needed). `/hr`→`/hr/my` renders fully (correct date, balances, policy nudges); `/hr/approvals/pending` renders the honest-empty inbox (F-78 area, "PENDING 0"); `/hr/cases` renders (SLA snapshot + HR-DEMO-CASE-001); the "New HR case" 4-step wizard opens cleanly (dismissed via navigation — Escape unverifiable due to a transient tool outage, not a site issue). Zero console errors; ~300 network requests ALL 200. No hotfix needed. |
| C1 | (D-6) Payroll CSV injection guard | ⬜ | |
| C2 | (D-2) Role-assignment guard on intake/rehire | ⬜ | |
| C3 | (D-3) User-write auditability | ⬜ | |
| C4 | (D-4) Archive-not-delete sweep | ⬜ | |
| C5 | (D-5) Exit-interview immutability | ⬜ | |
| C6 | (D-9) Case↔incident cross-link — wire read-only | ⬜ | |
| C7 | (D-8) Injury surfacing — read-only federation | ⬜ | |
| C8 | (D-1) Approvals inbox — surface real approvables | ⬜ | |
| C9 | (D-7/D-10/D-11) No-action closures | ⬜ | |
| C10 | Housekeeping + merge→main→push + END | ⬜ | |

**Work branch:** `claude/hr-closeout` (off main `c2f6bbef`). Commits stay here until C10's merge+push (C0 hotfix exception only). **Pest baseline: 723/0.** Vitest baseline: 8 fail/171 pass (my-day×4, app-sidebar×1, behaviour-abc-tab×2, resident-tracking×1).

## Run log

### Run 1 — C0 deploy smoke-check (2026-07-10)
Bootstrapped the loop: work branch `claude/hr-closeout` off main `c2f6bbef`; this ledger created. Browser-verified yesterday's deploy on oblivionfindings.com as Demo Admin (live session): `/hr/my` ✅, `/hr/approvals/pending` honest-empty ✅ (F-78 area), `/hr/cases` ✅, New-HR-case wizard opens ✅, 0 console errors, ~300 requests all 200. Deploy HEALTHY — no hotfix. Gates: N/A (no code touched; ledger only). Baseline stays 723/0.
