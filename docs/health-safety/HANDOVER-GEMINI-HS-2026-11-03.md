# Health & Safety: Gemini handover (Tue 3 Nov → Thu 5 Nov 2026)

Stephan (3 Nov) asked for a Gemini handover for H&S as well as S&D. Gemini works on H&S until **Thursday 5 Nov**. Then
Claude (MASTER) audits everything, fixes what's wrong, checks the screens and decides what goes live.

Everything Stephan approved for H&S is already built and live: the G1–G4 UI rounds, #13–#17 and the end-to-end fixes.
This job is the **polish, accessibility and test-health work that's left**. None of it needs a new decision. Where a
decision *is* needed, you write it down as a question and don't build it.

**"Done" means:** it works, it's tested, and you looked at it in a browser, and your report says how you know. On
27 Oct a Gemini session reported work as finished that wasn't: it invented an API and labelled things "verified" with
no check. Don't repeat that. An honest "not done, because …" is worth more than a wrong "done".

**Your first step:** copy this file into your branch as `docs/health-safety/HANDOVER-GEMINI-HS-2026-11-03.md` and commit it.

---

## 1. Where you work

- **Folder:** `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\gemini-hs`
- **Branch:** `gemini/hs-continue`, made from `main` at `fda7c3273`.
- **A second Gemini job (Security & Devices) runs in parallel** in `.claude\worktrees\gemini-sd`. Never touch it. The PC's test queue is shared with it (section 3).
- **H&S notes and approved drawings** are in `C:\Users\steph\AppData\Local\Claude-Data\claude_pzs8sxrjxfjjc\side-session-notes\local_2bd3983b-06b3-43c4-b807-e4fa625e9a94\`. Read them; don't edit them. You may copy them into `.board/` in your folder, which git ignores.
  - `hs-boards-2026-11-01\index.html` (plus g2, g3 and g4.html) is the approved G1–G4 boards.
  - `hs-boards-13-15\hs-13-15.html` is the approved #13/#15 panels.
  - `hs-e2e-report.md` is the end-to-end walk findings; the P3 list is the source for tasks 1–6.
  - `hs-e2e-fixes-MR.md` is what was already fixed.
  - `hs-final-summary-2026-11-02.md` is the final state.
  - `hs-remaining-work-2026-10-31.md` holds older items and Stephan's answers.
  - `hs-g3-g4-decisions-answered.md` holds Stephan's 27 answers.
  - `hs-uiux-problems-and-candid-review.md` is the earlier UI review.
- **Code:**
  - `app/Services/HealthSafety/**`, `app/Http/Controllers/HealthSafety/**`, `app/Models/HealthSafety/**` (and incident code under `app/Services/Incidents/**`);
  - `resources/js/pages/health-safety/**`, `resources/js/components/health-safety/**` and `resources/js/components/incidents/**`;
  - routes in `routes/health-safety.php` and `routes/incidents.php`;
  - tests in `tests/Feature/HealthSafety/**` and `tests/Feature/Incidents/**`.

### First-time setup (once)

Run these in PowerShell from your folder:

```powershell
robocopy C:\Users\steph\Herd\oblivionfindings\vendor .\vendor /E /MT:16 /NFL /NDL /NJH /NJS /NP   # exit code under 8 = ok
Copy-Item C:\Users\steph\Herd\oblivionfindings\.env .\.env -Force
npm ci
& "C:\Users\steph\.config\herd\bin\php84\php.exe" artisan wayfinder:generate
```

Use a **copy** of vendor, never a junction or link. A link lets tests load the parent app, and deleting the folder
later would empty the parent's vendor. PHP is `C:\Users\steph\.config\herd\bin\php84\php.exe`.

**Gate note:** in a worktree with a built `public/build/manifest.json`, two restraint tests get 409 in Pest
(`RestraintEntryParityTest`, `RestraintReportRecoveryHttpTest`). Move the manifest aside while Pest runs and put it back afterwards.

---

## 2. Hard rules (no exceptions)

1. **Never push.** No `git push`, no pull requests, no merging, nothing to `main` and nothing to GitHub. Commit on `gemini/hs-continue` only. MASTER merges after Thursday's audit, with Stephan's OK. This overrides GEMINI.md's "push to main with Stephan's OK" for this job.
2. **Never touch the live test server**: oblivionfindings.com, SSH, its database and its files. Never run anything against it. Do all checks locally.
3. **Stay in your folder.** Never edit, check out or delete anything in other worktrees or the main checkout `C:\Users\steph\Herd\oblivionfindings`.
4. **No secrets** (passwords, keys, tokens, `.env` values) in code, commits, logs or your report.
5. **Never loosen safety, privacy or permissions.**
   - Statutory and WorkSafe checks stay hard gates.
   - Independence rules ("someone who didn't add, own or do the action checks it") stay.
   - Every read is limited to the Sites the person may see.
   - Restraint, safeguarding and HR-confidential records keep their stricter access.
   - Close-anyway exceptions keep their independent approval.
   - If something would need loosening, stop and write it under Questions.
6. **No new permission keys, no permission changes.** Keep `canDo()` checks, not `->can()`.
7. **No invented APIs, and no "verified", "tested" or "working" for something you didn't run.**
8. **Don't change decisions Stephan already made** (they're in `hs-g3-g4-decisions-answered.md`, `hs-remaining-work-2026-10-31.md` and the drawings). If a drawing and the code disagree, follow the drawing and say so in the report.
9. **Hide anything unbuilt.** No "coming soon", no dead buttons.
10. **Don't change test infrastructure.** That is `tests/TestCase.php`, `tests/Support/**`, `scripts/test/**`, `phpunit.xml` and `Pest.php`; it belongs to Rory. You *may* fix H&S's own test files when the test, not the code, is wrong, and you must say why.
11. **Database:**
    - Migrations add only; no drops outside `down()`.
    - Foreign-key and index names must be at most 64 characters.
    - A model whose times are stored with microseconds needs `protected $dateFormat = 'Y-m-d H:i:s.u'`.
    - Laravel turns an empty string into `null`, so NOT NULL columns need defaults.
    - Store times in UTC and show them in NZ time.

## 3. This PC (it's shared, Stephan games on it, and Gemini S&D runs too)

- **Heavy commands only through the queue:** `bash ~/.claude/heavy-lock.sh "gemini-hs-<label>" -- <command>`. That means full Pest, vitest, eslint `.`, tsc and the build. The queue runs one job at a time, so waiting is normal.
  - Use 1–2 test processes, e.g. `TEST_PROCESSES=2 composer test:fast`.
  - tsc and the build need `NODE_OPTIONS=--max-old-space-size=8192`.
- **Quick checks while building** don't need the queue: eslint on changed files, `php -l`, or one test file (`vendor/bin/pest tests/Feature/HealthSafety/XTest.php`).
- **Watch memory:** if free memory drops below 1.5 GB, stop your own test or build by its exact process ID. Never kill anything that isn't yours, never MySQL, never Herd.

## 4. Design rules (read `DESIGN.md` first; the main points)

- Semantic tokens only: no raw Tailwind colours, no hex in components.
- Reuse `components/ui/*`, the H&S components and the pop-up kit (`components/popup/*`, `PopupForm`, `PopupConfirm`).
- `<StatusBadge>` for every status.
- **No coloured line on any box edge.** ESLint `design/no-edge-accent-line`.
- **No bordered pills as filters or tab strips.**
- Plain words: no jargon such as "hard gates", "provenance" or "internal-governance blocker". Say what the person must do.
- Sentence case for titles; times and dates in NZ format, never raw `2026-11-02 09:30`.
- Full-width pages. Desktop web only. Frontline pop-ups keep 44px touch targets.
- Dark mode must work. Check every screen in light and dark.

## 5. How to work (the whole-job rule)

1. Build **all** the tasks below, in order. Commit each task separately with a clear message, ending with a `Co-Authored-By` line for yourself.
2. While building, quick checks only (section 3).
3. When everything is built, run **one** full gate through the queue, one step at a time:
   1. `TEST_PROCESSES=2 composer test:fast`
   2. `npx vitest run --maxWorkers=2`
   3. `npx eslint .`
   4. `npx tsc --noEmit`
   5. `npm run build`
4. Fix what fails, re-running only those files. Failures that also fail on `main` aren't yours: list them, don't "fix" them, except task 8.
5. **Look at every screen you touched in a local browser**, in light and dark, as the roles the task names. Save screenshots under `.board/shots/` and name them in the report. If you can't serve your worktree locally, say so plainly.
6. Write the report (section 7) and stop.

## 6. The tasks (priority order; P-numbers are from `hs-e2e-report.md`)

1. **P10, a revised action from the Actions tab.** When a safety review says "Revised action needed", its Actions tab offers only "Add corrective action". Offer the same "Add revised action" that Overview has, using the same pop-up and the same rules.
2. **P12, "The activity couldn't be loaded"** on a corrective action page (seen as admin, CA-2026-9013). Find out why the activity at that step fails to load and fix it (it isn't a 5xx). Add a test.
3. **P14, safety review titles in sentence case** ("Injury incident — Amelia Wilson", not "injury incident …"), wherever the title is built. Change the display only; don't rewrite stored data.
4. **P15, one line instead of four** in closure readiness: "Record the signed WorkSafe decision before…" is repeated. Show it once, with what it blocks.
5. **P18, report wizard accessibility and polish:**
   - incident-type tiles and the "Straight away" checkboxes get accessible names;
   - the whole row toggles, not only the 16px box;
   - the Follow-ups "Due" picker matches its neighbours;
   - the Review step shows dates in NZ format, not `2026-11-02 09:30`;
   - the client picker doesn't open upward over the tiles.
   - Add vitest coverage for the accessible names.
6. **P8, one way to close a safety review.** The link opens an inline panel and the button a 480 pop-up. Check the approved G-boards for the close UI and make both entry points open that one. If the boards don't settle it, use the pop-up kit version and say so.
7. **Retire `IncidentJourneyPresenter`.** It's unmounted. MASTER decided on 31 Oct (item #18) to retire it with its skipped tests. Remove it cleanly and show that nothing referenced it (grep in the report).
8. **H&S test health** (tests that fail on main; fix the **code** when it's wrong, the **test** only when the test is wrong, and never loosen):
   - `DismissedAlertScopeTest`: the lone-worker cases (6) fail on main. Find out whether the lone-worker scope or the test is wrong.
   - `HsLegacyStorageCompatibilityTest`: UniqueConstraintViolation.
   - `PpeCommandConcurrencyTest` and `RestraintOperationalAlertBridgeConcurrencyTest`: flaky races. Make them deterministic inside the test files.
   - Check first with `git log` and a single-file run whether each is still failing. Some may already be fixed; say so.
9. **Item #19, the logged race limits** (Stephan 31 Oct: "Fix them", tighten only):
   - a permission withdrawn mid-request on the client page or the investigation export;
   - the same-UUID restraint race.
   - Check with `git log` and the code whether they're already fixed. If not, fix them so the later request is refused safely, and add tests.
10. **An accessibility pass over the main H&S pages** (Overview, Safety reviews, Actions, the registers, Restraints, Report something). Run axe in a local browser and fix what you can **without changing the approved design**: labels, focus order, contrast within the tokens, keyboard use of pickers and menus. List the before and after counts.
11. **A local end-to-end walk** with a headless browser on your local database, as admin, manager, team lead, support worker, compliance lead and H&S officer:
    - report a near miss, an injury, first aid and a hazard;
    - review the report;
    - add, finish and check an action.
    - Fix small things you find. Write bigger ones in the report.
12. **Investigate only, change nothing:** the Control Room job `CheckControlRoomSlaBreaches` holds alert rows for about 8 s. That's why H&S pages sometimes show "This page is busy". Measure it locally and write a short proposal: what holds the lock, for how long, and how to shorten it without changing what the job decides. Control Room alerts are safety-critical, so **don't change that code.**

**Questions to list, not build** (Stephan decides):
- the hazard reference formats differ (new `HAZ-2026-0001` vs older `HAZ-01024`);
- there's no way to remove a draft procedure;
- there's no archive for a private chemical exposure record;
- the PPE "Check for changes" button at the top of a brand-new record.

**Not yours:**
- anything on oblivionfindings.com, including closing the demo records there;
- new features or screens that aren't drawn;
- permission changes;
- Chat, which is Rory's;
- Security & Devices, which is the other Gemini job.

## 7. The report (write it last): `docs/health-safety/GEMINI-HS-REPORT-2026-11-05.md`

For **each** task:
- **Status:** Done, Partly done, Not done, or Already fixed on main.
- What changed, and the files.
- The tests you added or changed (file and test names) and the result. For a changed test, say why the test was wrong.
- The screenshots you took (paths).
- Anything you couldn't do, and why.

Then:
- **Gate:** each step's command and its summary line, plus the failures that also fail on `main`.
- **Questions for Stephan.**
- **Things you noticed but didn't touch.**
- **Commits:** `git log --oneline fda7c3273..HEAD`.

Leave everything committed on `gemini/hs-continue`. Leave the worktree clean. Don't push.
