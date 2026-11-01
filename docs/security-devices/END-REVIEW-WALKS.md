# S&D: what only Stephan's own kit and network can prove (end review)

MASTER collects these for Stephan in one list at the end (1 Nov 2026). Nothing here may say "Tested on real hardware" until Stephan has walked it on his own kit. "On a test stand-in" means a local test server took the device's place on this PC, with test logins only.

## Phase A · Honest and safe (walked by the build session, 1 Nov 2026)

Evidence for every step (screenshots, outputs, logs): `sd-phase-a-walk/` in MASTER's notes folder.

| Walk step | Proven so far | Still to do on Stephan's kit or network |
| --- | --- | --- |
| 6. Protect camera trust | **On a test stand-in.** A self-signed HTTPS "Protect console" on this PC, with a test API key. It showed the trust prompt with the fingerprint and no picture before trust; the picture after trust; no second prompt for the other camera on the same console. The console never received a password header. | One of Stephan's Protect cameras, with his own Protect API key (he types it), checking the fingerprint against his console in his browser. |
| 7. SSH key trust | **On a test stand-in.** A throwaway SSH server on this PC, with a test login. No login was sent to an untrusted key. After trusting it, the backup ran `/export terse hide-sensitive`. After a key swap: "Backup refused: this device’s SSH key has changed…". The walk found and fixed a bug: real SSH backups had never connected (`6ddfc1451`; on main since 29 Oct). | A real MikroTik, Cisco, Aruba or Fortinet device on Stephan's network, with an SSH login he types, to confirm a real backup and a real key change. |
| 4. Alarm receiver kept running | Pending: after the merge, on the .com, with root. | Stephan follows `ops/supervisor/README.md` steps 1–4. |
| 10b. Milesight push refused from outside | **Locally:** a public sender relayed through the proxy gets 404, even with the right secret. | After the merge: send the push to `https://oblivionfindings.com/webhooks/milesight-gateway/<site>` from outside his network (his phone on mobile data, or a cloud session). Expect 404. |
| 5. MQTT listener | **On a local test broker** (aedes, 127.0.0.1, test logins, removed afterwards). | His UG65 and the central private broker come in Phase E (R8). |
| R7. Ajax alarm reporting in | Skipped at Stephan's request. | His Ajax hub reporting to the alarm receiver. |
| R10. SNMP on his UniFi switch | Skipped at Stephan's request. | A hub reading his switch over SNMP. |

## Phase B · Windows app and hubs (built, not walked yet)

Every step below needs a real computer or network; none is proven yet.

- Run the per-Site installer on a clean Windows 11 PC: More info → Run anyway → Next, Next, Finish. Expect it connected, then waiting for approval.
- Approve the hub. Expect no work and no logins before approval; a first scan after.
- Run the same file on a second PC. Expect "This installer was already used".
- Rename a fresh file. Expect it to ask for the 8-character code.
- Do a staff install as a standard Windows user. Expect no admin prompt.
- Publish 1.3.1, then a broken 1.3.2. Expect it installed, then rolled back within about 5–6 minutes, with IT told.
- Raspberry Pi one command (`curl -fsSL https://<server>/h/<CODE>/linux | sudo bash`). Expect nothing from apt; waiting for approval.
- Remove a hub, then uninstall. Expect its saved logins can't be read.
- Sensor relay through a hub with the link pulled for 10 minutes. Expect the readings to arrive with their real times.
