# Respite times saved as NZ wall-clock: what a backfill would cover

Status: **list only. Nothing here has been run against any server.** Whether
and how to correct old rows before go-live is Stephan's decision. On 31 Oct
2026 he chose no backfill for now (the .com demo data is fictional).

## What was wrong (fixed 31 Oct 2026)

Respite forms post `datetime-local` and `date` values: NZ wall-clock with no
offset. Until this fix the controllers saved them as typed. The app timezone
is UTC, so a Mon 10:00 to Wed 10:00 NZ booking was stored as 10:00Z, which
reads back as 22:00 or 23:00 NZ. Anything comparing those columns with `now()`
was 12–13 hours out.

The fix converts NZ wall-clock to UTC once, when it is saved
(`App\Support\Respite\RespiteWallClock`, a thin wrapper on
`App\Support\WorkerClock::toUtc`). A value that already carries an offset
keeps its instant. The Respite restraint quick form now sends NZ time with its
offset and the server refuses a time without one, as the H&S restraint form
does (`RestraintCommandValues::instant`).

The fix does not touch existing rows. Rows written before it still hold NZ
wall-clock in a UTC column.

## Tables and columns that would need a backfill

| Table | Columns | Written by (before the fix) |
| --- | --- | --- |
| `respite_bookings` | `start_at`, `end_at`, `funding_approved_at` (when typed) | booking form and update; approve and promote copy the request's times; extend stay sets `end_at` |
| `respite_booking_requests` | `requested_start`, `requested_end`, `funding_approved_at` (when typed) | workspace pop-up and client-profile flow (date only, so NZ midnight), request page (datetime-local) |
| `shifts` where `respite_booking_id` is set | `starts_at`, `ends_at` | copied from the booking by `RespiteShiftSync` (create, reschedule, extend) |
| `respite_calendar_events` | `start_at`, `end_at` | copied from the booking by `RespiteCalendarProjector` |
| `respite_stays` | `actual_end` (extended stays only), `bed_hold_until` | extend stay, bed hold |
| `respite_medication_reconciliations` | `first_dose_due_at` | admission medicines check pop-up |
| `respite_complaints` | `received_at`, `acknowledged_at` | complaint pop-up |
| `respite_communication_logs` | `occurred_at` | comms log page |
| `respite_resource_allocations` | `start_at`, `end_at` | resources page |
| `respite_referrals` | `received_at` (only when typed; the default `now()` is right) | referral form |
| `client_incidents` where `respite_stay_id` is set | `occurred_at` | incident pop-up (it posted the browser's "now" as NZ wall-clock, so these sit 12–13 hours in the future) |
| rows copied from those incidents | `notifiable_incidents.occurred_at`, `data_breach_logs.discovered_at`, `hs_events.occurred_at`, `control_room_alerts.triggered_at` | `RespiteStayController::recordIncident` and `IncidentJourneyService` |
| `restraint_events` where `stay_id` is set and there is **no** `restraint_command_receipts` row (`source_type = 'event'`, `source_id` = the event) | `started_at`, `ended_at` | the Respite workspace quick form only; the stay page and H&S register already sent offsets |

Not affected: `respite_stays.actual_start`, the discharge `actual_end`, and
every column stamped with `now()` (`*_recorded_at`, `approved_at`,
`reconciled_at`, `submitted_at`, `created_at`). Date-only columns
(`period_start`, `note_date`, `expected_availability_date`) were always right.

The family portal had no stored-data problem: it only read shift and booking
times with UTC day boundaries, which is now fixed in the code.

## How to find the rows

Set `@fix_deployed_at` to the deploy time of this fix and look only at rows
created (or, for extended stays and rescheduled bookings, updated) before it.
A typed NZ value is usually a whole minute; a correctly stored daytime NZ
instant lands at 17:00–08:59 UTC, while the broken values sit at the typed
hour (often 06:00–20:59) or exactly midnight (a date-only input).

```sql
SET SESSION time_zone = '+00:00';
SET SESSION TRANSACTION READ ONLY;
SET @fix_deployed_at = '2026-11-01 00:00:00';  -- replace with the real deploy time (UTC)

SELECT 'bookings' AS rows_of, COUNT(*) AS rows_before_fix,
  SUM((SECOND(start_at) = 0 AND HOUR(start_at) BETWEEN 6 AND 20) OR TIME(start_at) = '00:00:00') AS probably_wall_clock
FROM respite_bookings WHERE deleted_at IS NULL AND created_at < @fix_deployed_at
UNION ALL
SELECT 'requests', COUNT(*),
  SUM((SECOND(requested_start) = 0 AND HOUR(requested_start) BETWEEN 6 AND 20) OR TIME(requested_start) = '00:00:00')
FROM respite_booking_requests WHERE deleted_at IS NULL AND created_at < @fix_deployed_at
UNION ALL
SELECT 'respite incidents in the future of their submission', COUNT(*),
  SUM(occurred_at > submitted_at)
FROM client_incidents WHERE respite_stay_id IS NOT NULL AND created_at < @fix_deployed_at
UNION ALL
SELECT 'quick-form restraints', COUNT(*), COUNT(*)
FROM restraint_events e
WHERE e.stay_id IS NOT NULL AND e.created_at < @fix_deployed_at
  AND NOT EXISTS (SELECT 1 FROM restraint_command_receipts r WHERE r.source_type = 'event' AND r.source_id = e.id);
```

## If a backfill is wanted before go-live

1. **Deploy the fix first** and note the deploy time. From then on new writes
   are UTC and the set of bad rows stops growing.
2. **Demo or test data only:** skip the backfill and reseed
   (`RespiteDemoSeeder` now writes 10 am NZ as UTC). This is the cheapest.
3. **Real data:** a reviewed artisan command with `--dry-run` and `--apply`,
   not a migration (deploys can run migrations unreviewed).
   - Convert each value in PHP: `Carbon::parse($raw, 'Pacific/Auckland')->utc()`,
     which is right for each date's daylight saving. Avoid MySQL
     `CONVERT_TZ(..., 'Pacific/Auckland', ...)`; it returns NULL when the
     server has no timezone tables.
   - Move linked rows with their parent: the booking's shift (only where it
     still equals the booking's old times), its calendar event and its
     request; an extended stay's `actual_end` with its booking `end_at`; an
     incident's notifiable incident, breach log, H&S event and alert.
   - Skip any column changed after the deploy (the `AuditableChanges` history
     on bookings and calendar events shows this).
   - Write each change (table, id, column, old, new) to a ledger, refuse to
     shift a row already in it, and use it as the rollback.
   - Dry run shows counts per table and 20 sample rows (old, old as NZ, new,
     new as NZ) for Stephan to check before `--apply`. Back up the database
     first.
