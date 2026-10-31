# Security & Devices: hand-over to Gemini

Written 26 Oct 2026 (NZ time) for Gemini Argon, which takes over from the Claude
session "Device management and security delivery". Stephan's Claude usage resets
on Thursday, so this file has to work without any Claude tools. Everything you
need is either here or in files this one names.

**Who's who.** Stephan is the product owner and the only person who approves
merges, pushes to `main`, and safety, privacy or permission decisions. "Rory's
rules" are the design rules in `DESIGN.md`. "Codex" is another AI agent that
built most of this work from 13 to 20 Oct, then ran out of credits. Claude took
it over on 22 Oct.

---

## 1. Start here (do these in order)

The working copy is
`C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\agitated-wozniak-ab58fb`.
It is a git worktree (a second checkout that shares one `.git` with the main
repo) on branch `claude/device-management-security-sol61-8c0c00`. It already has
its own `vendor`, `node_modules`, `.env` and generated Wayfinder route files.
Those are real folders, not links. Work there.

1. **Check it's the state this file describes.**
   ```powershell
   cd C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\agitated-wozniak-ab58fb
   git status
   ```
   You should see "You have unmerged paths", with 2 conflicted files:
   `app-sidebar.test.ts` and
   `today-retirement.test.tsx`. That is a stale merge of an older `main`
   (`031c0fca9`). The Claude desktop app's "Auto-fix" feature started it by
   itself around 24 Oct. It holds nobody's work: there are no unstaged or
   untracked changes besides the merge.

2. **Throw away the stale merge.**
   ```powershell
   git merge --abort
   git status            # clean, on claude/device-management-security-sol61-8c0c00
   git log --oneline -2  # db2c17b17 wip(...camera page trails...), then 0e9c0348d
   ```
   If `git status` shows anything else (other modified or untracked files),
   stop and ask Stephan. Don't discard it.

3. **Pick up this hand-over commit.** It was pushed to GitHub on top of
   `0e9c0348d`, but the local branch still ends at the unpushed WIP commit
   `db2c17b17`. Replay that one local commit on top of GitHub's branch:
   ```powershell
   git fetch origin
   git rebase origin/claude/device-management-security-sol61-8c0c00
   git log --oneline -3  # WIP, then "docs(security-devices): hand-over pack for Gemini", then 0e9c0348d
   ```
   This only rewrites the one commit that was never pushed, so later pushes
   stay fast-forward. Never rebase or rewrite commits that are already on
   `origin`.

4. **Read, in this order:** this file, `AGENTS.md` (single-organisation rule),
   `CLAUDE.md` (read `DESIGN.md` before any UI change), then
   `docs/native-endpoint-management/AUDIT-2026-10-22.md` (the takeover audit
   and gap list). Read the others in section 10 only when a task needs them.

5. **Before your first heavy command** (tests, type-check, build), read
   section 6. This PC has crashed several times from parallel test runs.

6. Then go to section 3, step A1.

---

## 2. Goal and current state

### What the programme is

Security & Devices is one workspace for a NZ supported-living provider: network
and equipment visibility, device management, CCTV, alarms, access control,
care sensors, people and vehicle tracking, native Windows remote support (RMM),
the staff helper "Obli", and incident reconstruction with Health & Safety. It
links into the existing modules instead of copying them. Stephan's original ask
to Claude (22 Oct), about the two Codex threads: "take over all the work and
complete it please". The full
plan is `docs/native-endpoint-management/RESUME-PLAN-SOL61-2026-10-11.md`
(packages 0–8).

### Where everything is

| Thing | Where |
| --- | --- |
| Working copy | `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\agitated-wozniak-ab58fb` |
| Branch | `claude/device-management-security-sol61-8c0c00` (based on `main` at `fb038cb59`, 22 Oct) |
| Pull request | https://github.com/Rory357/oblivionfindings/pull/23 (open, not merged; 260 files, +55,734 / −8,677) |
| Codex's work, saved as-is | branch `claude/sd-codex-snapshot-20261022` (`5a56bd7ff`): a snapshot of Codex's uncommitted 13–20 Oct work. Reference only. |
| Codex's original branch | `codex/native-endpoint-management` (`78a1fd45f`). Its checkout folder `~/.codex/worktrees/native-endpoint-management` was emptied on 23 Oct (see section 9). |
| Plan and decision docs | `docs/native-endpoint-management/` and `docs/security-devices-audit-2026-10-07/` |
| Main repo checkout | `C:\Users\steph\Herd\oblivionfindings` on `main`. It holds about 230 uncommitted files from other agents. Don't touch it. |
| Live test server | https://oblivionfindings.com (section 8) |

### Done (on GitHub, in PR #23 at `0e9c0348d`)

- Codex's 13–20 Oct work replayed onto `main` (one conflict in
  `routes/console.php`, both schedule lines kept).
- Four independent read-only reviews, then fixes:
  - **Safety and privacy:** device lists name a client only to people allowed
    to view that client (others see "Assigned client"). Client trackers can be
    assigned with the client's latest valid tracking consent, and the
    assignment records which consent it used.
  - **Permissions:** six approved-script permission keys are registered by
    migration `2026_10_22_090000_register_endpoint_script_permissions.php` with
    **no role grants**, and listed in `RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY`
    so admin doesn't get them by default.
  - **Approved scripts (execution stays OFF):** Remote support no longer locks
    rows on every page load; catalogue lock clashes return a retryable 503
    instead of a 500; the scripts page encrypts its browser history.
  - **Page bugs:** Milesight 13 h time-zone error, Discovery token vanishing,
    UniFi quiet hours computed in UTC, expiry dates, confirmations, error text.
  - **Design pass** on every S&D page: sentence case, words instead of slugs,
    12-hour times, "Add …" buttons, one name per place, no hard-coded product
    name, the shared date-range picker, header and button fixes. The sidebar no
    longer lights "Estate overview" on every S&D page.
  - **Tests:** Go script tests run from committed fixtures (15 were skipping
    silently); two Linux-CI breakages fixed; stale guard tests repointed;
    new `DeviceListClientPrivacyTest` and two consent tests.
- Local checks before the push (22–23 Oct), from PR #23's description:
  - Pest, in three S&D/IT/architecture groups: 9 failures found and fixed. The
    fixed files re-ran 163/164, the last one was fixed in `0e9c0348d`, and its
    re-run passed 45/45.
  - Full `tsc`: 0 errors. Go: all packages pass.
  - Vitest: 4,677 passed. The 4 failures are in 3 files that fail on `main` too.

### In progress (local only, not pushed)

Commit `db2c17b17` "wip(security-devices): camera page trails and Overview test
fix (tests not run yet)". Stephan's pause arrived before its checks ran.
- The Hikvision camera setup, recorder information and recording-reference pages
  now use the trail Home › Security & Devices › All devices › camera › page,
  like the other camera pages. Trail tests added.
- `today-retirement.test.tsx`: support workers have an Overview now. `main` has
  since made the same fix, so take `main`'s version when merging.

### Blocked or waiting

- **PR #23 has merge conflicts with today's `main`**, so GitHub runs no CI on
  it. Conflicts (checked 26 Oct against `main` `4e0590098`): `routes/console.php`,
  `resources/js/components/app-sidebar.test.ts`, and (with the WIP commit)
  `resources/js/components/today-retirement.test.tsx`. All three are mechanical;
  see step A2.
- **Its last CI run (22–23 Oct) was red, mostly from `main`.** `main`'s own CI
  has been red since about 9 Oct and is still red on `4e0590098`. A separate PR,
  https://github.com/Rory357/oblivionfindings/pull/24 (open, not merged), fixes
  part of it: Company Hub fixtures, the eMAR gate list, a seeder that made
  duplicate clients, and the governance login. Until #24 is on `main`, the
  browser (e2e) jobs can't even seed their data.
- **S&D's own e2e specs** (the `visual (it-security-desktop-1280/1440)` jobs)
  can't be checked until #24 lands. Known stale:
  `tests/e2e/operations-workspaces-acceptance.spec.ts` expects a Discovery
  "Limitations" tab that no longer exists.
- **Nothing in packages 2–8 has started under Claude.** Hardware and vendor
  acceptance waits for the first client site (Stephan, 11 Oct: no clients or
  live cameras yet).

---

## 3. Next steps, in order

### Phase A: get PR #23 mergeable (Stephan already approved pushing to this PR branch)

**A1. Finish the WIP commit's checks** (one heavy command at a time, section 6):
```powershell
$C = "resources/js/pages/security-devices/cctv"
npx vitest run $C/hikvision-page.test.tsx $C/hikvision-recorder.test.tsx $C/recording-pages.test.tsx $C/live-page.test.tsx $C/source-page.test.tsx resources/js/components/today-retirement.test.tsx --maxWorkers=2
npx eslint $C/_hikvision-page.tsx $C/_hikvision-recorder-page.tsx $C/recording-references.tsx $C/recording-reference.tsx $C/hikvision-page.test.tsx $C/hikvision-recorder.test.tsx $C/recording-pages.test.tsx resources/js/components/today-retirement.test.tsx --max-warnings=0
```
The full type-check runs once, after the merge in A2. Fix anything that
fails. Then give the WIP commit a normal message (allowed because it was never
pushed):
`git commit --amend -m "fix(security-devices): camera setup, recorder and recording pages follow the All devices trail"`.

**A2. Merge today's `main` into the branch** (merge, not rebase):
```powershell
git fetch origin
git merge origin/main
```
Resolve:
- `routes/console.php`: **keep both sides.** `main` added recovery schedules
  (procedure reviews, drills, lone worker, first aid, restraints, worker
  participation). The branch adds one line:
  `app(Schedule::class)->command('endpoints:purge-script-outputs')->everyMinute()->withoutOverlapping();`
  with its comment. Then run `php -l routes/console.php`.
- `resources/js/components/today-retirement.test.tsx`: **take `main`'s
  version.** Both sides made the same fix with different comments. While
  merging `origin/main` into this branch, "theirs" means `main`, so
  `git checkout --theirs resources/js/components/today-retirement.test.tsx`
  does it. Then check the file has no conflict markers.
- `resources/js/components/app-sidebar.test.ts`: `main` rewrote the Workforce /
  rostering part. **Take `main`'s Workforce section as it is.** `main` already
  added the `'Agent downloads'` label the branch also added, so keep one copy.
  Keep the branch's whole
  `describe('app sidebar Security & Devices navigation', …)` block at the end
  of the file.

Then check that the merge is only S&D on top of `main`:
`git diff --stat origin/main HEAD` should list S&D, IT remote-support, endpoint
and test files, not other modules' code. Also confirm
`database/seeders/RbacSeeder.php` still lists the six script keys in
`RESTRICTED_INDEPENDENT_AUTHORITY` after the auto-merge.

Run, one at a time: Vitest on `app-sidebar.test.ts` and
`today-retirement.test.tsx`; the full type-check
(`$env:NODE_OPTIONS="--max-old-space-size=8192"; npx tsc --noEmit -p tsconfig.json`);
the S&D Pest groups (section 6); ESLint on the files you touched. Then
`git add` the three resolved files by path and `git commit --no-edit`.

**A3. Push** (fast-forward only, never `--force`):
```powershell
git push origin claude/device-management-security-sol61-8c0c00
```
Stephan's approval covers pushing to this PR branch ("push … and open a PR.
Don't merge"; "fix any CI failures. Still don't merge"). His 23 Oct pause is
lifted by him handing this file to you. Merging to `main` is still his alone.

**A4. Read the CI result** (`gh pr checks 23 --repo Rory357/oblivionfindings`).
For each red check, compare with the same check on `main`'s latest run
(`gh run list --repo Rory357/oblivionfindings --branch main --limit 4`):
- Fails the same way on `main` → not this PR's problem. List it, don't fix it
  here (PR #24 or the owning module owns it).
- Fails only on PR #23 → fix it on this branch.

**A5. Report to Stephan** (section 11) and ask the Phase B questions.

### Phase B: Stephan's decisions (ask, don't decide; see section 5)

Merge order of #24 and #23, the e2e specs once #24 is in, design-kit tokens
(K1), the script policy editor, turning off Claude's Auto-fix, installing Go.

### Phase C: the rest of the programme (only after Stephan picks what's next)

From `RESUME-PLAN-SOL61-2026-10-11.md`, each a bounded package with its own exit
test:
- **Script execution gap list** (`AUDIT-2026-10-22.md`: server items 1–11,
  Windows agent items 1–16). Execution must stay switched off until every item
  is closed and Stephan says to switch it on.
- **Package 2**, incident reconstruction with H&S. H&S owns incidents. H&S is
  now on `main` (PR #28, 26 Oct). Agree the split with Stephan first.
- **Package 3**, alarms and response (Ajax, UniFi; internal, external or
  combined monitoring).
- **Package 4**, UniFi access to HR attendance and My Day.
- **Package 5**, care equipment and tracking (Milesight, Queclink).
- **Package 6 remainder:** script executor, installed screen view and control,
  other technician tools, Intune conflicts (Intune stays read-only).
- **Package 7**, Obli attachments, voice, offline drafts, notices; signed
  installers.
- **Package 8**, release verification. Vendor and hardware acceptance needs
  real client sites. Never label synthetic tests as vendor acceptance.

---

## 4. Decisions Stephan has already made (his words where recorded)

- 22 Oct 8:29 pm NZ, taking over: "can you please take over all the work and
  complete it please", then "can you also audit for gaps and edge cases. also
  tell me in a short paragraph what this all is going to do".
- 22 Oct 8:45 pm NZ: he asked Claude to "use computer use to allow the admin
  windows pop ups after 10 pm". Claude declined, because that is a security
  decision and nothing needs admin rights. Never click Windows admin (UAC)
  prompts for him.
- 22 Oct 9:44 pm NZ: "also please improve frontend i have started with artifact
  to get consistency … this will become our standard only improve please".
- 23 Oct 11:18 am NZ: "Yes — once the guard test re-run and the type-check
  pass, push claude/device-management-security-sol61-8c0c00 and open a PR.
  Don't merge."
- 23 Oct 12:25 pm NZ: "Yes — turn on Auto-fix for PR #23 and fix any CI
  failures. Still don't merge."
- 23 Oct 1:34 pm NZ, about the design-kit viewer (a Claude artifact): "set
  asside the artifacts for now just get everything you think is really good i
  think i am confusing all the sesions with this artififact". So: no design-kit
  or viewer work. Follow `DESIGN.md` in the repo.
- 23 Oct 3:48 pm NZ, passed on by his coordinator session: "please can you
  pause all work and only keep the rederall workflow running." S&D has been
  paused since. Handing you this file restarts it.
- 23 Oct, recorded by the main-CI session (not word for word): leave the 10
  failing HR/eMAR/rostering e2e journeys to their module owners. Updating stale
  screenshot baselines means approving the current look, so that's his call.
- 7 and 11 Oct, recorded by Codex in `docs/native-endpoint-management/OBLI-DECISIONS.md`:
  Windows-only computer agent and tray helper; one installer (Computer support,
  Site monitoring or Both); Obli uses the "02 — Obli orbit" design; attended
  remote sessions need the person to click Allow; session recording off by
  default; AI keys stay on the server; no clients or cameras yet, so build and
  test synthetically, but never call it field-accepted. Read the doc for the
  full list.
- Standing rules from other work, which apply here too:
  - 22 Oct: "i cant go through everything i am relying on you to do a good job
    please". Check your own work against `DESIGN.md` and earlier pages; ask him
    only real decisions.
  - 1 Oct: "i want improvements. if it [improves] things then yes. if not then
    no. Looking for consistency and better UI." This never covers loosening
    safety.
  - 20 Oct: "remember those logos should not be hard coded because the clients
    will need to add their own logo's? also their colours". The brand colour
    comes only from `--primary`; logos from Settings › Branding.
  - 23 Oct: "If free memory drops below 3 GB, stop running test jobs yourself by
    exact PID (newest or largest first)". On 26 Oct he lowered the limits
    ("Everything at 2 GB"): start heavy jobs only with at least 2 GB free, and
    stop test jobs below 1.5 GB.
- 26 Oct 5:47 pm & 5:59 pm NZ, Phase B & C decisions (Gemini takeover):
  - "all aprroved", confirmed via choices:
    - Script policy editor: "Hide the per-computer script policy editor UI until
      script execution ships".
    - Go 1.27: user-space portable Go 1.27 approved (no Windows admin/UAC prompt)
      when needed for `endpoint-agent/` tests.
    - Phase C order: "Build all Phase C packages in bounded order, starting with
      Package 3a (Auvik/PRTG-style network monitoring: thresholds, alert routing,
      maintenance windows, history, port actions, and config backup/diff)".
- 26 Oct 6:01 pm NZ, merging PR #24 and PR #23 to `main`:
  "if you think we should merge to main please do, merge and push".

---

## 5. Open decisions: what NOT to decide alone

Ask Stephan each as one short question with clear options, and record his
answer (his words, with the date) in this file's section 4 when you get it.

1. **Merging PR #23 to `main`.** His alone. Never merge it yourself.
2. **PR #24 (main CI fixes):** whether and when to merge it, and the 8 stale
   screenshot baselines it reports. Don't fix #24's items inside #23.
3. **K1, design tokens:** the "Slate" colour tokens in `resources/css/app.css`
   and shared components for the design kit's blocks. These are app-wide, and
   he set the design kit aside on 23 Oct. Don't start them.
4. **The script policy editor** is a full settings screen for scripts that
   can't run yet. That's borderline under the "hide unbuilt actions" rule.
   Ask whether to hide it until execution ships.
5. **Switching script execution on.** Never, until the whole gap list is closed
   and he says so. `EndpointScriptPolicy::available()` must stay `false`.
6. **Granting the six script permission keys** to any role after deploy
   (Settings › Roles). By design nobody has them. Only he grants them.
7. **Turning off Claude's Auto-fix on PR #23.** It runs in the Claude desktop
   app for the paused Claude session. While it's on, it can start a merge of
   `main` inside your working copy by itself, and it wakes the Claude session,
   which uses his Claude allowance. Recommend he turns it off (PR bar in that
   Claude session).
8. **Installing Go.** The endpoint agent needs Go 1.27 (`endpoint-agent/go.mod`).
   The portable Go that Codex used was deleted with its checkout, and CI doesn't
   run Go. Installing software needs his OK. Until then, say plainly that Go
   tests were last green on 22 Oct and haven't run since.
9. **Package order** in Phase C, and anything touching H&S incidents
   (package 2).
10. **Anything that loosens safety, privacy or permissions,** or changes who can
    see a client's data, location or consent. Never do it to make a test pass.
    Fix the test, or ask.
11. **The local development database.** Migrating or seeding the shared local
    database, or changing the Herd setup, is his call.
12. **Codex threads:** keep them paused. Don't open, resume or message them.

---

## 6. Running things on this PC (Windows 11, Herd)

### The heavy-command rule (most important)

The PC has 31 GB of RAM and has crashed several times when tests ran in
parallel. One Pest run, a full `tsc`, ESLint on everything, Vitest, a Vite build
or an `npm`/`composer` install each count as **heavy**. Rules:

- **One heavy command at a time on the whole machine,** including other agents'
  jobs. Claude sessions queue theirs through a script, `~/.claude/heavy-lock.sh`.
  Don't use it: it is currently paused for everything but named Claude lanes,
  so your job would wait forever. Do the same check by hand instead:
  ```powershell
  # free RAM in MB: start only if >= 2048
  (Get-Counter '\Memory\Available MBytes').CounterSamples[0].CookedValue
  # a Claude session holds the heavy slot if this prints True: wait
  Test-Path "$HOME\.claude\heavy-cmd.lock\owner"
  # heavy jobs already running: wait if any are listed
  Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='node.exe'" |
    Where-Object { $_.CommandLine -match 'pest|artisan test|tsc|vitest|vite build|eslint' } |
    Select-Object ProcessId, CommandLine
  ```
- Run one Pest process per group of files, not one per file, and never with
  `--parallel`.
- If free RAM drops below 1.5 GB during your run, stop your own job by exact
  PID.
- Stop any server, `php -S` or headless browser you start, by its exact PID,
  when you're done.

### PHP and Pest

- PHP is Herd's `C:\Users\steph\.config\herd\bin\php84\php.exe` (PHP 8.4). In
  PowerShell, `php` also works through Herd's `php.bat`. It isn't on Git Bash's
  PATH.
- Stack: Laravel 13, Inertia 2 + React 19 + TypeScript, Tailwind 4, Pest 4,
  Vitest 4, MySQL 8 (Herd), Go 1.27 for `endpoint-agent/`.
- Run scoped Pest groups from the worktree:
  ```powershell
  $php = "$HOME\.config\herd\bin\php84\php.exe"
  & $php vendor/bin/pest <files…> --colors=never *> "$env:TEMP\sd-pest-1.log"
  ```
- **Each Pest process creates its own MySQL database**
  (`oblivion_findings_codex_test_<pid>`), loads the whole schema (about 200 s,
  sometimes 5–7 min), and drops it on exit. A 1-test file still pays that cost,
  so batch related files.
- **Never `--parallel`**: its worker databases are never migrated, so most
  tests fail for no real reason.
- If a run sits at 0 % CPU at the very end, the shutdown `DROP DATABASE` may be
  waiting on its own idle connection. In MySQL, `SHOW FULL PROCESSLIST`, then
  `KILL` the `Sleep` connection in that `_<pid>` database.
- Tests that change schema or commit for real run last, in their own process.
- The S&D groups used on 22 Oct (about 15–20 min each, one process per group):
  ```powershell
  # Group 1: scripts and permissions
  $g1 = @(
    "tests/Feature/SecurityDevices/EndpointAgentLifecycleTest.php",
    "tests/Feature/SecurityDevices/EndpointScriptCatalogueTest.php",
    "tests/Feature/SecurityDevices/EndpointScriptPermissionRegistrationTest.php",
    "tests/Feature/SecurityDevices/EndpointScriptPolicyIsolationTest.php",
    "tests/Feature/SecurityDevices/EndpointScriptPolicyTest.php",
    "tests/Feature/SecurityDevices/EndpointServiceActionsTest.php",
    "tests/Feature/SecurityDevices/EndpointSoftwareInstallTest.php",
    "tests/Feature/SecurityDevices/EndpointScheduledRestartTest.php",
    "tests/Feature/It/ItRemoteSupportWorkspaceTest.php",
    "tests/Feature/It/ItRemoteSessionTest.php",
    "tests/Feature/RestrictedIndependentAuthoritySeedingTest.php",
    "tests/Feature/PermissionDefinitionCoverageTest.php",
    "tests/Unit/SecurityDevices/ManagementCommandCapabilityRegistryTest.php"
  ) + (Get-ChildItem tests/Unit/SecurityDevices/Endpoint*.php | ForEach-Object { "tests/Unit/SecurityDevices/" + $_.Name })
  & $php vendor/bin/pest @g1 --colors=never *> "$env:TEMP\sd-pest-g1.log"

  # Group 2a/2b: the rest of tests/Feature/SecurityDevices (about 90 files),
  # split in two halves, plus these:
  $g2extra = @(
    "tests/Feature/Queclink", "tests/Feature/Integrations", "tests/Feature/Consents",
    "tests/Architecture/ItSecuritySingleTenantBoundaryTest.php",
    "tests/Architecture/SecurityDevicesAccessBoundaryTest.php",
    "tests/Architecture/DeviceCommandOperationalBoundaryTest.php",
    "tests/Architecture/MilesightWebhookBoundaryTest.php",
    "tests/Architecture/IntegrationProviderSecretBoundaryTest.php",
    "tests/Architecture/ItSecurityDesktopReleaseAcceptanceBoundaryTest.php",
    "tests/Architecture/ItSecurityReleasePackagingBoundaryTest.php",
    "tests/Architecture/HsEventClosureBoundaryTest.php"
  )

  # Group 3: the remaining tests/Unit/SecurityDevices files
  ```
- A test that "can't fail because it never shows up": Pest keeps only one test
  class per file. Check with `pest <file> --list-tests`.
- Red result checklist before blaming the code: run the same file on `main`
  (identical failure → pre-existing); look for date rot (fixtures relative to
  "now" plus a fixed time travel); frozen clocks must use `->utc()`; random
  factory values can flake.

### Frontend

- Vitest: `npx vitest run <files> --maxWorkers=2`.
- Type-check: `$env:NODE_OPTIONS="--max-old-space-size=8192"; npx tsc --noEmit -p tsconfig.json`.
  It runs out of memory at the default heap, and an out-of-memory crash is not a
  type failure.
- ESLint: `npx eslint <files> --max-warnings=0`. `main`'s lint gate is meant to
  be 0 errors / 0 warnings. `npx eslint .` uses about 5 GB, so lint in chunks.
  CI runs `prettier --write resources/` before ESLint, so to match CI line
  numbers use `npx prettier <f> | npx eslint --stdin --stdin-filename <f>`.
- Wayfinder (generated route helpers in `resources/js/routes` and
  `resources/js/actions`) can fail on Windows at a folder named `prn` (a
  reserved device name). If `tsc` only complains about missing `@/routes/…`
  files, that's the environment, not your change. The 22 Oct workaround was to
  copy the missing generated files from the main checkout without overwriting.
- A Vite build is heavy and rarely needed. If you must:
  `$env:PATH="$HOME\.config\herd\bin\php84;$env:PATH"; $env:NODE_OPTIONS="--max-old-space-size=8192"; npm run build`.

### Go (endpoint agent)

`cd endpoint-agent; go test ./...` once Go 1.27 is installed (decision 8). No
Go is installed now. The script tests read committed fixtures in
`tests/fixtures/endpoint-script-*.json`.

### Seeing pages in a browser (optional)

The local site https://oblivionfindings.test is served by Herd from the **main
checkout**, so it does not show this branch. This worktree's `.env` uses the
shared dev/test database `oblivion_findings_codex_test`. The 22 Oct work was
verified through tests, which is the expected route. If you need a real browser
check: build (above), run `php artisan serve --port=<free port>` from the
worktree, sign in through the local-only route `/_dusk/login/{userId}`, look,
and press Cancel rather than saving. Stop the server by PID afterwards. Never
type passwords, including any found in seeders, and don't migrate a database
without asking (decision 11). For a check on the real deploy, see section 8.

---

## 7. House rules for any agent

- **Read `DESIGN.md` before any UI change**, and follow it. Semantic tokens
  only, no raw Tailwind colours or hex; reuse `resources/js/components/ui/` and
  the shared components; typography helpers; `<StatusBadge>` for status; check
  its "Named anti-patterns" list before you finish. When Stephan corrects a
  design choice, add it to that list.
- **Web-only desktop app.** Full-width pages, no mobile views. Hide actions
  that don't work yet; no "coming soon" stubs. NZ English, plain words, 12-hour
  times, sentence case.
- **Single organisation** per installation (`AGENTS.md`). No tenant pickers or
  cross-customer features.
- **Never commit `database/schema/mysql-schema.sql` or `phpunit.xml`.** Test
  setups sometimes swap or bump them locally. Restore them with
  `git checkout -- <file>` before committing.
- **Stage exact paths only.** `git add <path> <path>`, then
  `git diff --cached --stat`, then commit. Never `git commit -a`, `git add .`
  or `git add -A`.
- **Never force-push.** Push only this PR branch. Push to `main` only as a
  fast-forward, and only with Stephan's explicit OK.
- **Never leave files in `/var/www/oblivionfindings` on the .com server** (it
  blocks deploys; section 8).
- **Never loosen safety, privacy or permission rules to get tests green.**
- **Kill processes only by exact PID** after reading their full command line.
  Never by name or a pattern match; other agents run PHP, Node and Chrome too.
- **One heavy command at a time** on the whole machine (section 6).
- **Commit messages** follow the repo style: `type(scope): plain sentence`,
  for example `fix(security-devices): …`.
- Don't edit, switch branches in, or commit in the main checkout
  `C:\Users\steph\Herd\oblivionfindings`. Don't change anything inside
  `~/.codex/worktrees/*`.
- Never delete a worktree folder recursively. Some worktrees link their
  `vendor` or `node_modules` to another checkout, and a recursive delete
  follows the link and empties the original. This worktree's `node_modules` is
  real, and other worktrees have linked to it before. Don't delete it.

---

## 8. The .com test server and deploys

- https://oblivionfindings.com is a **test/demo server, not production**. Since
  23 Oct it also shows the client demo website (`DEMO_SITE=true`, fictional
  provider "Oblivion Living"), so keep what you create there tidy. Stephan
  signs in as the demo admin. Don't handle passwords.
- **Deploys:** a poller on the server checks GitHub `main` every minute. When
  `main` moves, it pulls, installs, builds the frontend and runs migrations.
  It does **not** run seeders. Only merges to `main` deploy, and those are
  Stephan's call.
- SSH (key-based): `ssh -o BatchMode=yes oblivionfindings.com`. User `oblivion`,
  app at `/var/www/oblivionfindings`, PHP CLI `/usr/bin/php`. Deploy logs:
  `/var/log/oblivionfindings/deploy.log` and `deploy-poller.log`. After a
  deploy, check them for "deployment refused".
- **Never leave any file in `/var/www/oblivionfindings`,** not even a `.env`
  backup. The deploy refuses a checkout with untracked files, retries every
  minute and takes a full database dump each time. This happened twice on
  23–24 Oct. Keep backups in `~/env-backups/`. After any server change,
  `git status --porcelain` there must print nothing.
- After PR #23 is deployed, the six script permission keys grant nobody. That's
  intended (decision 6).
- New permission keys elsewhere need a grant migration, because deploys don't
  run seeders. Without one the feature 403s for everyone. Permission checks use
  `User::canDo()`, which has no super-admin bypass.

---

## 9. Risks and known gotchas

- **Claude's Auto-fix can change your working copy.** See decision 7. Before
  each commit, check `git status`. If a merge you didn't start appears, run
  `git merge --abort` and tell Stephan.
- **Codex's checkout is gone.** `~/.codex/worktrees/native-endpoint-management`
  was emptied on 23 Oct, with its gitignored `.endpoint-tools/` folder: the
  portable Go and .NET, Codex's evidence JSON and its uncommitted
  `HANDOVER-2026-10-23.md`. Files that `CHECKPOINT.md`, `PROGRAMME.md` and
  `CAPABILITY-COVERAGE.md` cite under `.endpoint-tools/…` no longer exist on
  disk. Codex's session logs are still in `~/.codex/sessions/2026/10/`, if one
  is ever truly needed. Everything the tests need is committed. Don't rebuild
  evidence and present it as the original.
- **Script execution must stay impossible.** `EndpointScriptPolicy::available()`
  returns false, dispatch throws `script_executor_unavailable`, and the agent
  has no executor. Item 6 of the server gap list shows that a script could
  otherwise fall through to the service-action path. Don't change dispatch
  without closing that item.
- **MySQL lock clashes.** `FOR UPDATE NOWAIT` can raise MySQL 3572 (also 1205
  and 1213). The S&D pattern is `EndpointScripts::isLockContention()` → a
  retryable 503 or a quiet "not now", never a 500. Read data after taking the
  lock, not before (an InnoDB snapshot taken before the lock can be stale).
- **Time:** store UTC, show the worker's local time. `now()->startOfDay()` is
  UTC midnight. Carbon 3's `diffInDays` is signed and returns a float; use
  `lte(now()->addDays(N))`.
- **Laravel 13:** `->offset(0)` without a limit gives a SQL error. An empty
  string becomes `null` and 500s on NOT NULL columns. MySQL JSON columns
  reorder keys, so don't compare raw JSON text.
- **Tailwind:** `bg-primary/10/50` (double opacity) silently renders
  transparent.
- **`main`'s CI is red for reasons outside S&D** (section 2). Don't chase them in
  PR #23.
- **Windows temp cleanup** can empty checkouts kept under `%TEMP%`, which shows
  up as "Class … not found" in Pest. Run tests in this worktree, not a copy
  under `%TEMP%`.
- **Safety fixes to keep:** client names in device lists, tracker consent, the
  restricted script keys, encrypted history on the scripts page. Regressions
  here are privacy incidents, not style issues.

---

## 10. Reference docs (read only what the task needs)

All under `docs/native-endpoint-management/` unless noted.

- `AUDIT-2026-10-22.md`: takeover audit, script-execution gap list, design
  pass, rest of the programme. **Start here.**
- `RESUME-PLAN-SOL61-2026-10-11.md`: the full delivery plan (packages 0–8),
  acceptance gate, UI rules.
- `OBLI-DECISIONS.md`: Stephan's confirmed product decisions (7 and 11 Oct).
- `CAPABILITY-COVERAGE.md`: 24 equipment families, what's built and what's
  proven.
- `PROGRAMME.md`, `CHECKPOINT.md` (a 600 KB log; search it, don't read it
  whole): Codex's progress record.
- Workstream contracts: `SCRIPT-CONTRACT-DRAFT.md`, `REMOTE-SUPPORT.md`,
  `STAFF-HELPER.md`, `CCTV-CONTRACT.md`, `HIKVISION-CONTRACT-DRAFT.md`,
  `EFFECTIVE-POLICY-CONTRACT.md`, `SOFTWARE-CONTRACT.md`, `TOPOLOGY-MAP.md`, the
  `RELEASE-*.md` files and others.
- `docs/security-devices-audit-2026-10-07/`: the original scope, audit, market
  research and development pilot.
- PR #23's description on GitHub: a short summary of what's in the PR.

---

## 11. Reporting to Stephan

He reads short messages. Each time you stop, say in plain words:
- **Done:** what changed, with commit hashes, and whether it's pushed.
- **Running:** anything still going.
- **Needed from you:** one clear question with options, or "nothing".

Lead with the outcome, skip jargon, and don't claim something passed unless you
ran it. If you couldn't run a check (Go, a browser check), say so.

Terms you may see in older notes: "heavy-lock" is the Claude job queue from
section 6. The "coordinator" or "Pinned sessions overview" is a Claude session
that passed on his instructions. "Side-session notes" and "memory" are Claude's
own note folders. The "H&S session" is the Claude session that built Health &
Safety. The "design kit" or "viewer" is a set of drawings he has set aside. None
of these is needed to continue.
