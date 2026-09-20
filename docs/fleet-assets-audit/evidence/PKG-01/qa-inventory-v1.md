# PKG-01 v1 — preview QA inventory

Owner: DESIGNER ASTRA. Created 2026-09-19. Baseline e62b569. Synthetic preview only; not production acceptance.

Checks to execute through rendered controls:

1. Queue: populated table, cards and return; site/search/meter/tab filtering; search-empty and clear; owner/next action/source labels; kebab/right-click parity; row keyboard activation.
2. State harness: empty, loading, load failure/retry, direct-record denied concealment, stale refresh and save failure/retry. Counts unavailable rather than zero on failure. Harness controls are outside product semantics.
3. Report: required resource/title/observation validation; contextual asset locked; conditional duplicate option restricted to the same asset; evidence attach/remove; review; failed save retains entries; retry creates one result; linked report preserves existing work; separate report summary; escape → retain/discard draft → resume.
4. Check: required answer omissions/conditional evidence never Passed; governed sample answers; immutable submitted template/rules/answers/author/time; append-only correction; new retest does not rewrite original.
5. Work: triage/reason, owner handover pending/acknowledged; internal appointment vs provider confirmation/cancellation; evidence; repair completion retains hold; Finance stays unapproved/unposted.
6. Release: default safety/evidence/custody/policy blockers; explicit hypothetical eligible-source fixture only; reason required; independent release result/history; bookings remain and require fresh readiness checks.
7. Interfaces: minimal booking context, shared task owner/action, Finance handoff failure/retry with same source; no live requests or policy grants.
8. Accessibility: report focus trap, Escape guard, focus return, error focus, keyboard table/menu access, reduced-motion behavior; long content scrolls inside overlays.
9. Visual: 1440×1000 and 1280×900 desktop; desktop zoom; no page-wide horizontal overflow; header/filter/tab/menu/dialog visibility, source/readiness distinctions, screenshot provenance.

Intentional scope: full detailed repair-to-release interaction uses WO-0264; other synthetic queue records open accurate bounded summaries. No operational API/database, role enforcement, accounting correctness, concurrency or integration is certified by these checks.

Results are recorded separately in verification-v1.md after observation. No PASS is claimed by this inventory.
