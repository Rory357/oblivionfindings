# My Day agenda and private note improvements

Implemented 13 September 2026 following approval of `current-page-and-calendar-direction.md`.

## Result

- “What needs to happen today” uses a default Agenda and optional hourly Day presentation, with shared Site Calendar date, source and card styling. Both read the existing My Day task/dose stream. Due work stays above the schedule, future work has an Up next time, anytime work remains visible, assistance remains open, and completed outcomes stay collapsed.
- Add task, Add at a displayed time, and Add anytime task use the existing task dialog. The selected person and exact time are prefilled; an existing private draft takes precedence. Optional steps, canonical detail actions, person notes and care-plan shortcuts are retained.
- Shift notes save a private encrypted draft after typing pauses. Writes are serialized and version checked. New typing is retained during an in-flight save. A retry checks the saved draft first, recovers a write whose response was lost, and refuses to overwrite a different version from another window. Saving does not submit a handover.
- Each person's notes show Not started / Draft saved, with an explicit Did not support this person option. Contradictory notes and support status are rejected; selecting that option cannot silently erase entered notes. Read models retain each person's current privacy boundary.
- Before you finish is the first main card in My shift. It brings together notes by person, remaining work, assistance, handover status and this shift's time review. Older paperwork remains separately reviewable. Clock-out policy is unchanged.
- Timesheet summaries use authoritative paid minutes in readable hours/minutes. Planned time is distinguished while still clocked in.
- The no-roster panel contains View my roster. Attendance and roster status remain separate, including for Admin.
- My Calendar now reports medication, leave and task-source failures through availability metadata. The shared calendar flags incomplete loads, exposes retry and the last complete calendar-load time when known, and also reports failures in the separate upcoming/overdue summary. My Day retains its existing unavailable-data warning.

## Verification

- Focused UI tests: **64 passed across 18 files** (`evidence/agenda-vitest-final.txt`). Includes overnight/DST and simultaneous work, both calendar layouts, action callbacks, task/person/time prefills, draft recovery, stale note conflicts and uncertain-response recovery, and existing My Day/timesheet/finish-shift interactions.
- Backend: **52 distinct checks passed**: 51 on the first run (`evidence/agenda-pest.txt`), plus the corrected person-status test (`evidence/agenda-person-progress-retest.txt`). That new assertion originally assumed input order instead of the editor's alphabetical display order; it now asserts by person identity. Includes JSON draft revisions, stale writes, encryption, individual privacy, no automatic submission, existing three-person timesheet allocation, and task workflows.
- Targeted ESLint passed without errors or warnings. Targeted PHP formatting and `git diff --check` passed.
- The application build passed (`evidence/agenda-final-build.txt`). Generated routes/actions were copied and byte-checked for the build because other active work regenerates that tree. Output was built separately and copied to `public/build`, replacing the manifest after assets were present. The populated worker verification used app `app-BqQ6Qd5Y.js`, My Day `index-DwKx--Nc.js` and shared calendar `SiteCalendar-BhRoDJV_.js`. A subsequent concurrent local build produced `app-BpaKEcBd.js`, My Day `index-CMV6IzV9.js` and shared calendar `SiteCalendar-BL6-ibeY.js`; the normal page was reloaded and its manifest dependency graph was checked to retain the new agenda, autosave, support status and finish summary.
- Full repository TypeScript checking remains non-green due to existing/concurrent errors in IT and older test files, including `personal-entry-dialog.test.tsx`; no errors were reported in this change's implementation files (`evidence/agenda-types-final.txt`).
- Browser verification: normal local Admin My Day and shared My Calendar Day view load correctly. The synthetic worker's populated shift showed all three care-visible people, due work, future Up next, anytime/site work and both layouts. Filtering James retained whole-site work and the shift attention count. Add at 11:00 am prefilled James and the exact time; creating a task with a step retained them in the hourly list and canonical task detail. The note editor autosaved Casey's explicit unsupported status, separate James/Mere notes and a shared site note. Closing without pressing Save draft and reopening restored every section, while the summary displayed each saved status and Private draft — not sent yet. The summary opened the correct current-shift timesheet with readable planned time; existing financial roster eligibility was retained. No browser console errors were reported for this worker session.

## Scope and environment

This remains a single-organisation application using existing roles, permissions, approved sites, ownership and person privacy. No schema migration was added. No new attendance or medication workflow was introduced. My Calendar's separate planning feed and its broader event-duration semantics were not copied into My Day. Appointments remain outside this work stream until an authoritative worker-visible source is established.

Synthetic verification uses the existing loopback-only fixture and its separately named disposable database. Normal care, attendance, handover and timesheet records are not used for mutation testing. Unrelated concurrent IT/Governance and other work was preserved.

Cleanup completed: the loopback fixture stopped and a read-only schema check returned `owned_fixture_database_remaining: 0`. This run's temporary route copies and separate build output were removed. The normal `/my-day` tab and the original mockup remain available. The normal Admin account has no rostered shift, so its main panel correctly shows the no-roster state.
