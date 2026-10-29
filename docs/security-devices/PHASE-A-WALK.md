# Phase A walk-through: honest and safe

This is Stephan's check, on his home network, before Phase A merges. It's the plan's Phase A script, with his 30 Oct answers applied (GOAL.md §4 and §6).

**Where:** the Phase A copy of Oblivion runs on this PC, on your home network. The build session starts it and gives you the address (for example `http://127.0.0.1:8790`). You sign in as usual and type your own password.

**Before you start (one-off, needs your OK):**
- **Camera trust table.** Phase A adds one new table, `camera_endpoint_trusts`. Your local database needs it before step 6. Say "OK to migrate local" and the build session runs that one migration. It only adds the table and changes nothing else.
- **Logins for steps 6 and 7.** Steps 6 and 7 use logins you type yourself: your Protect API key, and an SSH login. Nobody else types them.

Tick each step if what you see matches **Expect**.

| # | Do this | Expect |
| --- | --- | --- |
| 1 | Open **Security & Devices › Setup › Supported devices**. | Ajax, Arrowhead, Bosch, DSC, ICT Protege, Inner Range Integriti and Paradox each show **View only**. UniFi Network shows "Built from vendor docs", with no "PoE port power-cycle" or "Device restart". Nothing says "Tested on real hardware". |
| 2 | Open an Ajax alarm device. If there isn't one, add a test device first: maker "Ajax Systems", model "Hub 2 Plus", kind alarm panel. | No **Arm** or **Disarm** button anywhere on the page. |
| 3 | The build session runs `php artisan security-devices:sia-dc09-check` and shows you the result. | Every reference report says **Read OK** with an **ACK**. The encrypted "still here" report gets an **Encrypted ACK**. The two reports whose keys aren't published say "CRC OK, needs its key". The last line says every report was read. |
| 4 | **After the merge, on the .com:** follow `ops/supervisor/README.md` steps 1–4. | The alarm receiver shows `RUNNING` again within a minute of being stopped. Settings › Server readiness shows "Alarm receiver: Running". (No MQTT on the .com: its listener comes with your central private broker, R8.) |
| 5 | **On this PC only, never the .com:** a small test broker (aedes) runs from the build session's scratch folder. It is reachable only from this PC (127.0.0.1), needs a login, and is removed after the walk. MASTER approved this on 30 Oct. The build session caches the settings (`php artisan config:cache`, so the password must come from config), starts the MQTT listener, sends one reading, restarts the broker, then sends another. (Your real UG65 and Mosquitto on your home address come in Phase E.) | Both readings arrive. After the restart the listener's log says "The MQTT broker closed the connection. Reconnecting." and then "Connected …" within a minute. (Your central broker on the private network comes in a later phase, R8.) |
| 6 | Open one of your UniFi Protect cameras in Oblivion (Protect API key saved for the Site), then **Capture snapshot**. | Before trusting: "This camera isn't trusted yet" and a certificate fingerprint, and no picture. Open `https://<your console address>` in your browser and check its certificate fingerprint matches. Then **Trust this camera** › **Trust this camera**. Capture again: you get the picture. Your other Protect cameras don't ask again. |
| 7 | The build session sets up a test network device with you: maker "MikroTik" (backups only run for MikroTik, Cisco, Aruba and Fortinet), address = a machine on your home network with SSH on (for example the .com server at 172.16.0.232), a Site whose network includes that address, and an SSH login that you type yourself. Then **Capture backup**. | The backup stops with "This device's SSH key isn't trusted yet … no login was sent", and the device page shows the key with **Trust this key**. Trust it. Then change the device's address to a different SSH machine and back up again: **refused**, "this device's SSH key has changed". |
| 8 | Open **Settings › Obli › Remote support**, then **Test relay**, with no relay saved. | "No relay set up. Save a relay address and its shared secret, then test again." It never says "Verified". |
| 9 | Look at the hub build on GitHub (the build session gives you the link). | Green, including "Hub runtime (amd64) loads SNMP: snmp-ok". |
| 10 | **Milesight push, private only (D23).** First, the build session sends a test sensor reading to the local copy from this PC, with the Kauri House secret. Then without it. | With the secret: accepted. Without it: refused (401). |
| 10b | **After the merge, from outside your home network** (for example your phone on mobile data, not home Wi-Fi), send the same push to `https://oblivionfindings.com/webhooks/milesight-gateway/<site>`. From inside your network the public address loops back and looks private, so this half needs an outside connection. | Refused with "Not Found" (404), even with the right secret. |

**Skipped at your request (30 Oct):** your Ajax alarm reporting in (R7), and reading your UniFi switch over SNMP (R10). Both are listed as not proven on hardware.

**Afterwards:**
- If every step matches, tell MASTER "Phase A walk passed".
- If something doesn't, say which step and what you saw. The build session fixes it and you walk that step again.
