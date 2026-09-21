# PKG-01 provisional application browser review

Owner: DESIGNER ASTRA. Revision1. Observed 2026-09-20 02:45–02:58 UTC. **Changing initial build, not final UI acceptance.**

Designer actual turn `01a0bcb4-7f3b-7d83-80f1-296621dd7f9b`, latest turn-context timestamp2026-09-20T02:54:46.381Z, independently verified `gpt-6-astra / xhigh`. Sol remains the only application writer in2375. Frozen approved v8 is unchanged. Findings below extend initial-build PKG01-I06, correction count0.

## Host and scope

- Inspected `http://127.0.0.1:8765` in temporary IAB tab14 with the synthetic manager account. The pre-existing mockup tab13 was retained.
- Read-only process inspection independently resolved port8765 to PHP PID2056, PHP8.4 and server.php in `C:/Users/steph/.codex/worktrees/2375/oblivionfindings`. No public/hot file. Loaded app-Tr73ZAwR.js matched that worktree's build manifest; worker was rebuilding later changes, so this is a provisional bundle observation.
- Worker supplied guarded testing/browser-database evidence: exact `oblivion_findings_pkg01_2375_browser`, loopback MySQL, null queue, array mail, synthetic fixtures. Designer performed navigation and client-only draft edits; no report submission, upload, finance action, release or work-order mutation.
- Initial observed viewport1280×720; detail document client width1265, scrollWidth1295. Requested1440×1000 override did not reliably apply; later screenshot measured752px wide while worker was also inspecting. No1280×900 or1440×1000 acceptance is claimed. Designer reset the temporary viewport override, paused browser activity to avoid shared sizing interference and retained tab14 for later stable QA.

## Observed behavior

- Work detail uses the new hero, right-side action controls, collapsed notes, provider and evidence sections. Appointment modal uses shared calendar plus start/end clock/manual time controls. Entering minute61 gives validation and keeps the picker open; cancelling retained09:00AM.
- Report a problem exposes Resource, Problem, Evidence, Related work and Review. Selected Sep23 thenSep25 and confirmed the range; Continue on Problem remained disabled until title/observation were supplied. Evidence step shows the premium dropzone and PDF/JPG/PNG,10MB and10-file limits. Review retains the asset, synthetic draft text, estimate, evidence state and Coordinator routing statement. The draft was closed without submission.
- No error/warning entries were returned from the inspected tab log. This is not an upload, network-success, full accessibility or permission-boundary test.

## I06 gaps sent to the same worker

1. Queue still uses legacy Work Orders hero/KPIs, large priority chart and table rather than the approved queue/tabs hierarchy.
2. Notes are collapsed but Add note is hidden until expansion; it must remain available alongside the compact summary.
3. Next action is a tall always-expanded editor, pushing Progress far down. Restore the compact summary and modal edit pattern. The visible Progress options omit On hold.
4. Hero All Tasks and a duplicate below-hero Source links card point to generic `/tasks`, not the canonical item. Restore the approved contextual destination and remove the duplicate stack.
5. Provider appointment and selected report range/review show raw ISO-style date strings. Use consistent local display and timezone context.
6. Detail horizontally overflows at the observed desktop1280px width, clipping right-hand control/helper content. Both required desktop dimensions remain to verify on a stable bundle.
7. Related-work search for `mirror` with Synthetic Van2375 selected displayed no result although WO2 Synthetic mirror noise existed. No console error was observed. Needs worker reproduction and explicit empty/error handling; no unsupported assertion about its server cause.

Sol was sent these concrete findings, positive bounded observations, actual viewport limitation and request for stable final assets/acceptance evidence. No new worker, scope reduction, user-approval loop, competing application writes or formal correction attempt. Final stable-code tests, role/privacy/release/browser journeys, desktop fidelity, Main exact-code review and A4 live-policy gates remain open.
