# Security & Devices: Gemini handover (Tue 3 Nov → Thu 5 Nov 2026)

Stephan (3 Nov): "can gemini continue working on S&D i would like to give it alot to do and then thursday you can
continue again … so you audit first and resolve any issues and check the ui etc."

Gemini works on S&D until **Thursday 5 Nov**. Then Claude (MASTER) audits everything, fixes what's wrong, checks the
screens and decides what goes live.

**"Done" means:** it works, it's tested, and you looked at it in a browser, and your report says how you know.
"Built" and "should work" are not done. On 27 Oct a Gemini session reported S&D as finished when it wasn't:
- devices couldn't be pinged;
- the map was empty;
- a device API was invented (`/api/v1/network/devices/{ref}/configuration`);
- cameras were labelled "Live source verified" with no check.

Don't repeat any of that. An honest "not done, because …" is worth more than a wrong "done".

**Your first step:** copy this file into your branch as `docs/security-devices/HANDOVER-GEMINI-2026-11-03.md` and commit it.

---

## 1. Where you work

- **Folder:** `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\gemini-sd`
- **Branch:** `gemini/sd-continue`, made from `main` at `3eade3c34`. Everything you need from S&D is already on main and live on the test server.
- **The approved drawings:** `.board/index.html` in that folder (open it in a browser). The page boards are in `.board/b/Pg-SDR-*.html` and the pop-ups in `.board/b/Pop-SDR-*.html`. This is board v14, approved; each view has a caption with its id (e.g. `12i`, `15g`).
- **The S&D build notes:** `.board/notes/`.
  - `sd-build-slices.md`: every decision and what was left out, phase by phase.
  - `sd-merge-request*.md`: what went live.
  - `sd-v13-review.md`: the last review.
  - `before-go-live.md`: items for real data.
- **The goal and decisions:** `docs/security-devices/GOAL.md`.
- **Code:**
  - `app/Domain/SecurityDevices/**` (screens in `Screens/`, alerts in `Alerts/`, hubs in `Hubs/`);
  - `resources/js/pages/security-devices/**` and its components;
  - routes in `routes/security-devices.php`;
  - tests in `tests/Feature/SecurityDevices/**` and `tests/Unit/SecurityDevices/**`.
- `.board/` is ignored by git. Don't commit it.

### First-time setup (once)

Run these in PowerShell from the folder above:

```powershell
robocopy C:\Users\steph\Herd\oblivionfindings\vendor .\vendor /E /MT:16 /NFL /NDL /NJH /NJS /NP   # exit code under 8 = ok
Copy-Item C:\Users\steph\Herd\oblivionfindings\.env .\.env -Force
npm ci
& "C:\Users\steph\.config\herd\bin\php84\php.exe" artisan wayfinder:generate
```

Use a **copy** of vendor, never a junction or link. A link lets tests load the parent app, and deleting the folder
later would empty the parent's vendor. PHP is `C:\Users\steph\.config\herd\bin\php84\php.exe`.

---

## 2. Hard rules (no exceptions)

1. **Never push.** No `git push`, no pull requests, no merging, nothing to `main` and nothing to GitHub. Commit on `gemini/sd-continue` only. MASTER merges after Thursday's audit, with Stephan's OK. This overrides GEMINI.md's "push to main with Stephan's OK" for this job.
2. **Never touch the live test server**: oblivionfindings.com, SSH, its database and its files. Never run anything against it.
3. **Stay in your folder.** Never edit, check out or delete anything in other worktrees or the main checkout `C:\Users\steph\Herd\oblivionfindings`. Other sessions own them.
4. **No secrets** (passwords, keys, tokens, `.env` values) in code, commits, logs or your report.
5. **Never loosen safety, privacy or permissions.**
   - Safety alerts (panic, alarms, doors, care rules) never pause and never wait.
   - Every read is limited to the Sites the person may see.
   - A resident's name shows only to people who may open their record.
   - Lockdown and evacuation keep the second person's approval.
   - If something would need loosening, stop and write it under Questions in your report.
6. **No new permission keys** unless a task says so. If one is truly needed, it needs an `RbacSeeder` line, a grant migration (deploys skip seeders) and tests, and it goes in the report as a question.
7. **No invented device or vendor APIs.**
   - Use only calls already in the code, or ones in the vendor's official documentation (name the page in a comment).
   - Hardware the code hasn't been tested against stays labelled "Not tested on hardware".
   - Never write "verified", "tested" or "working" for something you didn't run.
8. **Don't change decisions Stephan already made** (they're in `sd-build-slices.md` and the drawings). If a drawing and the code disagree, follow the drawing and say so in the report. If the drawing can't be built truthfully, build the honest version and say why.
9. **Hide anything unbuilt.** No "coming soon", no dead buttons, no stub pages.
10. **Don't change test infrastructure.** That is `tests/TestCase.php`, `tests/Support/**`, `scripts/test/**`, `phpunit.xml` and `Pest.php`; it belongs to Rory. Don't edit tests outside S&D except to fix something you broke.
11. **Database:**
    - Migrations add only; no drops outside `down()`.
    - Foreign-key and index names must be at most 64 characters.
    - A model whose times are stored with microseconds needs `protected $dateFormat = 'Y-m-d H:i:s.u'`.
    - Laravel turns an empty string into `null`, so NOT NULL columns need defaults.

## 3. This PC (it's shared, and Stephan games on it)

- **Heavy commands only through the queue:** `bash ~/.claude/heavy-lock.sh "gemini-<label>" -- <command>`. That means full Pest, vitest, eslint `.`, tsc and the build.
  - Use 1–2 test processes, e.g. `TEST_PROCESSES=2 composer test:fast`.
  - tsc and the build need `NODE_OPTIONS=--max-old-space-size=8192`.
- **Quick checks while building** don't need the queue: eslint on the files you changed, `php -l`, or one test file (`vendor/bin/pest tests/Feature/SecurityDevices/XTest.php`).
- **Watch memory:** if free memory drops below 1.5 GB, stop your own test or build by its exact process ID. Never kill anything that isn't yours, never MySQL, never Herd.

## 4. Design rules (read `DESIGN.md` first; the main points)

- Semantic tokens only: no raw Tailwind colours, no hex in components.
- Reuse `resources/js/components/ui/*`, the S&D components and the pop-up kit (`components/popup/*`).
- `<StatusBadge>` for every status.
- **No coloured line on any box edge** (top, side, bottom or pop-up). ESLint rule `design/no-edge-accent-line`.
- **No bordered pills as filters or tab strips.** Filters are dropdowns, as in the built S&D History and Logbook tabs.
- Plain words. None of: evidence, observation, scope, confidence, telemetry, provenance. Say "how sure" instead of confidence.
- Full-width pages. A web app for desktop; no phone layouts.
- **Card padding:** `Surface` cards add no inner padding of their own. Anything you put straight inside one needs the 18px inner padding the Alerts page cards use (see task 1).
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
4. Fix what fails, re-running only those files. Known failures that also fail on `main` are listed in `.board/notes/sd-merge-request-r3.md`: compare, don't "fix" them.
5. **Look at every screen you touched in a browser**, in light and dark. Use a local site or a headless browser. Save screenshots under `.board/shots/` and name them in the report. If you can't serve your worktree, say so plainly in the report.
6. Write the report (section 7) and stop.

## 6. The tasks (priority order; each has an approved drawing or needs no new decision)

1. **Card padding (small, do first).**
   - The 4 cards: the Site › Logbook "Show" card; a switch's History "Show" card; a switch's Ports line under the "Ports" heading; and Overview › Activity's "… by kind" chart card.
   - Their contents sit flush against the card edge. Give them the same 18px inner padding the Alerts page cards use.
   - Add a line to `DESIGN.md`'s named anti-patterns: "content placed straight in a `Surface` without inner padding".
2. **15g: the 6:00 am morning summary and quiet hours for device alerts.** Approved board 15g; also referenced by 13d and 15p.
   - Non-urgent device and hub alerts raised during quiet hours wait and go out in the 6:00 am summary (in-app, plus email where the person has email on).
   - Safety alerts never wait.
   - The summary also lists hub update results (see 15p).
   - Use the existing alert engine, `DeviceAlertEngine`, and its reminder pattern.
   - Tests: an alert in quiet hours waits; a safety alert doesn't; the summary goes out once; quiet hours end.
3. **13d On call extras:** the "All handovers" page, the backup-person card and the week day-strip.
   - On-call shifts come from Rostering (`ItOnCall`); handovers are in `security_device_on_call_handovers`.
   - Read-only views. Keep the same permission as the On call tab.
4. **12i Logbook Export:** a day or a date range as PDF or CSV.
   - Export asks for a reason, kept with the person's name (audited), like the existing Reports exports.
   - Site-scoped, and the same privacy rules as the screen (residents and door names).
5. **12j "Place them":** devices nothing reports links for (e.g. radio sensors) can be placed on the Site map by hand.
   - This stores a reviewed manual link, needs `securityDevices.devices.update`, and is audited.
   - Taking one off the map works too.
6. **3f switch Settings extras:**
   - a "Tell IT when an update is ready" switch;
   - a "Looked after by" person;
   - the SSH user. It's shown and edited only by IT admins (the people who may use the terminal) and is never a password.
   - Store them per device, audit changes, and add tests.
7. **3d Ports extras:** the Columns menu, and the PoE budget **only if** the UniFi data already stored has it (`provider_observed_state`). If it isn't stored, leave the PoE budget out and say so. Don't invent a feed.
8. **E6 sensors (boards 5–6, views 7b and 7c):**
   - the "Nights" view: last night's log and minutes out of bed over 7 nights, from data already stored;
   - the 4-check readiness ring;
   - the 7c meters.
   - Also: **configurable alert limits per sensor**, replacing the fixed `READING_LIMITS` with stored rules as drawn (e.g. 16 °C). Who can change them follows the existing sensor-rule permission, and changes are audited. Care sensors linked to a resident keep their privacy rules.
9. **A local switch for screen checks:** a seeder or artisan command that adds one demo UniFi switch with ports **to your local database only**. That way the switch's Ports, History and Settings tabs can be looked at. Never run it on the test server, and never add it to `DatabaseSeeder`.
10. **If time is left: tests for what has none.** Look for S&D screens or services with no test, and add Site-scope and privacy tests (another Site's data never shows; a resident's name stays hidden). List them in the report.

**Not yours** (don't start these):
- hardware walks;
- anything on oblivionfindings.com;
- Obli's "use the computer to fix things";
- the alarm "Keep unlocked";
- adding a tracker by IMEI (pop-up 76);
- alarm tiles in "Connect a service";
- who armed on the keypad (unless the drivers already store it);
- dropping the device-group tables;
- anything that needs a new drawing.

If something you need isn't drawn, write it under Questions with a short description of what you'd draw. Don't build it.

## 7. The report (write it last): `docs/security-devices/GEMINI-REPORT-2026-11-05.md`

For **each** task:
- **Status:** Done, Partly done or Not done.
- What changed, and the files.
- The tests you added (file and test names) and the result.
- The screenshots you took (paths).
- Anything you couldn't do, and why.

Then:
- **Gate:** each step's command and its summary line (passed, failed and which), plus the failures that also fail on `main`.
- **Questions for Stephan:** decisions you didn't make.
- **Things you noticed but didn't touch.**
- **Commits:** `git log --oneline 3eade3c34..HEAD`.

Leave everything committed on `gemini/sd-continue`. Leave the worktree clean (`git status` shows nothing). Don't push.
