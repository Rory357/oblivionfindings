# Browser acceptance — 14 September 2026 (Claude continuation session)

Browser: Claude desktop Browser pane (real navigation, real controls, results
inspected after the mutation). Actor: **Demo Admin** (`admin@demo.test`, role
`admin`), signed in by the user. Viewport: the pane's responsive desktop size
(≈1173–1996 px wide) plus a 375 × 812 emulation for the narrow check. No
secrets entered or captured. Build: main `13616f050` (deployed) / parent
checkout at the same commit for `.test`, local assets rebuilt at 17:3x NZ.

Two hosts were used deliberately:

- **https://oblivionfindings.com** (deployed): confirms the webhook deploy of
  this week's work, but its Demo Admin has **no approved Site** (the Log
  ticket wizard says "No approved Site is available for this request"), so
  ticket-level journeys are impossible for that account there.
- **https://oblivionfindings.test** (local Herd, protected working DB): Demo
  Admin has approved Site 1, so ticket-level journeys ran here. The one
  pending local migration (`create_it_ticket_macros`) was applied additively;
  nothing was reset.

## Results

| Area | Host | Result |
|---|---|---|
| Setup → Automation tab renders three sections | both | **Pass** — reply templates, macros, recurring plans with empty states and actions |
| W18 reply template create (name, body with placeholder chip, Create) | .com, .test | **Pass** — toast "Reply template created."; card shows body, Public badge, "Owned by Demo Admin · v1", Edit/Archive |
| W18 macro builder (assign-to-me + insert reply template) | .com, .test | **Pass** — toast "Macro created."; card lists both ordered actions |
| W18 macro apply on a real ticket (IT-000008) | .test | **Pass** — preview dialog named both changes ("Assign the ticket to you (Demo Admin).", "Send a public reply from template …"); after Apply: toast, assignee = Demo Admin, public reply posted with placeholders rendered ("Kia ora Support, Demo Admin has picked up IT-000008."), email notification queued, first-response clock stamped with the earlier breach retained |
| W18 routing dry run | .com | **Pass** — dialog explains scope, generated time, "There are no open tickets to evaluate" for an actor with no scoped tickets (truthful, no mutation) |
| W20 recurrence plan builder | .com | **Fixed during acceptance** — Site picker was empty for an organisation-wide admin without an approved-Site profile; fix `13616f050` deployed; picker then listed four Sites. Plan creation itself hit a **503 Service Unavailable** on submit at 17:42 (deploy maintenance window suspected); retried below |
| W20 recurrence plan create (weekly Monday 09:00, Site chosen, Create) | .com, .test | **Pass** on both once `.com` left its maintenance window — toast "Recurrence plan created."; card shows "Active · Every Monday at 09:00 · next Mon 21 Sept, 9:00 am · Owned by Demo Admin · 0 runs" with Edit/Pause/Retire |
| W20 recurrence plan Pause | .test | **Pass** — toast "Recurrence plan paused."; badge Paused; action becomes Resume |
| W21/W22 Knowledge library → full document page | .com | **Pass** — `/it/knowledge/9` renders the Event Horizon header (Published badge, meters), Document/Diagrams/Files/Related/Ownership tabs, Revisions & review, Edit document, Assist |
| W25 Knowledge Assist dialog | .com | **Pass** — three documentation capabilities "Not enabled", reason line, permission-checked citations, publication rule, untrusted-content note |
| W25 ticket Assist dialog (Summary / Reply draft / Triage) | .test | **Pass** — Summary lists permitted sources; Reply draft is audience-first with Insert/Replace/Discard disabled; Triage shows current category/priority/status/next action vs "No suggestion" |
| W15 catalogue editor "Approval routing" section | .com | **Pass** — section present in the live editor (build with the W15 slice is deployed) |
| Narrow width 375 × 812: ticket detail + Service Desk | .test | **Pass** — title wraps fully, meters stack two-up, tabs scroll, no overlapping SLA/age text; hero meters stack with truthful "SLA watchdog: Unverified" |
| Direct-object denial | .test | **Pass** — `/it/tickets/26` (Site outside approval) returns 404 |

## Console / network observations

- `.com`: one recurring console line "An unknown error occurred when fetching
  the script." — no failed asset or XHR in the network log (all 200/304);
  attributed to a service-worker/PWA fetch, pre-existing, not from this work.
- `.com`: two 409 responses on sidebar prefetches (`/it/reports`,
  `/it/major-incidents`) — Inertia's version-mismatch reply after a deploy
  landed mid-session; the app reloads itself, no user-visible defect.
- `.test`: a transient `ViteManifestNotFoundException` (500) with CSP
  inline-script errors from its error page, caused by loading the site while
  `npm run build` was clearing `public/build`. Gone after the build finished.

## Not covered here

Requester-role journeys, keyboard-only/zoom/reduced-motion passes, the W23/W24
vault reveal flows (real secrets must not be captured) and the specialised
Problem/Change/Major-incident lifecycles remain browser-owed per the W27
review.
