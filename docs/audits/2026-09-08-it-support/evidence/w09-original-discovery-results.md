# W09 preserved-original discovery — 10 September 2026

Status: implemented; focused automated checks and Build30 original-discovery browser journeys verified. A separate merge-review scroll defect remains under correction. W09/F16/A08/E08 remain partial. This adds query-free original-record discovery to the previously implemented merge command and UI.

The survivor lists directly merged original records using the canonical permission-scoped ticket query, stable descending-ID pagination (10 per page), and original routes. Each original can expose its own predecessors, so multiple merge stages remain traversable without copying or reparenting historical evidence. Staff work/approval/context links require current per-record canWork; participants receive original/history links only. The service reloads the actor's approval and current parent before projecting records. Hidden intermediate originals cannot be traversed through this list.

The desktop page uses a compact shared Collapsible disclosure with explicit original/history/tasks/approvals/linked-record links. Empty later pages retain a Previous action when access or records changed. No fake totals or private task counts are shown. The existing closed-original receipt recovery is retained.

Changed: ItTicketMergeService.php, ItTicketController.php, tests/Feature/It/ItTicketMergeTest.php, components/it/ticket-merged-originals.tsx and its test, pages/it/tickets/show.tsx. No migration, design-guide, provider or working-database change.

Actual verification:
- Isolated discovery run12564, token it_bba5d2da30674a28: 4 passed/30 assertions, Pest0/wrapper0. Matching diagnostics and w09-original-discovery-backend.txt show all14 postflight guards and exact schema absence.
- UI discovery plus merge dialog/recovery regressions:16 passed/3files/5.30s0 in w09-original-discovery-ui.txt.
- Full types67052 exit0; scoped ESLint0; Pint0; diff-check0; protected DESIGN.md/design_styles diff empty.
- Build30 handle63741 exited0 in4m6s. Real desktop restricted/requester journeys verified fresh survivor discovery, completed original evidence, nested5→4→1 history and public-only requester controls. See w09-browser-build30-results.md and its persisted reconciliation. Exact disposable runtime cleanup and independent absence check passed.

Next: finish the corrected merge-review scrolling recheck and the remaining approval/file/watcher/recovery/race cases. Build30 verified fresh survivor -> original task/history -> nested originals and requester privacy; actual approval-bearing merge remains unverified. Earlier stale tabs had connection-refused pages and a generated data:error URL-policy block; a fresh authorized loopback tab worked without a bypass. Never resize. Remaining W09 relationship/duplicate/known-error/article criteria stay open.
