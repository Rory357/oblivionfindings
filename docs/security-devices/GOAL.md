# Security & Devices rebuild: the finish line

Written 30 Oct 2026 by the S&D build session, for MASTER and Stephan to approve before any app code.

Stephan approved the plan on Fri 30 Oct 2026 ("Approve, start Phase A"; "Claude goal, MASTER gates").
This file says what "done" means, how each phase is proved, and what Stephan still needs to check at the end.

## Where the plan lives

| What | Where |
| --- | --- |
| The approved plan (version 7) | https://claude.ai/artifact/SzP42kmjNSi3RWJu9UHYZw |
| The same plan as markdown | `C:\Users\steph\Herd\oblivionfindings-design-kit\sdr-plan\pack\` (`plan.md`, `audit.md`, `windows.md`, `decisions.md`) |
| The drawings to match | `C:\Users\steph\Herd\oblivionfindings-design-kit\sdr-plan\site\b\Pg-SDR-*.html` and `Pop-SDR-*.html` |
| Who to ask about drawings, decisions or audit items | the design session "Device management and security delivery" (`local_2bd3983b…`) |
| Who approves each merge | MASTER (`local_b0d978d3…`), after Stephan's home walk-through |

This file replaces `PROGRESS.md` as the record of where S&D stands. `PROGRESS.md` holds Gemini's claims, and the audit shows many of them are untrue.

## 1. Done means

All of these are true:

1. **Every drawing is built.** All 69 page views and 79 pop-up drawings in version 7 exist in the app, view for view.
   - Each one is compared with its drawing at 1440 px wide, in light and dark.
   - Pages follow `DESIGN.md`: semantic colours only, the Event Horizon header rules, the right layout shell, and `<StatusBadge>` for every state.
   - Pop-ups use the kit in `resources/js/components/popup/`: widths 480, 720 and 900, wizards 1100, Cancel first, and the main button named for what it does.
   - Setup follows Fleet's settings layout exactly.
   - Today's 36 pages and ~128 pop-ups are kept, moved or removed as the plan's two "What happens to today's…" tables say. No old page is left that the new menu can't reach.
2. **Every audit item is fixed.** That means the 5 broken, the 7 fake or overclaimed and the 16 missing items (the final audit says 16, up from 13). Each item is ticked in section 5 below with the commit that fixed it.
3. **The Windows app is "download → Next → Next → Finish".**
   - One file per Site, already set up for that Site. The setup code is in the file name and works once, for 24 hours (D2).
   - Every hub PC installs the hub and Obli (D4). "Ask Obli" only looks: it runs read-only checks through the hub.
   - Staff without admin rights can install Obli for themselves.
   - It updates itself overnight and rolls back if an update fails (D3). It uninstalls from Windows Settings › Apps.
   - Unsigned while testing (D1). It must be signed before the first client install.
4. **Stephan's kit works for real.** Each driver below is walked by Stephan on his home network, and its walk is written down:
   - UniFi Network, UniFi Protect and UniFi Access;
   - Ajax (today only a "Planned" stub on main): view only, reporting through the alarm receiver;
   - Milesight sensors.
5. **The data moves are done.** Each move has a dry run, a full backup and MASTER's OK at the time. This includes D9 (remove the 3 test Sites) and D14 (clear names from door events after 90 days).
6. **Every new permission has a grant migration.** There are 17 new keys, and each ships in the same change as the migration that grants it. Deploys skip seeders.
7. **Checks are green on main.** CI passes, `npm run lint` stays at 0 errors and 0 warnings, and MASTER has checked every phase live on the .com.

## 2. Honest labels

- **Nothing says "Tested on real hardware" unless it was.** A driver earns that label only when Stephan has walked it on real kit. The driver records the date, the model and the walk it passed.
- **New guard test.** A Pest test fails if any driver claims `TestedOnHardware` without a matching entry in `docs/security-devices/HARDWARE-WALKS.md`. This makes the rule something the build checks, not something people have to remember.
- **Phase A takes back Gemini's overclaims:**
  - `UbiquitiUnifiNetworkDriver` goes back to "Built from vendor docs". PoE cycle and reboot are removed until they are built and walked.
  - `QueclinkTrackerDriver` goes back to "Built from vendor docs". Stephan's tracker has been offline since 20 May, so it was never proven.
  - 7 alarm brands lose Arm and Disarm and show "View only": Ajax, Arrowhead, Bosch, DSC, ICT Protege, Inner Range Integriti and Paradox.
  - "Test relay" stops saying "Verified".
  - `PROGRESS.md`, `SUPPORTED-DEVICES.md` and the release workflow stop claiming a live SNMP test, real certificate issuing, Windows arm64 hubs and "100% verified live".
- **No button that does nothing.** A button either works or isn't shown. No "coming soon".
- **Phase reports say what was proved and how:** a test, CI, a walk on Stephan's home network, or the live .com. "Done" is never claimed from tests against pretend devices alone.

## 3. How every phase is built and merged

1. **Build in this worktree** (`claude/eager-sinoussi-119427`, cut from main). Run `git fetch origin && git merge origin/main` before starting and before each gate.
   - Don't edit Gemini's worktree (`agitated-wozniak-ab58fb`). Any piece of Gemini's work is copied on purpose, after checking it against the audit.
2. **Gate.** Every heavy command runs through `bash ~/.claude/heavy-lock.sh "sd-<label>" -- <cmd>`, in the background, with no `--parallel` and no more than 2 helpers. PHP is Herd's `~/.config/herd/bin/php84/php.exe`. The gate runs:
   - Pest for the S&D, Monitoring and collector tests the phase touches;
   - vitest for the S&D pages the phase touches;
   - `npm run lint` (must stay 0/0);
   - the full `tsc` (8 GB heap);
   - `vite build`.
3. **Report to MASTER:** the commit, the gate results, screenshots from the local copy at 1440 px next to the drawing, and the phase's walk-through list.
4. **Stephan walks the phase on his home network before it merges.** He uses the local copy on this PC, which sits on his home network. MASTER passes on the list and his result.
5. **Merge.** MASTER approves the push to main only after the gate and Stephan's walk both pass. The .com deploys within about a minute, and MASTER checks it live.

**Standing rules**

- Never loosen safety or privacy.
- Never type a password or secret, including camera, device or SNMP logins. Stephan types those himself. Test values are used only on local dev hosts.
- Data steps on the .com need a full dump in `~/env-backups/` and MASTER's OK at the time. Never leave a file in `/var/www/oblivionfindings`.
- **Hands off two shared paths until MASTER says the slowness fix has landed:** `SignalProcessingService`, and the path that turns a device signal into a Control Room alert. In Phase A that covers the alarm receiver's outbox publishing (`SiaDc09IntakeService` → `MonitoringOutboxPublisher`). Phase A changes only how messages are read, answered and kept running, not where they go.
- Ask MASTER before editing shared files outside S&D.

## 4. Phases, in the plan's order

Each phase is one change and one merge. A phase may be split into smaller merges if MASTER prefers, each with its own gate and walk.

### Phase A · Honest and safe (engine fixes; nothing new on screen except removals)

**What ships**

| # | Fix | Files (from the audit) |
| --- | --- | --- |
| A1 | **Overclaims removed.** The 7 label-only alarm brands become "View only", with no Arm or Disarm anywhere. UniFi PoE cycle and reboot go. UniFi Network and Queclink drop to "Built from vendor docs". The fake port-action path stops being offered. The `HARDWARE-WALKS.md` guard test lands. | `Drivers/Alarms/*Driver.php`, `UbiquitiUnifiNetworkDriver.php`, `QueclinkTrackerDriver.php`, `NetworkDeviceCommandAdapter.php:169`, `supported-devices.tsx`, `PROGRESS.md`, `SUPPORTED-DEVICES.md`, `site-hub-release.yml:276-280` |
| A2 | **"Test relay" is honest.** With no relay set up it says "No relay set up". With one set up it runs a real STUN/TURN reachability check and says exactly what answered. It never says "Verified" without contacting the relay. | `EndpointRemoteSupportSettings.php:396-450` |
| A3 | **The alarm receiver (SIA DC-09) works.** It checks the check-sum over the right bytes, uses the correct encrypted layout, answers encrypted messages encrypted, rejects messages outside the time window, and listens on an address you can set. A supervisor program keeps it running, and a heartbeat shows on the server check. Ajax reports through it, view only. | `SiaDc09FrameDecoder.php`, `ListenSiaDc09Command.php`, `AjaxHubAlarmDriver.php`, `ops/supervisor/` |
| A4 | **MQTT works after settings are cached.** The password is read from config, not `env()`. The listener reconnects with back-off and runs under supervisor. | `ListenMqttSensorsCommand.php`, `config/`, `ops/supervisor/` |
| A5 | **Camera passwords are safe.** Only WS-Security digest and HTTP Digest are used, never plain Basic. Certificate checks are on. A camera is trusted on first use with "Trust this camera?" (a small kit confirm) and refused if its certificate later changes. | `OnvifCameraProfileClient.php:1326, 1594` and its callers |
| A6 | **SSH keys are pinned.** The first connect asks "Trust this device's key?" and remembers the key. A changed key is refused with a plain message, and only an IT admin can replace a trusted key; the replacement is logged. | `PhpseclibNetworkCliTransport.php:70-83` and its callers |
| A7 | **SNMP works on hubs.** The SNMP module is bundled in the Windows hub runtime and added to the Linux package. SNMP v2c is supported next to v3. | `collector/src/Runtime/ProbeRunner.php:313-325`, `endpoint-agent/internal/collectorhost/installed_windows.go:216`, `endpoint-agent/cmd/oblivion-site-collector/run_linux.go:58`, `site-hub-release.yml` |
| A8 | **The Milesight webhook check is real.** The invented `X-Milesight-Signature` header is replaced by what Milesight's own documents say, or by a per-Site secret that you set in the gateway. | `MilesightGatewayWebhookController.php:58` |

**Moved out of Phase A.** The plan's engine-fix table also lists hub approval, scoped logins, routing through the hub, scheduled hub scans, the code-signing check and the hub syslog/Protect code. Those need the new installer and hub, so they ship in Phase B with that phase's walk. See review item R1.

**Tests**

- **Alarm receiver:** Pest tests that feed it reference messages from outside our own code (see R3). They check that every sample is accepted and acknowledged, that encrypted samples get encrypted replies, and that a bad check-sum or an old time is rejected.
- **MQTT:** a test with cached config and a reconnect test against a fake broker that drops the connection.
- **Cameras:** a fake camera answering with a plain-HTTP Basic challenge gets no password. A changed certificate is refused.
- **SSH:** first use returns "not trusted yet" with the fingerprint, and a changed key is refused.
- **Hubs:** collector tests for SNMP v2c and v3 requests. CI proves the hub runtime loads the SNMP module.
- **Labels:** a registry test: no `Planned` or label-only driver has Arm or Disarm, and the `HARDWARE-WALKS.md` guard passes.
- **Relay:** "Test relay" with no relay says so, and with a dead relay says it didn't answer.
- **Pages:** vitest for the Supported devices page and every page that used to show Arm or Disarm.

**Stephan's walk-through, on his home network, before merge**

1. Open Setup › Supported devices. Expect: Ajax and the other 6 label-only alarm brands say "View only". Nothing says "tested on hardware" for UniFi PoE cycle or reboot.
2. Open the Ajax alarm page. Expect: no Arm or Disarm button anywhere.
3. Run the alarm receiver test with the reference messages. Expect: every sample is accepted and acknowledged, and encrypted samples get encrypted replies.
4. Stop the alarm receiver and the MQTT listener. Expect: both restart by themselves within 1 minute, and the server check is green. Supervisor runs only on the .com, so this step is walked there right after the merge, once Stephan has added the programs (`ops/supervisor/README.md`, step 4). Before the merge, the restart is shown on this PC by stopping and starting the command.
5. On this PC, with a local test broker bound to this PC or the home network only (never the .com) and settings cached, send an MQTT reading, then restart the broker. Expect: the reading arrives, and the listener reconnects within 1 minute (R8).
6. Add your UniFi Protect console, which has a self-signed certificate. Expect: "Trust this camera?" is asked once, and no plain Basic-auth password appears in the console's log.
7. Back up a home device's settings over SSH, then point the same device record at a different SSH device. Expect: the first backup asks to trust the key, and after the swap it is refused with a plain message.
8. Settings › Remote support › Test relay, with no relay set up. Expect: "No relay set up", never "Verified".
9. Open the pull request. Expect: CI is green, and the CI-built hub loads the SNMP module.

Two steps were dropped at Stephan's request on 30 Oct: reporting from his Ajax alarm (R7) and reading his UniFi switch over SNMP (R10). Both go on the end-review list as not proven on hardware.

**Data and site steps**

- **D9:** remove the 3 test Sites from the .com, with a full dump to `~/env-backups/` first and MASTER's OK at the time.
- **Supervisor (R6):** the alarm receiver program has to be added once on the .com. That needs root, so it is Stephan's job. The exact commands to copy and paste are in `ops/supervisor/README.md`, and MASTER hands them over when Phase A is ready to merge.
- **MQTT on the .com:** nothing in Phase A. Its listener program waits for the central private broker (R8, later phase). There is no broker and no MQTT port on the .com, ever on the public internet. Any broker password is typed by Stephan himself.

**CI for the hub (MASTER's OK, 30 Oct)**

- The build branch may be pushed so GitHub builds the hub, because this PC has no Go. It is never pushed to main, and there is no pull request to main before the merge gate.
- Only `main` signs with the real `ENDPOINT_*_SIGNING_SEED` keys. Every other branch uses throwaway keys and never uploads a signed catalogue. That change to `site-hub-release.yml` lands before the first push.

### Phase B · Windows app and hubs

**What ships**

- **One installer:** Inno Setup, one file per Site, with the setup code in the file name. A hub PC gets the hub and Obli (D4). Staff without admin rights can install Obli for themselves.
- **Hubs:** each new hub is approved by an admin (pop-up 79). Logins are scoped to the hub's Sites and encrypted on the hub; removing or replacing a hub revokes and wipes them.
- **Updates:** real releases, nightly self-update with rollback (D3), and a "hold an update" switch.
- **Uninstall:** an Add/Remove Programs entry.
- **Pi or Linux hub:** a one-line command that brings its own runtime and installs nothing from apt.
- **Routing through hubs:** cameras, SSH, alarms, web pages and the terminal go through the hub that covers the Site.
- **Hub scans and checks:** scheduled scans on hubs, and ping and offline alerts run from the covering hub.
- **Code-signing check:** restored behind an explicit unsigned-test switch (D1).
- **Hub syslog/NetFlow and Protect client:** wired in, or deleted.
- **Screens:** Setup › Hubs & networks › Hubs, the Add a hub wizard, and the Hubs and downloads pop-ups.
- **Permissions:** `securityDevices.hubs.approve` and `securityDevices.savedLogins.manage`, each with its grant migration.

**Tests**

- Pest for hub approval, login scoping and wiping on revoke.
- Collector tests for routing through the hub.
- Go tests and the installer build in CI (this PC has no Go).
- vitest for the hub screens.

**Walk-through:** steps 1–11 of the plan's Phase B. That covers download, Next/Next/Finish on a clean Windows 11 PC, approve, the second-PC refusal, a renamed file asking for the code, the non-admin staff install, update 1.3.1, rollback from 1.3.2, the Pi one-liner, reaching the house only through the hub, and remove plus uninstall.

**Data steps**

- Collectors and site collectors become one table called Hubs. Old setup codes stop working.
- Credential references and Site credentials become Saved logins, each with a Site.

### Phase C · Core screens

**What ships**

- **Overview:** first time, support worker's view, Trends, Reports and Activity.
- **Devices:** cards and list, the camera wall, Upkeep › Batteries and Updates.
- **Device pages:** switch (Overview, Ports, Checks & alerts), printer, UPS, computer (Overview, Health), Wi-Fi point, video recorder, and "device in trouble".
- **Sites:** the Sites list and the Site page (Overview, Network map, Internet, Devices, Logbook), plus "hub stops answering".
- **Found devices.**
- **Setup:** Overview, Hubs & networks, and Services & logins (Connected services, Saved logins, Supported devices).
- **Pop-ups:** Adding devices, scans and found devices; On a device: ping, watch, edit.
- **The new 6-entry menu.** Old pages are moved or removed as the plan's tables say.

**Tests**

- vitest for each page and pop-up.
- Pest for the presenters (counts on Overview match the Devices list).
- Architecture tests for semantic tokens and the pop-up kit.

**Walk-through:** steps 1–8 of the plan's Phase C, in light and dark. That includes opening every pop-up on these pages to check its kit width and that Cancel comes first.

**Data steps**

- Devices get their kind, room and Site from a one-off script with a dry run.
- Monitors become checks. Discovery tables are kept as they are. A daily scan stays off until someone turns it on.
- Device groups become rooms and filters (D8). The old tables are kept read-only for one release and dropped only after Stephan says yes.

### Phase D · Alerts

**What ships**

- **Alerts:** Open, Snoozed, Done, and Rules & automations (rules part).
- **Checks & alerts tabs** on every device page.
- **Setup › Alerts & checks:** Alert rules with the "Review changes" bar, and Checks.
- **Alert handling:** acknowledge, assign and snooze; root cause (devices behind a down switch show grey "Can't check"); quiet time.
- **Who's told:** whoever is on shift from the roster, plus IT on call, by app and email (D13).
- **Report incident:** opens the H&S form, filled in.
- **Permission:** `securityDevices.alerts.acknowledge`.

**Tests**

- Pest for root cause, quiet time, snooze expiry, roster-based recipients and auto-close.
- vitest for the Alerts pages and pop-ups.

**Walk-through:** steps 1–7 of the plan's Phase D (unplug a home switch, and so on).

**Data steps**

- Maintenance records become logbook entries and quiet time.
- Events become alerts and logbooks; the new queue starts empty.
- Command batches become logbook lines.
- ⚠️ This phase works on the device → Control Room alert path, so it waits until MASTER says the slowness fix has landed.

### Phase E · Safety kit

**What ships**

- **Cameras:** Live (a reason before every view, D6), Recordings, and Settings with privacy hours and mask.
- **Alarm panel:** Zones, Events, Notes & documents. Inner Range Inception and Gallagher can arm and disarm, with a confirm and the state read back; Ajax stays view only.
- **Door:** Events and Access. Unlock for 5 seconds, or keep unlocked until a time.
- **Sensor:** includes Hemi's bed sensor linked to his care plan, and the hot-water sensor that raises an H&S hazard over 45 °C.
- **Care tracker:** Location, Console, and the consent-blocked state.
- **Live camera view through the hub.**
- **Permissions:** `cctv.live.watch`, `alarms.arm`, `alarms.disarm`, `doors.unlock`, `people.link`, `trackers.command`.

**Tests**

- Pest for live-view reasons and the viewing log, privacy hours on every screen, consent gating, the arm/disarm read-back and unlock relock.
- vitest for each page and pop-up.

**Walk-through:** steps 1–6 of the plan's Phase E. Arm and disarm are walked only if a supported panel is available; Ajax stays view only.

### Phase F · Home-Assistant layer

**What ships**

- **Site pages:** Rooms and Modes. Modes never disarm or unlock (D12).
- **Rules & automations,** with the Add automation wizard and "Test it".
- **Links:** sensors to a person's care plan (consent checked), and H&S hazards from readings.
- **Overview › Trends graphs.**
- **Permissions:** `automations.manage` and `modes.switch`.

**Tests**

- Pest proving no automation or mode can ever disarm or unlock.
- Pest for the TEST-only message, the consent block and the hazard draft.

**Walk-through:** steps 1–6 of the plan's Phase F.

### Phase G · Network tools

**What ships**

- **Terminal tab,** through the hub, for IT admins only (D5). Every command goes in History, and it closes after 15 minutes idle.
- **A device's web page,** opened through the hub.
- **Settings backups:** nightly backups with compare (pop-up 24).
- **Updates:** Oblivion shows them; you install them in UniFi.
- **Remote support relay:** the person at the PC clicks Allow, and sessions aren't recorded (D7). This includes the Computer › Remote support tab and Setup › Computers & Obli › Remote support.
- **Permissions:** `terminal.use` and `webPage.open`.

**Tests**

- Pest for permission gating, command logging and the idle timeout.
- Relay tests.

**Walk-through:** steps 1–5 of the plan's Phase G.

### Phase H · Access and clock-ins (UniFi Access first)

**What ships**

- **Access:** Doors, People, Visitors, Schedules, Clock-ins and Activity, plus the staff band on My Day.
- **Setup › Doors & access:** UniFi Access, and Clock-ins from doors.
- **Access pop-ups,** including Connect UniFi Access. Its "every door has an exit button and a handle" tick is required.
- **Kept in step with UniFi Access** through each Site's hub, because the API answers only on the Site's own network.
- **Clock-ins:** a badge suggests a clock-in and the person confirms it (D15). Faces and fingerprints never count (D18). Residents' fobs show as "Resident fob" (D19).
- **Leavers:** their cards turn off at the end of their last day, from HR (D20).
- **Lockdown, evacuation and roll call.**
- **Permissions:** `access.lockdown` and `attendance.doorClockIns.review`.

**Tests**

- Pest for people matching, the clock-in window, nothing clocked in without the person, the leaver cut-off, and lockdown and evacuation.
- vitest for each page and pop-up.

**Walk-through:** steps 1–5 of the plan's Phase H, using Stephan's UniFi Access console.

**Data steps**

- Door cards and schedules are matched by employee number in a dry run. Nothing is sent to UniFi until a manager confirms.
- **D14:** names on door events older than 90 days are cleared after a backup and MASTER's OK.

### Phase I · Obli fixes things

**What ships**

- **Obli on the PC:** Windows screens H–O. It shows its plan and needs one OK, then works with a Stop bar (D16). Admin fixes come only from IT's list, and it never types passwords.
- **Screen pictures:** kept 7 days for IT (D17).
- **Offline checks:** built-in network checks when there's no internet.
- **Ask Obli** for a Site's network (pop-up 97, read-only).
- **Screens:** Computer › Obli help, Setup › Computers & Obli (Obli, and Fixes Obli can make), and pop-ups 93–97.
- **Permissions:** `obli.fixes.manage` and `obli.pictures.view`.

**Tests**

- Pest for the fix list, one-off approval, picture expiry and the view log.
- Tests for the refusal list (antivirus, firewall, BitLocker, admin changes).

**Walk-through:** steps 1–5 of the plan's Phase I, on a test PC.

### Which drawings belong to which phase

| Board | Phase |
| --- | --- |
| Pg-SDR-1 Overview · Devices | C (Trends: F; Reports and Activity: C) |
| Pg-SDR-2 Devices · Network switch | C (Terminal tab: G) |
| Pg-SDR-3 Switch · Camera · Alarm panel | C (switch Checks & alerts: D); camera and alarm: E |
| Pg-SDR-4 Alarm panel · Door · Sensor | E (Door › Access tab: H) |
| Pg-SDR-5 Sensor · Care tracker · Computer | E (computer: C) |
| Pg-SDR-6 Computer · Printer · Found devices · UPS | C (Obli help: I; Remote support: G) |
| Pg-SDR-7 Device in trouble · Wi-Fi · Recorder · Sites | C |
| Pg-SDR-8 Site · Access | C (Rooms and Modes: F; Access: H) |
| Pg-SDR-9 Access · Staff view · Alerts | H (Alerts: D) |
| Pg-SDR-10 Setup: Overview · Hubs · Services | B (Hubs), C (the rest); Rules & automations: D and F |
| Pg-SDR-11 Setup: Services · Alerts & checks · Computers & Obli | C (Supported devices), D (Alerts & checks), G and I (Computers & Obli) |
| Pg-SDR-12 Setup: Doors & access · Change history | H (Change history: C) |
| Pop-SDR-Wizards | B (add a hub), C (connect a service), F (add automation), H (give access, connect UniFi Access) |
| Pop-SDR-Add, Pop-SDR-Device | C (terminal, web page, backups: G) |
| Pop-SDR-Hubs | B |
| Pop-SDR-Safety | E |
| Pop-SDR-Alerts | D (house modes and quiet time: F) |
| Pop-SDR-Computers | G and I |
| Pop-SDR-Access | H |

At the start of each phase I list every view and pop-up it covers, by drawing number, and tick each one with its 1440 px comparison.

## 5. Audit tracker

Each item is ticked with the commit that fixed it and how it was proved.

| Audit item | Phase | Fixed in | Proved by |
| --- | --- | --- | --- |
| Broken: alarm receiver (SIA DC-09) | A3 | Phase A | 17 checks against pysiaalarm reference frames (7 real CRCs, one real encrypted frame); intake, NAK, DUH, encrypted ACK and socket tests; supervisor walk on the .com after merge |
| Broken: SNMP on hubs | A7 | Phase A | Collector v2c/v3 tests; CI build fails unless the hub runtime loads SNMP (not walked on hardware, R10) |
| Broken: MQTT password and reconnect | A4 | Phase A | Listener tests: password from cached config, reconnect with back-off after drop and silent broker; broker walk waits for R8 |
| Broken: camera passwords over plain HTTP | A5 | Phase A | Real local TLS server: no password before trust, pinned key only, impostor refused before any byte; no Basic password ever sent |
| Broken: SSH accepts any key | A6 | Phase A | Backup sends no login to an untrusted key; changed key refused until an IT admin replaces it; audit-logged |
| Fake: hub syslog/NetFlow and Protect never called | B | | |
| Fake: 7 label-only alarm brands show Arm/Disarm | A1 | Phase A | Driver honesty guard test; Arm/Disarm never taken from saved settings; Supported devices shows "View only" |
| Fake: UniFi PoE cycle and reboot "tested on hardware" | A1 | Phase A | Claims removed; port actions not offered (made-up controller path); guard test |
| Fake: "Test relay" says Verified | A2 | Phase A | Real STUN check; "No relay set up" / "No answer from …"; never "Verified" |
| Fake: Milesight signature header | A8 | Phase A | Header dropped (not in Milesight docs); Site secret as Bearer, token header or Basic password |
| Fake: code-signing check deleted | B | | |
| Fake: progress notes overclaim | A1 | Phase A | PROGRESS.md and SUPPORTED-DEVICES.md corrected; HARDWARE-WALKS.md + guard test |
| Missing: scheduled scans on hubs | B | | |
| Missing: ping and offline alerts from a hub | B | | |
| Missing: routing through the hub | B | | |
| Missing: hub approval | B | | |
| Missing: self-update | B | | |
| Missing: uninstaller | B | | |
| Missing: real releases | B | | |
| Missing: Linux hub with its own runtime | B | | |
| Missing: screens for the alarm receiver, MQTT and LoRaWAN gateway | C (Setup › Connected services) | | |
| Missing: relay server for remote support | G | | |
| Missing: acknowledge, assign, snooze | D | | |
| Missing: live camera view through a hub | E | | |
| Missing: door access beyond a quick unlock | H | | |
| Missing: door badges and attendance | H | | |
| Missing: Obli doing anything on the computer | I | | |
| Missing: tests against real devices or reference messages | every phase (walks + reference samples) | | |

## 6. For Stephan's end review (kept up to date)

### Judgement calls made for you

- **R1. Phase A is engine only** (MASTER agreed on 30 Oct). Hub approval, scoped logins, routing through hubs, scheduled hub scans, the code-signing check and the hub syslog/Protect code move to Phase B. The plan's engine table lists them, but they can only be walked with the new installer and hub, and the plan's Phase B walk already covers them.
- **R2. The SSH walk swaps the target, not the key.** Step 7 points the device record at a second SSH device instead of changing your router's real key. It proves the same refusal without touching the router.
- **R3. Alarm receiver samples.** The full SIA DC-09 standard isn't free. Its sample messages are copied from public receiver projects whose samples came from real panels, and are credited in the test. Your Ajax alarm doesn't report in during Phase A (R7), so these samples are the proof for now.
- **R4. "Test relay"** does a real STUN/TURN check, not a full remote-support session. The full session is proved in Phase G.
- **R5. Milesight webhook:** if Milesight's documents don't define a signature for gateway forwarding, it uses a per-Site secret that you set in the gateway.
- **R14. A wrong length field doesn't stop an alarm.** The alarm receiver accepts a report whose check-sum is right even if its length field is wrong. The check-sum already proves the report arrived intact, and refusing a real alarm over a length quirk is worse. The open receiver Home Assistant uses does the same.
- **R15. Port on/off/restart are gone, not just hidden.** The code behind them called a made-up web address that no switch or controller has, so they can't be offered at all until Phase G builds a real UniFi version. The approval rules for uplink and camera ports stay in place for it, and their tests come back with it.
- **R16. Alarm keys are read both ways.** Ajax-style panels take the encryption key as 16, 24 or 32 characters. Some installers store the same key as hex digits. The receiver tries both and uses whichever decrypts a valid report.
- **R17. Strangers get no reply.** An alarm report from an address outside the Site's network gets no answer at all. A known panel with a bad report gets NAK, so it can correct and resend.
- **R18. Replies follow the open receiver.** The SIA DC-09 standard isn't free to read, so the reply format (ACK with no time, NAK with our time, encrypted ACK padded with `0`) follows pysiaalarm. Home Assistant users run that receiver with Ajax and other panels.

### Needs your hardware, your accounts or your installer

- **R6. Supervisor on the .com.** The alarm receiver and MQTT programs have to be added once on the .com. That needs root, so it's your job. The commands to copy and paste are in `ops/supervisor/README.md`.
- **R7. Ajax: not proven on hardware.** You chose to skip the Ajax PRO monitoring-station step on 30 Oct. Ajax stays "View only" and is not proven on real hardware. Ajax gives Oblivion no arm or disarm without Ajax's partner API.
- **R8. MQTT broker: answered 30 Oct.**
  - **Your answer:** one central broker beside Oblivion, reachable only over the private network (SD-WAN/MPLS), never from the public internet: "I don't want to expose to internet ever". Plain (unencrypted) MQTT is allowed only on those private paths. Houses without a hub may push Milesight readings, also over the private network.
  - **Phase A:** A4 is Oblivion's side (config(), reconnect with back-off, heartbeat). No MQTT port is opened and no broker is installed on the .com.
  - **Walk:** the MQTT check runs on this PC with a local test broker bound to this PC or the home network only.
  - **Later phase:** the central private broker itself. The design session is redrawing it, and the details are added here when they arrive.
- **R9. Cameras.** Your UniFi Protect console is used for the "Trust this camera?" walk (step 6).
- **R10. SNMP: not walked on hardware.** You chose to skip the switch step on 30 Oct. A7 is proved by the CI-built hub loading the SNMP module and by collector tests, not by reading your switch.
- **R11. Doors.** Your installer still has to confirm every door has an exit button and a handle. Connect UniFi Access requires the tick (pop-up 91).
- **R12. Signing.** Before the first client install, the installer needs a code-signing certificate (D1).
- **R13. Queclink.** Your tracker has been offline since 20 May. Queclink stays "Built from vendor docs" until one is walked.
- **R19. Opening the alarm receiver to the network.** The receiver only listens on the server itself until you set `SIA_DC09_BIND_HOST=0.0.0.0` and open port 51100 in the server's firewall. That opens a port, so it's your call, made when a panel is ready to report in.
- **R20. The two "Trust this…?" pop-ups weren't drawn.** v7 has no drawing for "Trust this camera?" or "Trust this device's SSH key?", so both use the kit's small confirm pop-up (480 px, Cancel first) and a notice on the existing page. The design session can draw them for Phase C or E, and they'll be matched then.
- **R21. One trust per camera address.** A camera or Protect console is trusted per Site, address and port. Trusting your Protect console once covers all its cameras.
- **R22. Who may trust.** First trust of a camera or SSH key: anyone who can run device management (`securityDevices.commands.manage`). Replacing a trusted key after it changed: IT admins only (`securityDevices.commands.admin`). This reuses existing permissions, so Phase A adds no new permission key and no grant migration.
- **R23. SSH backups for your kit.** Settings backups only run for MikroTik, Cisco, Aruba and Fortinet, and your network is UniFi. So walk step 7 uses a test device record pointed at a real SSH machine on your network. A UniFi settings backup is Phase G's job.
- **R24. The SNMP module is required in Windows hub releases.** The CI build now fails if the hub runtime can't load SNMP. On the old Linux (apt) install it's installed but optional, because Phase B replaces that install.

### Not proven yet

- Everything from Gemini's phases 0–6 that no walk has covered. That includes discovery, the maker lookup, switch-neighbour maps, SSH backup for MikroTik/Cisco/Aruba/Fortinet, and Intune. These are kept and labelled honestly until a walk proves them.
- Brands nobody owns stay "Not tested on hardware" (D10).
- Your Ajax alarm reporting through the alarm receiver (R7) and SNMP on your UniFi switch (R10), both skipped in the Phase A walk.
- **The Milesight cloud integration's signature check.** This is a separate, older integration, not in the audit. It checks an `X-Msc-Request-Signature` header that Milesight's public webhook pages don't describe. It needs checking against a real Milesight cloud webhook, logged for Phase E.
- **Hub syslog/NetFlow ingest.** Its existing test (`Phase5…collector ingest isolates site hub syslog…`) fails, and Phase A doesn't touch that code. It is the audit's "never called" item, fixed or removed in Phase B.

## 7. Progress

| Phase | State | Commit | Gate | Stephan's walk | On main / .com |
| --- | --- | --- | --- | --- | --- |
| Finish line (this file) | Approved by MASTER, 30 Oct | 8b2cc254d | — | — | — |
| A · Honest and safe | Built (A1–A8); gate running | see git log | | | |
| B · Windows app and hubs | Not started | | | | |
| C · Core screens | Not started | | | | |
| D · Alerts | Not started | | | | |
| E · Safety kit | Not started | | | | |
| F · Home-Assistant layer | Not started | | | | |
| G · Network tools | Not started | | | | |
| H · Access and clock-ins | Not started | | | | |
| I · Obli fixes things | Not started | | | | |
