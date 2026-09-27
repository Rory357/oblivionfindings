# OPS-PL01 — Main visual successor review

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-28.

**Result: no new blocking source finding in the four-file visual successor, based on source, independent tests and inspected owner-rendered evidence. T04 actual125% remains pending; no Main-write or integration slot.** Exact candidate `352ce7006098b227b945a47c4d55400c9aeff429`, parent `419e4c890c84a322ec031cd07cc06f61acf80d39`, tree `9d14682b6a9fc3572c6c5b0987b359c6c46533e8`, original base/current local Main `926b4981b0289da08a20baca0995117fb53e413e`.

## Verified authority and source boundary

Main independently read the actual user messages in **OF | OPS-PL01 People Locations | DESIGNER ASTRA | Mockup** (`01a0dfcf-097e-7850-af69-897f46d66cba`). Message `01a0e428-e812-70e0-bb30-83da3c47cc79` asked “does this look like the mockup?”; the owner identified condensed filters and spacing differences. Message `01a0e42c-519e-79e3-8d89-a3e63c8b4935` in turn `01a0e42c-4f83-7542-9918-9bb0110db3bb` then said “please continue”. This is a bounded correction under the approved v5, not authority to rewrite shared design rules or change product requirements. Prior local-only integration authority remains in the intake; this packet does not release it.

Exactly four application files change from the reviewed parent: the People Locations page, `record-picker.tsx`, `workspace.css`, and `history-panel.tsx`. The other11 changed paths are review evidence. No backend, permissions, privacy projection, shared branding or guide changes occur. T01/T02/T03/T05 closure remains applicable to unchanged source; no new backend/PDF execution is claimed.

The page now exposes population choices and current site/evidence/sort values, adds the current view to its breadcrumb, and keeps Cards/List in the People filter row. Searchable dropdowns use the shared header trigger through the existing RecordPicker; ordinary form pickers retain their existing variant. Current snapshot absence disables the filter fieldset. Combined cohorts call the existing readable-label function without changing selection or source authorization semantics.

History replaces its always-two-column layout with a single column below the desktop breakpoint; the report selector can shrink and outing buttons wrap. These are module-local reflow corrections. They do not introduce a separate mobile product scope or change the approved desktop/browser acceptance matrix. The header scope label uses the primary foreground token, without altering brand or status colours.

## Independent source and test evidence

[Main verifier](OPS-PL01-main-visual-verify.mjs) and [result](OPS-PL01-main-visual-verification.json) independently match all46 working/committed manifest paths and exact source-diff coverage, all41 frozen v5 files, the six protected references in both Main and candidate, the additional Page Header guide and Revision10 master. The candidate is clean. The source manifest reports digest `d0fd136de600f8746a9e950b77e0aaf30cf94dd1b7ddf7ec128ff8a777098d79`; its committed file SHA256 is `3ea9d9acbc74228d498a2c16cef0c49d8785bcdab70a19aa551fdf2a8eebe2df`. Master SHA256 remains `c4837ab675f9dffdb6a8597636f49d5761da114e6c155dc08e6bb8a209d63fd0`; Page Header guide SHA256 remains `33aeeb0e4434d3bb4d3023e3ce95ffee12e95ba1fe62c02f36601f8219827faa`.

Main ran the final exact source through **22 tests across four frontend suites**:18 tests in model/report-dialog/personal-location-privacy pass in5.10 seconds, and4 People Locations workspace tests pass in2.78 seconds. [First log](OPS-PL01-main-visual-ui.log), [workspace log](OPS-PL01-main-visual-workspace.log). The owner's16-test run used model/report/workspace, before final class-only refinements; Main's counts include the additional personal-location privacy suite and are not presented as the same run. No new tests mirroring CSS were added.

## Visual evidence and its limits

Main inspected frozen v5 `output/playwright/ops-pl01-v5/people-cards-1440.png`, the candidate's committed `visual-people-1440.png` and `visual-history-map-mobile.png`, and `VISUAL-ALIGNMENT.md`, `visual-alignment-browser.json` and `visual-alignment-checks.json` under the candidate's `docs/fleet-assets-audit/evidence/OPS-PL01-implementation/`.

The saved desktop composition restores visible filter choices, population and Cards/List controls, and readable scope text. Published shell/card geometry, theme and actual synthetic source records differ from the prototype; this is structure comparison, not pixel identity. The saved narrow History image visibly contains the map and report controls. Owner DOM evidence measures its map width improving1.6→299.8px at a390px viewport, with page scroll width410→378px and wrapped outing actions. Main inspected the final labelled cases and did not treat earlier pre-fix measurements or the explicitly excluded stale/timed-out captures as passes.

Search/Enter selection, sort/cohort results, List search, Escape focus, desktop1280/1440 and narrow390 interaction results are **owner-run browser evidence**. Main's independent navigation to the isolated8776 preview redirected to `/login`; no fresh Main authenticated browser pass is claimed. Main closed its temporary tab without another sign-in-code request or authentication change. The user's one-code synthetic sign-in approval was independently visible in the owner chat and remains part of that owner's completed verification history.

Owner full TypeScript predates final class-only changes; final scoped lint and production build follow them, with the existing large-chunk warning. The owner's declared final compiled asset check is retained, not a new Main production-build run. Prior backend/PDF and branding results remain historical evidence for unchanged source.

## Retained gate and custody

**T04 genuine125% browser zoom remains pending the existing manual setup question.** Responsive widths, OS scaling and baseline DPR do not satisfy it. No duplicate user question, browser-policy workaround, new worker or waiver is introduced. Same Designer retains correction/verification custody as Astra/xhigh. This review does not assert complete all-view visual or operating acceptance.

No Main application write, merge, commit/push, operating migration, permission grant, provider/device activation or telemetry repair is released. MAIN-TELEM-01 stays open. Settings38239d355 retains its own T08-02 gate and unchanged shared-Blade custody. Local Main remains926b4981; no fresh remote-publication claim is made.
