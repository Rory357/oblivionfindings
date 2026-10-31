# Virus scanning for uploaded files (ClamAV)

**Decision (Stephan, 21 October 2026):** install ClamAV on the test and production servers.

## Why this is needed

Uploaded files are **fail-closed**. Referral evidence, consent evidence and IT inbound attachments are held in quarantine until a scanner says they are clean. Without a scanner, nothing is released. That blocks funding verification and any handover that needs documents.

The app runs the scanner binary directly, never through a shell (`app/Services/Files/MalwareScanner.php`):

| Exit code | Meaning | File becomes |
|---|---|---|
| `0` | Clean | Available |
| `1` | Virus found | Rejected |
| Anything else, or timeout | Scanner problem | Stays quarantined |

We use `clamdscan`, which asks the always-running `clamd` daemon to scan. That takes milliseconds, while plain `clamscan` reloads the signature database on every file (10–20 seconds). `--fdpass` hands clamd the open file, so clamd never needs read access to the app's private storage.

The servers run Ubuntu with the app as `www-data` (see `docs/runbooks/inertia-ssr.md`). Run these steps on **each** server: test first, then production.

## 1. Install ClamAV

```bash
sudo apt-get update
sudo apt-get install -y clamav clamav-daemon
```

## 2. Download the virus signatures

The `clamav-freshclam` service updates them automatically from now on. The first download must finish before the daemon will start:

```bash
sudo systemctl stop clamav-freshclam
sudo freshclam
sudo systemctl enable --now clamav-freshclam
```

## 3. Start the scanning daemon

```bash
sudo systemctl enable --now clamav-daemon
sudo systemctl status clamav-daemon --no-pager
```

Wait until the status shows `active (running)`; loading the signatures takes about 30 seconds. Then check that the socket exists and `www-data` can use it:

```bash
ls -l /var/run/clamav/clamd.ctl
```

The Ubuntu default (`LocalSocketMode 666`) lets every local user connect. If the mode differs, set `LocalSocketMode 666` in `/etc/clamav/clamd.conf`, then run `sudo systemctl restart clamav-daemon`.

## 4. Check it works before touching the app

The EICAR line below is the industry-standard **harmless** test "virus". Every scanner reports it as infected.

```bash
printf '%s' 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' > /tmp/eicar-test.txt
echo "hello" > /tmp/clean-test.txt
sudo -u www-data clamdscan --no-summary --fdpass /tmp/eicar-test.txt; echo "exit $?"   # expect: FOUND, exit 1
sudo -u www-data clamdscan --no-summary --fdpass /tmp/clean-test.txt; echo "exit $?"   # expect: OK, exit 0
rm /tmp/eicar-test.txt /tmp/clean-test.txt
```

If you see `exit 2`, the daemon isn't running or the socket can't be reached. Go back to step 3.

## 5. Point the app at the scanner

Add these to the server's `.env`. They are the same for test and production:

```dotenv
REFERRAL_EVIDENCE_SCANNER_BINARY=/usr/bin/clamdscan
REFERRAL_EVIDENCE_SCANNER_NAME=clamav
REFERRAL_EVIDENCE_SCANNER_FD_PASS=true
REFERRAL_EVIDENCE_SCANNER_TIMEOUT=30

CONSENT_EVIDENCE_SCANNER_BINARY=/usr/bin/clamdscan
CONSENT_EVIDENCE_SCANNER_NAME=clamav
CONSENT_EVIDENCE_SCANNER_FD_PASS=true
CONSENT_EVIDENCE_SCANNER_TIMEOUT=30

IT_INBOUND_MALWARE_SCANNER_BINARY=/usr/bin/clamdscan
IT_INBOUND_MALWARE_SCANNER_NAME=clamav
IT_INBOUND_MALWARE_SCANNER_FD_PASS=true
```

Leave `IT_INBOUND_MALWARE_SCANNER_DATABASE` empty: `clamdscan` uses the daemon's database and doesn't accept `--database`. If `.env.example` gains other `*_SCANNER_BINARY` keys later, give them the same values.

Then reload the configuration and restart the queue workers, which keep the old settings in memory:

```bash
cd /path/to/app
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan queue:restart
```

(A normal deploy runs `config:cache` already. The `queue:restart` is what's easy to forget.)

## 6. Check it in the app

1. Open a test referral and upload a small PDF as evidence. It should become **Available** within a few seconds, not "Held for scanning".
2. Files uploaded **before** the scanner was installed stay quarantined. A staff member with document access opens each one's menu and chooses **Scan again**. It is then released, or rejected if infected.

## Keeping it healthy

- `clamav-freshclam` updates signatures several times a day. Check with `sudo systemctl status clamav-freshclam`.
- When clamd is down, uploads are **held, not lost**. They release with "Scan again" once the daemon is back.
- clamd uses about 1–1.5 GB of RAM for its signatures. Allow for it on small servers.
