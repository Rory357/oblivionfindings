# Supervisor programs on the server

Supervisor keeps Oblivion's long-running listeners going and restarts them if they stop. Deploys don't
install or change these files. Whoever has root on the server copies them in once.

| File | Programs |
| --- | --- |
| `oblivion-monitoring-listeners.conf` | SNMP traps, syslog, flow (already installed on the .com) |
| `oblivion-monitoring-workers.conf` | Monitoring queue workers (already installed on the .com) |
| `oblivion-sd-listeners.conf` | `oblivion-sd-alarm-receiver`: the Security & Devices alarm receiver (new in Phase A) |

## Security & Devices alarm receiver (Phase A)

**Who:** Stephan, or whoever has root on the server. **When:** after Phase A is merged to main and the
.com has deployed it. MASTER says when.

The alarm receiver answers alarm reports (SIA DC-09) from alarm panels. It is off until it is switched
on in `.env`. Settings › Server readiness then shows an "Alarm receiver" check.

Sign in to the server (`ssh oblivionfindings.com`), then paste these one at a time.

### 1. Back up `.env`

Keep the backup outside the app folder. Never leave files in `/var/www/oblivionfindings`, or deploys stop.

```bash
mkdir -p ~/env-backups && chmod 700 ~/env-backups
cp /var/www/oblivionfindings/.env ~/env-backups/.env.before-sd-listeners-$(date +%Y%m%d-%H%M)
```

### 2. Switch the alarm receiver on

Open `.env` and add these lines at the end:

```bash
nano /var/www/oblivionfindings/.env
```

```
SIA_DC09_ENABLED=true
SIA_DC09_BIND_HOST=127.0.0.1
SIA_DC09_PORT=51100
```

`127.0.0.1` means only this server can reach the receiver. That's enough for the Phase A checks. To let
alarm panels on the network report in, use `0.0.0.0` instead, and allow TCP and UDP port 51100 in the
server's firewall. That opens a port, so it's your call, made when a panel is ready to report.

### 3. Install the program and start it

```bash
sudo cp /var/www/oblivionfindings/ops/supervisor/oblivion-sd-listeners.conf /etc/supervisor/conf.d/oblivion-sd-listeners.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status oblivion-sd-alarm-receiver
```

Expect `RUNNING`. Its log is `/var/log/oblivion/sd-alarm-receiver.log`. The first line says
`Alarm receiver listening on 127.0.0.1:51100 (TCP and UDP).`

### 4. Check that it restarts by itself (Phase A walk step 4)

Stop the process the way a crash would, by its exact process number, then look again:

```bash
sudo supervisorctl pid oblivion-sd-alarm-receiver
sudo kill <the number printed above>
sleep 10
sudo supervisorctl status oblivion-sd-alarm-receiver
```

Expect `RUNNING` again, with a new process number and an uptime of a few seconds. Within a minute,
Settings › Server readiness shows "Alarm receiver: Running".

### To undo

```bash
sudo supervisorctl stop oblivion-sd-alarm-receiver
sudo rm /etc/supervisor/conf.d/oblivion-sd-listeners.conf
sudo supervisorctl reread
sudo supervisorctl update
```

Then remove the three `SIA_DC09_` lines from `.env`. Afterwards, `git -C /var/www/oblivionfindings status --porcelain`
must print nothing.

## MQTT sensor listener (Phase A, item A4)

Not on the .com. Stephan's call (30 Oct, GOAL.md R8): one central MQTT broker beside Oblivion, reachable
only over the private network (SD-WAN/MPLS), never from the internet. The listener's supervisor
program is added in the later phase that builds that broker. Until then, open no MQTT port and install
no broker on this server.
