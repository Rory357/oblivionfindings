# W07 public conversation overview and list projection

Date:2026-09-09. Status: **Implemented; focused UI, lint and scoped TypeScript verified; desktop browser acceptance pending the next coordinated asset build.** This is a bounded read-projection slice within W07. It supports E05 public/internal conversation and E04 navigation; it does not close whole W07, provider delivery, files/watchers, or those scenarios. Root owns composer, show/drawer, schema gates and browser. Setup remains frozen and was not edited in this slice.

## Implemented behavior

- The existing `awaiting_reply` URL/default/saved-filter key stays **Awaiting first reply** in the queue menu, header meter and overview lane. No historical first-response meaning is changed.
- The new **Awaiting IT** queue choice and restored-default eligibility require the canonical top-level `conversation_ready:true`. It remains available to permitted readers because the server scopes public conversation separately from private operational waiting views. Existing operational choices still require `can.manage`.
- Header `summary.tickets.awaiting_it` is shown only when its canonical readiness is true and its count is available. It links to `/it?tab=tickets&view=awaiting_it`; absent/null data does not produce a zero or a dead button. First-reply and current-responsibility counts have separate labels/destinations.
- Overview consumes canonical `awaiting_it_lane` only when ready. Its `created_age` is explicitly labelled **Ticket age**, never inferred reply-wait duration. Missing readiness or a missing lane produces a plain unavailable explanation. The first-reply lane remains available. Lane “View all” destinations use native Inertia links; row activation retains the existing quick-peek callback.
- Technician and requester card/table rows use one small public conversation presenter. It displays the recorded last-public speaker/time and canonical next-response state independently of ticket waiting status. Recorded `observer` is **Another participant**. Historical null speaker/party remains **Speaker not recorded / Next public reply not recorded**; a missing last-public pointer is **Last public reply not recorded**, not a claim that no reply exists. It never infers an old role from today's requester/assignee. Contradictory party/state values do not assert responsibility. False readiness conceals any stale projected evidence and shows **Public reply status unavailable**.
- No private waiting, internal comment text, provider result or fabricated duration is added to the public projection. Existing backend current-record scope remains authoritative. There is no migration, provider call, real communication, runtime activation or protected design-file change.

## Changed files

- `resources/js/components/it/ticket-conversation-summary.tsx`: typed canonical read DTO and shared evidence-only presentation.
- `resources/js/components/it/ticket-view-options.ts`: existing frontend view catalogue moved from the page, with readiness/permission eligibility and nullable-count labels. One catalogue remains.
- `it-overview.tsx`, `it-hero.tsx`, `it-ticket-list.tsx`, `my-tickets-list.tsx`: existing approved header/list/card components consume the canonical projection.
- `resources/js/pages/it/index.tsx`: read DTO, queue-choice/default eligibility and list readiness wiring. `it-wizards.tsx`: additive `TicketRow.conversation` type only; its creation/dialog bodies were not changed.
- `resources/js/components/it/__tests__/ticket-conversation-list.test.tsx`:17 new focused cases. Existing requester-list, SLA presentation and shared entity-interaction tests remain in the regression batch.

## Actual verification

- `w07-conversation-list-tests.txt`: **30 tests across four files passed3.76s**, actual exit0. This includes readiness/null counts, separate current/first-reply URLs/counts, restricted row rendering, Cards/Table behavior, observer/unknown/settled responsibility, retained native destinations, priority filtering and ticket-age semantics.
- `w07-conversation-list-eslint.txt`: eight-file focused ESLint, actual exit0.
- `w07-conversation-list-types.txt`: scoped strict TypeScript, actual exit0, using `w07-conversation-list-types.tsconfig.json` (index/imported DTO graph and the focused tests). One earlier full application read-only TypeScript pass also exited0 before the final test-only assertions.
- Early test attempts exposed fixture wording (`Waiting · Vendor` is the existing label) and the approved card's two links to the same destination; assertions were corrected without changing those existing UI contracts. Final result is the30-pass batch above.

Reproduce the focused UI batch with `node_modules/.bin/vitest.cmd run resources/js/components/it/__tests__/ticket-conversation-list.test.tsx resources/js/components/it/my-tickets-list.test.tsx resources/js/components/it/__tests__/sla-evidence.test.tsx resources/js/components/lists/entity-interactions.test.tsx`. Tests use local component fixtures, not provider calls or browser acceptance substitutes.

## Precise resumption

Source is frozen and root has been notified. No asset build was run by this agent. After root's coordinated current build, verify at the approved desktop viewport: new and historical ticket rows, permitted reader/requester roles, independent vendor-waiting and public-response state, current/first-reply header links and queue choices, overview priority narrowing, keyboard/native navigation and Back. Confirm the served bundle/build and schema readiness before claiming browser evidence. Composer/delivery/files/watchers and the rest of W07 remain with their existing owners.
