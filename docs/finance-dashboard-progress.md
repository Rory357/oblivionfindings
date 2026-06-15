# Finance Dashboard — build progress (loop state)
- [x] Phase 0  Audit -> docs/finance-dashboard-audit-findings.md
- [ ] A1 Hero (primary gradient, period control, Site+Funding filters)
- [ ] A2 Quick-action wizard modals
- [ ] A3 Finance hubs quick-links bar
- [ ] A4 KPI cards (8)
- [ ] A5 Donut row (3)
- [ ] A6 Charts row (area + bar, tokens)
- [ ] A7 Tables row + recent journals
- [ ] A8 Footer
- [ ] A9 Needs-attention strip
- [ ] B  Period-aware real data + new metrics
- [ ] C  P0 correctness (3.1, 3.2, 4.1, 2.1/2.2)
- [ ] D  Funding pipeline (1.1, 1.2, 1.3) + utilisation donut/table
- [ ] E  Net-pay disbursement (4.2) + IRD payday filing (4.3)
- [ ] F  Budgets (5.1, 5.2) + finance calendar (5.3)
notes:
- 2026-06-15 first run: created progress file; design drop lives in parent repo (.design-drops gitignored). Starting Phase 0 audit.
- 2026-06-15 Phase 0 DONE: wrote docs/finance-dashboard-audit-findings.md. Verified gap claims vs live code — concurrent Finance loop already fixed 3.2 (markPaid posts journal), 3.3, 4.1 (lock generates payslips), partial 4.2 (net-pay journal done, direct-credit file missing). Dashboard AR KPI (DashboardAggregatorService:73) still reads orphaned App\Models\Invoice — the one live 3.1 break. Phase C is small; substantive new work = D (funding/remittance), E (payday filing + DC file), F (budgets + calendar surfacing). Next: A1 hero.
