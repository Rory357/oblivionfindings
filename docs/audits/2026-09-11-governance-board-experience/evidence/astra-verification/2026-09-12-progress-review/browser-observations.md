# Independent browser observations — 12 September 2026
Observer: Astra. Normal authentication and fresh production assets; synthetic records only. These are observed notes, not generated screenshots or an automated test report.

- Preview: http://127.0.0.1:8777, isolated database oblivion_gov_review_runtime_20260912_25964. Runtime fixture and router are beside this file. Observed DOM script URL: /gov-review-20260912/assets/app-B8MwfjgM.js.
- Real board_member login at 1366×768, name Review Alex Morgan. My Day displayed REVIEW PRIVATE assigned action even though the parent executive meeting denied access. No admin permissions were used.
- Governance navigation → Overview loaded. Twelve risks displayed as twelve. Personal header said My work (1). Entering zzzz-no-match into header search and pressing Enter left results and route unchanged. No results/empty/search destination appeared.
- Board priorities showed 58 open, 13 critical, 42 overdue; sampled tabs All 15. “View all 15 priorities” navigated to /governance/my-work.
- My work showed All work 3, Act 2, Know 1. It contained the private child action, the legitimate last-ranked action, and the upcoming normal meeting. Duplicate-name other-owner action was absent. Policy source warning said the list may be incomplete; runtime schema/query probe explains the local column errors.
- Governance meetings calendar used shared Sites controls and appearance. Month loaded one ordinary meeting on 17 September at 10am, matching the meeting detail time. The private executive meeting was absent. Month/Week/Day/Agenda/Timeline tabs were exercised; Timeline's final loaded state included the meeting and Up next rail. Rapid tab observations include intermediate Loading states and do not prove complete range-race correctness.
- During calendar loading the page also showed On track, zero counts and empty guidance. These transient states must not be treated as verified healthy information. Backend mapping probe separately reproduces implemented decisions being labeled overdue.
- Calendar New entry as an ordinary member navigated to /governance/meetings/create, a full-page schedule form. No meeting was submitted. Source hardcodes canCreate=true, so capability-aware control remains required.
- At 1366×768 overview DOM width was 1351; at 1920×1080 calendar DOM width was 1905. No body horizontal overflow was measured on these two observations. This is not a module-wide overflow/accessibility pass.
- Switched through normal logout/login to board_chair (not admin), Review Chair, at 1920×1080. /governance/meetings/1 loaded agenda and private agenda item visible to its chair.
- Opened the actual Workflow tab: unrecorded attendance, pack generation pending, distribution blocked, minutes approved separate from signed. Source of odd combined lifecycle state is the deliberately synthetic fixture; do not treat its future meeting plus approved minutes as a production lifecycle reproduction.
- Submitted synthetic Attending RSVP. Final UI: Your RSVP: Attending, Recorded on 12 Sept, 09:46 pm; Update RSVP had focus. Quorum stayed 0/3, correctly separating RSVP from presence.
- Chair → Resolutions → New Resolution opened the old short dialog with type, title, description, voting deadline and linked meeting. There was no operative motion/options/implications/evidence wizard. The new Create.tsx is not connected through this normal entry point.
- Browser-visible breadcrumbs start at Governance; overview has no Home-rooted strip. This corroborates the source design findings.

Screenshots were inspected in the browser tool transcript for overview and calendar. No screenshot file is claimed in this folder. Full light/dark, zoom, reduced-motion, all-persona browser flows, PDF/queue tests and representative-user timings were not completed in this interim audit. HTTP smoke results for all six roles are in extra-probe-results.json; do not label them browser journeys.

