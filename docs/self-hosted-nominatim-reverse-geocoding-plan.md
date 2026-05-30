# Self-Hosted Nominatim Reverse Geocoding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a self-hosted OpenStreetMap/Nominatim reverse-geocoding path so Oblivion Findings can display resident tracker addresses without sending coordinates to Google or the public OpenStreetMap Nominatim service.

**Architecture:** Oblivion keeps storing coordinates in `fleet_telemetry_events`, but the queued reverse-geocode job calls a private Nominatim endpoint on the same host or private network. Fresh server provisioning can install/import/start Nominatim automatically when explicitly enabled; regular application deploys health-check and restart services without re-importing map data.

**Tech Stack:** Laravel Artisan commands, queued jobs, HTTP client, Redis queue/cache, Bash provisioning scripts, Linux systemd, Nginx local proxy, PostgreSQL/PostGIS/Nominatim, Geofabrik New Zealand or Oceania OSM extracts.

---

## Current State

- The history UI is already address-first. `IntegrationEventHistoryService` sets `display_location` to `FleetTelemetryEvent.address` and only falls back to coordinates when `address` is null.
- `ReverseGeocodeFleetTelemetryEvent` exists and stores `address`, `reverse_geocoded_at`, and `reverse_geocode_failed_at`.
- `ReverseGeocodeService` currently supports only Google Maps and requires `GOOGLE_MAPS_API_KEY`.
- `config/fleet.php` already has `fleet.maps.reverse_geocode_enabled`, cache TTL, and rate-limit settings.
- `scripts/deploy-server.sh` is an idempotent deploy/provision script and already installs the Queclink listener via `php artisan queclink:install`.
- The live deploy script outside the repo must eventually mirror any deploy-script changes made here.

## Source Constraints

- Nominatim reverse geocoding takes a latitude/longitude and returns the closest indexed OSM object; it may not always be a perfect civic address.
- Nominatim supports smaller regional extracts; Geofabrik provides country extracts including New Zealand.
- Nominatim supports reverse-only imports, but the official docs note this mainly removes search indexes and only modestly reduces disk use.
- The official production deployment path uses a Python ASGI frontend behind systemd and Nginx.
- Do not use `https://nominatim.openstreetmap.org` for automatic tracker backfill or continuous resident tracking.

References:

- Nominatim import guide: https://nominatim.org/release-docs/latest/admin/Import/
- Nominatim reverse API: https://nominatim.org/release-docs/latest/api/Reverse/
- Nominatim production deployment: https://nominatim.org/release-docs/latest/admin/Deployment-Python/
- Geofabrik New Zealand extract: https://download.geofabrik.de/australia-oceania/new-zealand.html

## Approval Decisions

This plan assumes these defaults unless changed before implementation:

- Initial region: New Zealand, using Geofabrik `new-zealand-latest.osm.pbf`.
- Initial import mode: address-focused, reverse-lookup-only service for tracker coordinates.
- Service exposure: localhost/private network only, not public internet.
- Deploy behavior: opt-in automatic Nominatim install on fresh instances via environment flag; regular deploys run health checks and queue workers, not full imports.
- Failure behavior: if Nominatim is unavailable, Oblivion keeps storing coordinates and marks geocoding as pending/failed without blocking telemetry ingest.

## Files

- Modify: `config/fleet.php`  
  Add provider, Nominatim endpoint, Nominatim user-agent/contact email, install flags, region URL, and timeout settings.
- Modify: `.env.example`  
  Document Nominatim provider settings and the fresh-instance install toggle.
- Modify: `app/Services/Fleet/ReverseGeocodeService.php`  
  Convert it into a provider-aware wrapper that keeps existing cache/rate-limit/usage logging.
- Create: `app/Services/Fleet/Geocoding/ReverseGeocoder.php`  
  Small interface for provider implementations.
- Create: `app/Services/Fleet/Geocoding/GoogleReverseGeocoder.php`  
  Move current Google-specific call here.
- Create: `app/Services/Fleet/Geocoding/NominatimReverseGeocoder.php`  
  Call the private Nominatim reverse endpoint and normalize `display_name`.
- Create: `app/Console/Commands/FleetGeocoderStatus.php`  
  Report provider, endpoint, health, pending rows, failed rows, and latest resolved address.
- Create: `app/Console/Commands/FleetReverseGeocodeBackfill.php`  
  Backfill missing addresses safely by queueing existing `fleet_telemetry_events`.
- Create: `scripts/nominatim/install-nominatim.sh`  
  Idempotently install/import/start Nominatim on Linux/systemd hosts.
- Create: `scripts/nominatim/nominatim.env.example`  
  Template for region URL, project directory, import style, and resource settings.
- Modify: `scripts/deploy-server.sh`  
  Add `--install-nominatim`, `--skip-nominatim`, and a normal health-check step.
- Create: `tests/Unit/Services/Fleet/NominatimReverseGeocoderTest.php`
- Modify: `tests/Feature/Queclink/ResidentLocationAddressTest.php`
- Create: `tests/Feature/Fleet/FleetGeocoderCommandsTest.php`
- Optional live-only follow-up: patch `/usr/local/bin/deploy-oblivionfindings-main` on the server after repo changes are merged.

---

## Task 1: Add Provider Configuration

**Files:**
- Modify: `config/fleet.php`
- Modify: `.env.example`

- [ ] Add config keys under `fleet.maps`.

```php
'reverse_geocode_provider' => env('FLEET_REVERSE_GEOCODE_PROVIDER', 'google'),
'reverse_geocode_timeout_seconds' => env('FLEET_REVERSE_GEOCODE_TIMEOUT_SECONDS', 6),
'nominatim' => [
    'endpoint' => env('FLEET_NOMINATIM_ENDPOINT', 'http://127.0.0.1:8088'),
    'user_agent' => env('FLEET_NOMINATIM_USER_AGENT', 'OblivionFindings/1.0'),
    'contact_email' => env('FLEET_NOMINATIM_CONTACT_EMAIL'),
    'auto_install' => env('FLEET_NOMINATIM_AUTO_INSTALL', false),
    'region_pbf_url' => env('FLEET_NOMINATIM_REGION_PBF_URL', 'https://download.geofabrik.de/australia-oceania/new-zealand-latest.osm.pbf'),
    'project_dir' => env('FLEET_NOMINATIM_PROJECT_DIR', '/srv/nominatim-project'),
],
```

- [ ] Add matching `.env.example` entries.

```dotenv
FLEET_REVERSE_GEOCODE_ENABLED=false
FLEET_REVERSE_GEOCODE_PROVIDER=nominatim
FLEET_REVERSE_GEOCODE_TIMEOUT_SECONDS=6
FLEET_NOMINATIM_ENDPOINT=http://127.0.0.1:8088
FLEET_NOMINATIM_USER_AGENT="OblivionFindings/1.0"
FLEET_NOMINATIM_CONTACT_EMAIL=
FLEET_NOMINATIM_AUTO_INSTALL=false
FLEET_NOMINATIM_REGION_PBF_URL=https://download.geofabrik.de/australia-oceania/new-zealand-latest.osm.pbf
FLEET_NOMINATIM_PROJECT_DIR=/srv/nominatim-project
```

- [ ] Run `vendor\bin\pest.bat tests\Feature\Queclink\ResidentLocationAddressTest.php`.

Expected: existing tests still pass because provider defaults should not change disabled behavior.

---

## Task 2: Split Reverse Geocoder Providers

**Files:**
- Create: `app/Services/Fleet/Geocoding/ReverseGeocoder.php`
- Create: `app/Services/Fleet/Geocoding/GoogleReverseGeocoder.php`
- Create: `app/Services/Fleet/Geocoding/NominatimReverseGeocoder.php`
- Modify: `app/Services/Fleet/ReverseGeocodeService.php`
- Create: `tests/Unit/Services/Fleet/NominatimReverseGeocoderTest.php`

- [ ] Write a failing unit test for Nominatim success.

```php
public function test_nominatim_reverse_geocoder_returns_display_name(): void
{
    config([
        'fleet.maps.reverse_geocode_timeout_seconds' => 6,
        'fleet.maps.nominatim.endpoint' => 'http://nominatim.test',
        'fleet.maps.nominatim.user_agent' => 'OblivionFindings/Test',
        'fleet.maps.nominatim.contact_email' => 'ops@example.test',
    ]);

    Http::fake([
        'nominatim.test/reverse*' => Http::response([
            'display_name' => 'Te Kowhai Road, Hamilton, Waikato, New Zealand',
        ], 200),
    ]);

    $address = app(NominatimReverseGeocoder::class)->reverseGeocode(-37.723663, 175.241560, 123);

    $this->assertSame('Te Kowhai Road, Hamilton, Waikato, New Zealand', $address);
    Http::assertSent(fn ($request) =>
        str_contains($request->url(), '/reverse')
        && $request['format'] === 'jsonv2'
        && $request['lat'] === -37.723663
        && $request['lon'] === 175.24156
        && $request->hasHeader('User-Agent', 'OblivionFindings/Test')
    );
}
```

- [ ] Run the test to verify it fails because the class does not exist.

Run: `vendor\bin\pest.bat tests\Unit\Services\Fleet\NominatimReverseGeocoderTest.php`

Expected: failure for missing `NominatimReverseGeocoder`.

- [ ] Add the interface.

```php
namespace App\Services\Fleet\Geocoding;

interface ReverseGeocoder
{
    public function reverseGeocode(float $lat, float $lng, ?int $assetId = null): ?string;
}
```

- [ ] Move the current Google HTTP call into `GoogleReverseGeocoder`.

Keep the same Google request URL and response parsing currently in `ReverseGeocodeService`.

- [ ] Add `NominatimReverseGeocoder`.

Implementation requirements:

- Uses `config('fleet.maps.nominatim.endpoint')`.
- Calls `/reverse`.
- Sends query params: `format=jsonv2`, `lat`, `lon`, `addressdetails=1`, `zoom=18`.
- Sends a real `User-Agent`.
- Adds `email` only when `FLEET_NOMINATIM_CONTACT_EMAIL` is set.
- Returns trimmed `display_name`.
- Logs warning and returns null on non-200, missing result, timeout, or malformed JSON.

- [ ] Update `ReverseGeocodeService` to select the provider.

Provider rules:

- `FLEET_REVERSE_GEOCODE_PROVIDER=google` uses `GoogleReverseGeocoder`.
- `FLEET_REVERSE_GEOCODE_PROVIDER=nominatim` uses `NominatimReverseGeocoder`.
- Unknown provider logs a warning and returns null.
- Existing cache key, rate limit, and `FleetMapUsageLog` behavior stays in `ReverseGeocodeService`.

- [ ] Run the Nominatim provider test.

Expected: pass.

- [ ] Run the existing address tests.

Run: `vendor\bin\pest.bat tests\Feature\Queclink\ResidentLocationAddressTest.php`

Expected: pass.

---

## Task 3: Add Geocoder Status And Backfill Commands

**Files:**
- Create: `app/Console/Commands/FleetGeocoderStatus.php`
- Create: `app/Console/Commands/FleetReverseGeocodeBackfill.php`
- Create: `tests/Feature/Fleet/FleetGeocoderCommandsTest.php`

- [ ] Write a failing feature test for `fleet:geocoder:status`.

Expected behavior:

- Prints configured provider and endpoint.
- Shows whether reverse geocoding is enabled.
- For Nominatim, calls `/status` on the configured endpoint.
- Exits non-zero only when `--fail-if-enabled` is passed, reverse geocoding is enabled, and the provider health check fails.

- [ ] Implement `fleet:geocoder:status`.

Command signature:

```php
protected $signature = 'fleet:geocoder:status {--fail-if-enabled : Fail when enabled provider is unhealthy}';
```

Status fields:

- provider
- enabled
- endpoint
- health
- pending missing-address rows
- failed rows
- latest resolved row timestamp

- [ ] Write a failing feature test for `fleet:reverse-geocode:backfill --dry-run`.

Expected behavior:

- Filters rows where `address`, `reverse_geocoded_at`, and `reverse_geocode_failed_at` are null.
- Requires `consent_blocked=false`.
- Supports `--device=`, `--client=`, `--limit=`, `--retry-failed`, and `--dry-run`.
- Dry run prints the count and queues no jobs.

- [ ] Implement `fleet:reverse-geocode:backfill`.

Command signature:

```php
protected $signature = 'fleet:reverse-geocode:backfill
    {--device= : Device ID to backfill}
    {--client= : Client ID whose active tracker device should be backfilled}
    {--limit=500 : Maximum rows to queue}
    {--retry-failed : Include rows previously marked reverse_geocode_failed_at}
    {--dry-run : Count eligible rows without queueing jobs}';
```

Backfill rules:

- Never queues consent-blocked events.
- Queues `ReverseGeocodeFleetTelemetryEvent` jobs.
- Orders newest first so the UI improves immediately.
- Prints the first and last event IDs it queued.
- Does not perform HTTP calls in the command itself.

- [ ] Run command tests.

Run: `vendor\bin\pest.bat tests\Feature\Fleet\FleetGeocoderCommandsTest.php`

Expected: pass.

---

## Task 4: Add Nominatim Provisioning Script

**Files:**
- Create: `scripts/nominatim/install-nominatim.sh`
- Create: `scripts/nominatim/nominatim.env.example`

- [ ] Add an idempotent Linux/systemd script.

Script behavior:

- Exits 0 with a clear message on non-Linux or non-systemd hosts.
- Requires root or sudo only for package install, PostgreSQL role setup, systemd, and Nginx writes.
- Runs the Nominatim import as the dedicated `nominatim` system user, not as root.
- Reads settings from `/etc/oblivionfindings/nominatim.env` when present.
- Defaults to New Zealand Geofabrik extract.
- Creates `/srv/nominatim-project`.
- Downloads the PBF only when missing or explicitly refreshed.
- Installs the Ubuntu 24 package baseline from the Nominatim docs:

```bash
apt-get update -qq
apt-get install -y osm2pgsql postgresql-postgis postgresql-postgis-scripts \
    pkg-config libicu-dev virtualenv python3-pip nginx curl
```

- Creates the Nominatim virtual environment and installs:

```bash
/srv/nominatim/nominatim-venv/bin/pip install nominatim-db
/srv/nominatim/nominatim-venv/bin/pip install psycopg[binary] falcon uvicorn gunicorn nominatim-api
```

- Imports once only when the Nominatim database/project has not already been initialized.
- Writes systemd socket/service for the Nominatim frontend.
- Enables and starts the service.
- Verifies `http://127.0.0.1:8088/status`.

Required environment template:

```bash
NOMINATIM_REGION_PBF_URL="https://download.geofabrik.de/australia-oceania/new-zealand-latest.osm.pbf"
NOMINATIM_PROJECT_DIR="/srv/nominatim-project"
NOMINATIM_LISTEN_HOST="127.0.0.1"
NOMINATIM_LISTEN_PORT="8088"
NOMINATIM_IMPORT_STYLE="address"
NOMINATIM_REVERSE_ONLY="1"
NOMINATIM_REFRESH_PBF="0"
NOMINATIM_MIN_FREE_DISK_GB="80"
NOMINATIM_MIN_FREE_MEMORY_MB="4096"
```

- [ ] Add guardrails.

The script must not start a fresh import when:

- A project directory already contains an imported database marker.
- Free disk space is below the configured threshold.
- Available memory is below the configured threshold.
- `NOMINATIM_REGION_PBF_URL` is empty.

- [ ] Add clear output for operators.

At the end print:

```text
Nominatim status: systemctl status oblivion-nominatim
Nominatim logs:   journalctl -u oblivion-nominatim -f
Endpoint:         http://127.0.0.1:8088
```

---

## Task 5: Wire Deployment Automation

**Files:**
- Modify: `scripts/deploy-server.sh`

- [ ] Add deploy flags.

```bash
--install-nominatim   Run Nominatim provisioning if enabled and not already imported.
--skip-nominatim      Skip Nominatim health checks.
```

- [ ] Add normal deploy behavior.

Default behavior:

- Do not import OSM data.
- Run `php artisan fleet:geocoder:status`.
- If reverse geocoding is enabled and provider is unhealthy, fail the deploy before declaring success.

- [ ] Add fresh-instance behavior.

When `--install-nominatim` is supplied:

- Run `scripts/nominatim/install-nominatim.sh`.
- Run `php artisan fleet:geocoder:status --fail-if-enabled`.
- Continue with regular Laravel deploy steps.

- [ ] Preserve existing Queclink behavior.

The final deploy order should be:

1. Composer install.
2. NPM build.
3. Migrations.
4. Storage link.
5. `php artisan optimize:clear`.
6. Optional Nominatim provision or geocoder health check.
7. Queclink install/restart.
8. Queue restart.

- [ ] Run shell syntax checks.

Run:

```powershell
bash -n scripts/deploy-server.sh
bash -n scripts/nominatim/install-nominatim.sh
```

Expected: no syntax errors.

---

## Task 6: Enable Safe Backfill For Existing Resident History

**Files:**
- Modify: `tests/Feature/Queclink/ResidentLocationAddressTest.php`
- Use existing: `app/Jobs/ReverseGeocodeFleetTelemetryEvent.php`
- Use new: `app/Console/Commands/FleetReverseGeocodeBackfill.php`

- [ ] Add a test proving backfill improves the history response.

Test shape:

1. Create a resident tracker.
2. Create a telemetry event with coordinates and no address.
3. Assert history returns coordinates.
4. Run the queued job with a fake geocoder returning an address.
5. Assert history returns the address and preserves coordinates.

- [ ] Add an operator command for the live Amelia/device case.

After deployment, the live command should be:

```bash
php artisan fleet:reverse-geocode:backfill --client=9012 --limit=500 --dry-run
php artisan fleet:reverse-geocode:backfill --client=9012 --limit=500
php artisan queue:restart
```

- [ ] Verify after queue processing.

Run:

```bash
php artisan fleet:geocoder:status
```

Expected:

- pending count decreases.
- latest resident tracking history rows have `address`.
- UI timeline displays addresses instead of raw coordinates.

---

## Task 7: Verification Gate

**Files:** no new files unless tests reveal issues.

- [ ] Run focused PHP tests.

```powershell
vendor\bin\pest.bat tests\Unit\Services\Fleet\NominatimReverseGeocoderTest.php
vendor\bin\pest.bat tests\Feature\Fleet\FleetGeocoderCommandsTest.php
vendor\bin\pest.bat tests\Feature\Queclink\ResidentLocationAddressTest.php
vendor\bin\pest.bat tests\Feature\Queclink
```

- [ ] Run existing publish checks touched by this area.

```powershell
vendor\bin\pest.bat tests\Feature\FleetTelemetryIngestTest.php
npm run types
npm run build
vendor\bin\pint.bat --dirty --test
git diff --check
```

- [ ] Browser verify after deployment.

Check:

- `/fleet-assets/resident-tracking`
- `/fleet-assets/resident-tracking/history/9012`
- client profile Location tab

Expected:

- current location still renders when no address is available.
- once backfill jobs finish, timeline rows show address text.
- coordinates remain available in exported data or secondary metadata.

---

## Task 8: Production Rollout

**Files:**
- Repo deploy files from earlier tasks.
- Live script: `/usr/local/bin/deploy-oblivionfindings-main` after main is pushed.

- [ ] Merge and push after verification.

- [ ] On each new Oblivion instance, configure:

```dotenv
FLEET_REVERSE_GEOCODE_ENABLED=true
FLEET_REVERSE_GEOCODE_PROVIDER=nominatim
FLEET_NOMINATIM_ENDPOINT=http://127.0.0.1:8088
FLEET_NOMINATIM_AUTO_INSTALL=true
FLEET_NOMINATIM_REGION_PBF_URL=https://download.geofabrik.de/australia-oceania/new-zealand-latest.osm.pbf
```

- [ ] Run fresh-instance provisioning.

```bash
sudo ./scripts/deploy-server.sh --install-nominatim
```

- [ ] Patch the live webhook deploy script to run the same geocoder health check.

The live script should not import OSM data on every webhook deploy. It should only check:

```bash
php "$APP_DIR/artisan" fleet:geocoder:status --fail-if-enabled
```

- [ ] Backfill Amelia's current device history.

```bash
php artisan fleet:reverse-geocode:backfill --client=9012 --limit=500 --dry-run
php artisan fleet:reverse-geocode:backfill --client=9012 --limit=500
```

- [ ] Confirm service and UI.

```bash
systemctl is-active oblivion-nominatim.service
curl -fsS http://127.0.0.1:8088/status
php artisan fleet:geocoder:status
```

Then open `/fleet-assets/resident-tracking/history/9012` and confirm timeline rows show address text.

---

## Risks And Mitigations

- **Import time and disk use:** Nominatim imports are heavy. Mitigation: use New Zealand extract first, preflight disk/RAM, and never re-import on normal deploys.
- **Address precision:** OSM reverse geocoding returns the closest indexed object, not guaranteed house-level truth. Mitigation: show address as "nearest address" internally and retain coordinates in metadata/export.
- **Sensitive location data:** Resident coordinates must not go to public services. Mitigation: localhost/private endpoint only, no public Nominatim automatic calls.
- **Operational drift:** Each new instance needs the same service. Mitigation: keep provisioning scripts in repo and make deploy health fail when geocoding is enabled but unhealthy.
- **Service outage:** Telemetry ingest must not fail if Nominatim is down. Mitigation: queued job marks failure/pending; UI falls back to coordinates.

## Done Criteria

- A fresh Linux/systemd Oblivion instance can run one command to install the app and Nominatim.
- Normal deploys start/check the Nominatim service without re-importing OSM data.
- `FLEET_REVERSE_GEOCODE_PROVIDER=nominatim` resolves resident tracker rows through the private endpoint.
- Existing tracker history can be backfilled with an Artisan command.
- Resident tracking and client Location tab display addresses when available and coordinates only as fallback.
- Tests, typecheck, build, Pint dirty check, and `git diff --check` pass.
