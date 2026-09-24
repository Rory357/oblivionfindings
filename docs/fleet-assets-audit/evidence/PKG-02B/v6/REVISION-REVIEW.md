# v6 review — 22 September 2026

Synthetic design preview only. No Main message, production change, commit, push or implementation release.

## Changed

Trip history now matches the location workspace more closely: a bounded landscape map, narrow side inspector, smaller score/metric blocks and collapsed source details. Replay remains tied to recorded points; sparse joins are labelled illustrative.

Export opens a canonical 720px modal with shared calendar controls, PDF/Excel choice, date-range summary, optional maps/events, progress, ready state and explicit empty/error states. Both formats include branding and three-day examples. The PDF has a summary and journey pages; Excel separates trips, events and embedded journey maps. Export respects the current filters across pagination.

Driving workflow additions close the largest v5 gaps: actual driver confirmation/handover, confirm/dismiss/dispute with retained original evidence, reviewed scores, policy versioning, coaching acknowledgement/completion, acknowledgement deadlines and backup escalation, and linking an assessed alert to existing Maintenance work. Synthetic source controls demonstrate mapped limits and approved temporary overrides.

## Verified

- Confirmed Jamie for the sampled journey; related events inherit the confirmed identity.
- Dismissed overspeed event changes the example score from 82 to 90 while retaining the original 68 km/h observation.
- Coaching was created, acknowledged and completed; it updates the shared reminder record.
- Manual speed limit remains inactive pending review, then applies after approval with evidence. The resulting Control Room example retains its limit/source snapshot.
- Advancing the demonstration clock escalates an unacknowledged alert once to its backup owner.
- Required evidence cannot be bypassed with an empty upload list.
- Date controls update the evaluated observation date; an out-of-validity source becomes unknown rather than carrying forward an earlier road limit.
- Replay terminates at its last recorded point. Driver filters remain through profile navigation.
- Three-day export includes three journeys, 16.9 estimated km and 55 minutes. A one-day range selects one trip. Reversed and empty ranges block generation.
- PDF renders as four readable pages with greyscale basemap pictures, route overlays, logo and event details. Long detail text can continue to a new page.
- XLSX opens through two parsers; dates/numbers are typed, distance/time formulas have correct cached totals, all XML parts parse, and four embedded PNGs are present (logo plus three maps).
- At 1100px the trip map and inspector fit without horizontal overflow; light and dark layouts inspected.
- Five calendar views select successfully, and right-clicking a day exposes the booking menu.
- Final targeted TypeScript check and Vite build pass. Seven reducer checks pass.

The first workflow screenshots precede the later trip-layout/export changes; their reducers were unchanged. The manifest identifies the final review candidate. This is targeted verification, not every permission/state combination or production integration acceptance.

## Remaining implementation work

No live provider, tracker decoder, notification transport or production Control Room record is connected. The approved operational scoring/response policy, effective-date activation, durable audit store, driver/custody reconciliation, device swap/counter reset and delayed-report replay require implementation. Universal geofence/checklist governance needs canonical ownership, permissions, impacted-profile review and concurrency handling. Production trip retrieval and report pagination must support more than these three fixtures.

The workbook renderer used for review imports image metadata but does not paint imported pictures. Their PNGs and drawing relationships/anchors were validated; native Excel visual acceptance remains outstanding. In-app download event detection timed out despite a valid downloadable report link; copies extracted from that exact link are supplied. PDF uses a standard Latin font and transliterates macrons; production reports should use the approved Unicode brand font.
