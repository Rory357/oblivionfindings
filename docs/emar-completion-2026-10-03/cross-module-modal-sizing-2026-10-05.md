# On-call card and shared modal date/time sizing — 5 October 2026

## Request and changes

Stephan requested the house profile's card layout for on-call contacts and the
same date/time sizing correction in the other modules' modals.

- Settings now uses the existing profile meter composition: label and count on
  the top row, configured-contact initials beneath, and a short caption. The
  count remains **configured houses / visible houses**, rather than implying
  that a person is currently rostered or reachable. Fixed/backup people come
  from the existing authorised settings data, are deduplicated, and use the
  shared avatar stack. An empty or roster-only configuration uses a phone icon;
  no people are invented. The card still opens Alerts & access → On-call contacts
  and respects the existing view permissions.
- Shared DialogContent and SheetContent provide compact date/time presentation
  to their children. WizardShell, SettingsModal and WorkspaceWizard inherit it
  through the existing dialog shell. DatePicker, TimePicker and DateTimeField
  use that context unless explicitly overridden. Page filters retain their
  existing presentation, and eMAR's explicit compact opt-ins continue to work.
- Compact triggers and the clear action have a 44 CSS pixel minimum height.
  Timestamp pairs use one purpose/timezone legend, short visible Date/Time
  labels, full accessible names, and stack by available form width. The same
  calendar, clock, exact-minute input, draft cancellation and paired-value
  behaviour remain in use.

## Scope checked

A source inventory found 67 shared date/time field call sites in 31 non-eMAR
files (excluding the internal DateTimeField composition). This includes page
filters as well as modal forms; it is not a count of changed or browser-tested
modals. The inherited sizing covers these form families:

| Family | Forms using the shared controls |
| --- | --- |
| Assets | Registration, custody/actions, checks, document dates |
| Fleet and maintenance | Work orders, checks, appointments, bookings, service records and reminders, mileage, compliance and document dates |
| Transport | Requests, rescheduling, planning, trip exports and follow-ups |
| Locations and boundaries | Zone schedules, geofence rules and report/history dialogs |

The change concerns oversized shared picker presentation. Native date inputs
and calendar-range workflows retain their existing contracts; this is not a
replacement of every date-entry implementation across the application.

## Evidence

Verified in the synthetic preview at `http://127.0.0.1:8765`, served by the
integration checkout. No operational records or configuration were saved.

- `/fleet-assets/transports/overview`: Request transport → Date & time.
  Before: 68.59px triggers and 144.89px empty timestamp groups. After: 44px
  triggers and 95.5px groups at desktop width.
- At 320 × 800 CSS pixels, transport fields stack to 238.8px wide, with no
  horizontal field or footer overflow. The 294.4px dialog remains inside the
  viewport; its step rail intentionally scrolls horizontally. The clock is
  288px wide with no horizontal overflow and reachable actions.
- Calendar selection followed by exact-minute entry retained **6 Oct 2026,
  1:17 pm**. Cancel left an empty time unchanged; Enter applied the time without
  submitting the request. Closing exercised the existing dirty-draft guard;
  the unsaved test draft was discarded.
- `/fleet-assets/assets`: Add asset → Details. Purchase and warranty triggers
  changed from 68.59px to 44px without individual call-site edits.
- `/emar/settings`: the on-call card shows `0/4` in the top-right corner and
  “4 houses to configure”; its click opens the correct section. At 320px the
  card is 229px wide and has no content overflow. Populated and deduplicated
  contact states are covered by component tests; the preview has no configured
  on-call contacts.
- Browser error log was empty. Viewport overrides were reset afterwards.

TypeScript, scoped ESLint and formatting passed. Targeted tests passed
**3 files / 17 tests**; the complete frontend suite passed **537 files / 3,632
tests** in 403.84s. The initial build passed in **3m52s** and browser checks
used `app-VflmPlll.js`. The final build passed in **4m36s**; the browser loaded
`app-B35hAEeR.js` and measured the clear-date/time action at **44px high**.

Verification logs: `storage/logs/cross-module-date-time-{targeted,types,lint,frontend,build,build-final}-20261005.log`.

Screenshots are saved in the local `emar-resume-20261004` proof folder under
the chat's visualizations directory: `transport-compact-date-time-desktop-final-20261005.jpg`,
`transport-compact-date-time-phone-final-20261005.jpg`, and
`emar-oncall-profile-card-desktop-20261005.jpg`.

These UI corrections do not close the separately recorded clinical release
gates. Draft PR #16 remains the integration target.
