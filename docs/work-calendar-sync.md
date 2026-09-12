# Staff work calendar sync

My Calendar uses the shared Site Calendar experience. Its data remains the signed-in worker’s shifts, medication rounds, approved leave and tasks.

Settings → Calendar Sync → Staff work calendars configures a single organisation’s automatic shift export. Only users with `integrations.manage_secrets` can change the setup, check access or request a run. This is separate from shared site calendar OAuth connections.

The duplicate Operations Calendar Sync screens, controller and legacy per-user job have been removed. Navigation opens `/settings/calendar-sync`; the old index and create bookmarks redirect there with the same admin permission. Legacy write endpoints are gone. Historical `calendar_syncs` storage is retained without an active application workflow; it is not a source of credentials or staff enrolment for the new integration.

## Admin setup

1. Have IT configure one of the providers below and run the normal Laravel scheduler and queue worker.
2. In Calendar Sync settings, choose the provider and the staff work email domain, then save with sync paused.
3. Check one current staff work calendar. This read-only check creates no events; the first sync confirms write access. Each mailbox must be included in the provider’s approved access scope.
4. Enable automatic sync and save. Use “Sync staff calendars now” for the first run, then inspect the last successful sync and any error. The schedule runs every 15 minutes.

Only canonical current approved staff are enrolled, using `hr_employee_profiles.work_email`. The domain must match exactly. Missing addresses, duplicate addresses, personal login emails, inactive staff and former staff are excluded. New eligible staff are included automatically on later runs. Changes to credentials invalidate the saved access check.

## Google Workspace

Create a service account and enable Calendar API access. An administrator must grant domain-wide delegation for `https://www.googleapis.com/auth/calendar.events`. Install these values through the server’s normal secret-management process:

- `WORK_CALENDAR_GOOGLE_SERVICE_ACCOUNT_EMAIL`
- `WORK_CALENDAR_GOOGLE_PRIVATE_KEY` (PEM; literal `\n` separators are supported)

The service signs a short-lived assertion with the current staff work address as the delegated subject. It never uses worker sign-in tokens. Personal Gmail accounts do not support this organisation setup.

[Google’s service-account and delegation documentation](https://developers.google.com/identity/protocols/oauth2/service-account#delegatingauthority).

## Microsoft 365

Create an application in the organisation directory. Grant calendar read/write application access, preferably using Exchange Online Application RBAC scoped to the approved staff mailboxes. Avoid a simultaneous unscoped Entra calendar grant that defeats the RBAC scope. Install:

- `WORK_CALENDAR_MICROSOFT_DIRECTORY_ID` (the provider’s directory GUID)
- `WORK_CALENDAR_MICROSOFT_CLIENT_ID`
- `WORK_CALENDAR_MICROSOFT_CLIENT_SECRET`

The service uses the client credentials grant and `/users/{work-email}/calendar/events`, with no worker sign-in. The directory identifier belongs to Microsoft authentication; the application remains single-tenant.

[Microsoft’s Exchange application RBAC documentation](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac).

## Sync behaviour and operational limits

- Exports “Work shift”, its start/end and a link to My Calendar. Entries are private and have no attendees. Client names, locations, care notes, medication and leave data are never exported by automatic sync.
- Maintains assigned, frontline-visible shifts within approved sites from the last 7 days through the next 90 days. Unpublished shifts are excluded when roster publishing is enabled.
- Updates rescheduled shifts and removes this integration’s obsolete copies after cancellation, reassignment, withdrawal or a work-address change. Offboarded staff copies within the maintained window are removed if provider access to the old mailbox still exists. Removal failures remain retryable and are reported.
- Tracks remote IDs and payload hashes. Google uses deterministic operation IDs; Microsoft uses `transactionId` plus a persisted extended property to recover interrupted creates before retrying. It never modifies unrelated events.
- Keeps historical copies beyond the seven-day maintenance window. Pausing leaves all existing copies in place.
- A single lock prevents overlapping exports and configuration changes during a run. Keep the queue worker timeout/retry configuration appropriate for this job’s 30-minute maximum, with retry-after longer than the timeout.
- Changing provider or domain while tracked copies exist is blocked: reconcile the old copies before migrating the integration.

Apply only `database/migrations/2026_09_13_180000_create_work_calendar_event_links.php` when introducing this feature to an existing local database. Credentials are not installed, permissions are not granted and exports are not enabled by the migration.

## Local verification — 13 September 2026

- Verified the real Herd routes `/my-calendar`, `/calendar` and `/settings/calendar-sync` in Chrome. The old Operations index redirects to Settings, and no current navigation points to the removed page.
- Checked desktop and 390 px mobile layouts, all five views, September-to-January year navigation and Today. The date is readable against the header; mobile date-navigation targets measure 44 × 44 px with no horizontal overflow.
- Final Vite build and focused ESLint checks passed. Two personal-feed adapter tests passed.
- 37 feature tests passed for staff sync, consolidation, shared-site settings and OAuth. The Google provider test was moved to a database-free unit test with a public test-only RSA fixture; its signed delegation and conflicting-create retry checks passed separately. HTTP calls in these tests are faked.
- The repository-wide TypeScript check still reports unrelated existing errors in IT wizard/knowledge tests and the governance calendar adapter. It reports none in the changed personal-calendar or work-calendar settings files.
- Local credentials are unconfigured for both providers. No real Google/Microsoft mailbox access check or external export has been performed, and automatic staff sync remains disabled.
