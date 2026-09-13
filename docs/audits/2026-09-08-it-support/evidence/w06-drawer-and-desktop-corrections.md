# W05/W06 — desktop header, card and quick-peek corrections

Current checkpoint: header/card consumer corrections **Verified** in root Build5 (`app-lNwuFfLJ.js`). Drawer boundary correction **Implemented**, focused UI regression passed; current-assets drawer browser verification remains pending a later build. No mobile browser or layout branch is in scope; the user's desktop-only clarification supersedes mobile-specific plan checks.

## Confirmed desktop geometry and correction

Root's current-assets1440×900 `/it?list_view=cards&q=ITSupportW04&tab=tickets` evidence measured the header top4/bottom348.25/height344.25, with the IT views rail ending244.25:104px of purple remained below it. Further geometry established `.eh-header.scrollTop=104`, scrollHeight774, clientHeight344 and overflow hidden. The inner box was displaced exactly104px while its natural children298+46 correctly filled the header. This was internal scrolling caused by focus/scroll-into-view of overflow-clipped content, not an incorrect rail height.

`ItHero` now opts into `overflow-clip!` in both summary branches. It retains the approved decorative clipping and canonical header/rail structure while preventing a hidden scroll container. Shared PageHeader and CSS are unchanged. `ItTicketList` opts into normal wrapping and word breaking for its EntityCard h3 only, so long ticket titles retain meaningful distinctions. Shared card geometry, typography, native links, selection and menus remain unchanged. DESIGN.md and PAGE_HEADER/LIST guides were read and not edited. Both changed consumer files passed focused ESLint.

Root Build5 actual1440×900 acceptance: header overflow `clip`, scrollTop0; header bottom437.25 equals rail bottom437.25 exactly. Long Response clock ticket title has white-space normal/text-overflow clip and height37.5, visibly readable over two lines. Root inspected the screenshot, Cards/Table changes, canonical keyboard Open, native Ctrl/new-tab and browser Back retaining query/layout. No resize occurred.

## Drawer source defects confirmed

- `TicketDrawer` has no cancellation, epoch or current-record response check; an older request can replace a newer ticket.
- The inline `onClose` supplied by IT index recreates `fetchTicket`, causing its effect to clear data and unmount the composer on unrelated parent rerenders.
- Escape/backdrop/close and Open full page have no entered-work decision, and every fetch failure only toasts/closes. There is no inline network/session/access recovery.
- Shared Sheet already owns focus return and should be retained. The full page already keys Thread by ticket+actor; the old drawer does not.

`resources/js/hooks/use-it-ticket-peek.ts` now drives the drawer. It scopes every response to current actor/ticket, aborts old HTTP, rejects stale/wrong-record results, retains the mounted current conversation through refresh/network failure, and conceals content after401/419/403/404. Root added `viewer_user_id` to the canonical authorized show payload; the hook requires exact actor equality and conceals a changed-account response until normal authorized reload. Current internal/reply capability changes remount the composer, purging entered agent content before a public/read-only context can reuse it. No draft content or secret is placed in browser storage.

Root supplied `ThreadDraftState {dirty,busy}` through optional `onDraftStateChange`; it carries no draft content. The drawer now keeps callback references stable, offers explicit dirty close/handoff decisions, handles network/session/access errors inline with retry, uses the approved Skeleton and retains Sheet focus return. A native beforeunload guard and generic Inertia GET guard protect navigation; comment POST is not intercepted. Confirmed navigation bypasses its guard once. A busy departure explicitly states the submitted request may finish and that closing does not cancel/undo it. Full-page handoff currently offers Discard/Open or Cancel; persisted Resume handoff belongs to the shared W06 draft hook, and W07 composer commitment remains root-owned.

Actual tests: initial request helper9 passed2.05s; mandatory-viewer follow-up10 passed2.09s. Integrated drawer+request+SLA presentation24 passed3.27s, then26 passed3.34s after GET/actor recovery tests. Final current-capability purge group **27 passed,3 files,3.68s**, exit0. Focused ESLint exit0. Test files are `resources/js/hooks/use-it-ticket-peek.test.tsx`, `resources/js/components/it/__tests__/ticket-drawer.test.tsx` and existing `sla-evidence.test.tsx`. All HTTP is mocked; no database/provider calls. Full TypeScript checkpoint session96698 **exit0**, output `w06-drawer-typescript.txt` (started before the final string-key/capability test addition).

Next: source frozen for the coordinated next asset build, then root verifies desktop dirty Escape/backdrop/GET/full-page, keyboard focus, retry and actor/access loss using current assets. W06 as a whole remains incomplete until scoped persisted Resume/Discard and every package criterion is integrated and verified.
