# PKG-02B resumed technical audit — 26 September 2026

Status: correction candidate under final verification. Claude's implementation is already merged; Main's substantive review now concerns that merged state plus these corrections. Main's final review and integration of corrections remain outstanding.

## Recovered state and authority

- Remote fetched on 26 September: local `main`, `origin/main` and the dirty Main checkout were at `fa7b5291988cebfb6beaa6e6e10c6c660fb2a959`.
- The old Designer checkout `5b0a/oblivionfindings` remains at `71dfa1a90a6160c49b2b2dfe9908361b0694b2b8` on `codex/pkg-02b-vehicle-profile-design`. It was not reset, merged or cherry-picked.
- Corrections and verification use a new managed checkout, `pkg02b-final-audit/oblivionfindings`, branch `codex/pkg02b-final-audit`, based on that verified main revision.
- Claude session `abcf584d-5e86-4f1f-9fbf-27c851acbd2f` has the exact title **Continue PKG-02B vehicle profile: decisions, calendar, audit**. Its user messages, implementation commits and follow-up evidence were recovered independently of the pause checkpoint.
- The current Main checkout's uncommitted programme records and BUILD handoff are read-only inputs. They are not included in correction commits.
- Current executor proof: `gpt-6-astra`, `xhigh`, turn `01a0dcd1-49fb-7a43-87ea-cc99e6343639`, rollout timestamp `2026-09-26T08:24:59.174Z`.

## User decisions retained

1. Versioned Registration/WoF/CoF/RUC **Not required** with a visible tick box and recorded reason; no fuel-type inference.
2. `fleet.vehicles.viewAllSites` permits fleet-wide vehicle oversight while booking, driver, trip/location, Finance and Maintenance records retain their own access boundaries.
3. An authorised manager may assess their own clean check where no approved check rule applies, with a reason. This does not waive the independent release requirements for a safety restriction.
4. Provision the internal Fleet safety signal source; preserve intentionally disabled existing configuration.
5. Self-confirmation of bookings is intended for emergencies, with an audited justification. Independent approval remains a separate transition.
6. Finance decides vehicle review requests in Finance, without needing Fleet access; requesters cannot decide their own Finance request.
7. Daily checks are immutable versioned observations, never a readiness gate. Issues retain their Maintenance reporting path.
8. Stephan will assign Fleet Manager roles; the recovered session states development has no live clients. This does not turn synthetic fixture rules into approved operating configuration.

## Preservation before reconciliation

Original Designer material: 817 files, 59,536,438 bytes. Comparison with the Main checkout: 479 byte-identical, 270 differ only in line endings, six have substantive changes, 62 are Designer-only (mostly original built previews plus superseded frontend foundations and scoped test configs).

All 817 original files were archived and each archive member's SHA-256 checked against its source. Recovery archive: `C:/Users/steph/.codex/visualizations/2026/09/21/01a0c2bb-fcff-7cb1-8bab-882d84477c6c/pkg02b-preservation/designer-originals-20260926.zip`; SHA-256 `e4b414487ff93fe94ad23b7e0951c1a195150433898ff68e44c56b5b90878371`. Adjacent `designer-inventory.json` contains each path, size, source hash, Main hash and classification. The six substantive source/guide differences and original foundations were accounted for against Claude's preservation commit and current implementation. Superseded scoped configs and original built previews remain recovery evidence; none was overlaid blindly on Main.

## Defects corrected

- Fleet Alerts omits the canonical `queclink_fleet` source even though vehicle alerts reach Control Room through it.
- Appointment updates read a fresh work-order version rather than the version opened by the user; concurrent edits can overwrite later plans. An early retry branch also accepts changed content with a reused key.
- Calendar unavailable-period Undo fetches the latest version before restoring old values, so it can overwrite another person's subsequent edit.
- Calendar reminder snooze/reschedule has no compensating Undo path; appointment reschedule also lacks Undo despite the shared calendar rule.

The corrections also cover:

- Multipart uncertain-result retry retains its original FormData, HTTP method, URL and key; a file upload no longer silently becomes a JSON retry.
- Appointment receipts persist actor, fingerprint (including files), previous internal plan and resulting version. Stale edits, changed-content key reuse and source-derived existing work without its opened version conflict. Undo compensates only the exact actor/receipt/version through Maintenance.
- Reminder Undo restores prior action text/time/state, checks latest event, actor/version and current owner/source access, and preserves history. Unavailable-period Undo uses its captured resulting version.
- Browser testing found Undo could expire while a success modal blocked the toast. Appointment/reminder success dialogs now contain inline Undo; a short toast is offered when closing. Both inline paths were exercised against persisted records.
- Pending/processing outbox delivery remains **Waiting for Control Room**, without premature triage actions. Retry updates the dialog. Canonical `queclink_fleet` events appear in Fleet Alerts.
- Check assessment finds an active restriction through its original report as well as a direct check link; cross-vehicle denial and independent release remain.
- Document create/replay/edit/replace/archive/download and projections retain Finance, booking, service-completion and unavailable-period source boundaries. Decided Finance evidence stays immutable through vehicle APIs.
- Source-owned reminders retain restrictions before Fleet/All Tasks/Site Calendar projection and direct mutations/replay; a private Finance evidence reminder cannot disclose its title/notes through another surface.
- The CG power event uses **Vehicle power alert**, not an unsupported low-voltage claim. Unknown values remain unknown.
- Excel is now a real `.xlsx` workbook, with typed dates/numbers, totals, filter/freeze rows, literal untrusted text, brand styling and embedded PNG route sketches. Route-image selection works for Excel. PDF no longer prints an invalid total page count of zero.
- Export scores honour dismissed/disputed reviews even without event detail. Audit is written after successful report generation and records route-image provenance.
- Header date/search overlap at narrower desktop widths is fixed. Geofence selection no longer claims linking changes all monitoring settings to inactive.

## Verification

Tests use isolated per-process MySQL schemas under `oblivion_findings_pkg02b_final_test`, verified local autoload, forced testing environment, empty test dotenv, no `.env` or config cache, null outbound queue/broadcast, array mail/cache/session. Browser writes use only `oblivion_findings_pkg02b_final_browser`, cloned from synthetic fixtures. Main's operating database was not migrated, seeded or reset. `preflight-final.json` records isolation/profile/TestCase hashes.

Runs overlap; do not add them as unique tests:

- Baseline: 175 tests / 3,875 assertions, dotenv warnings and one rollback allowlist failure. Failure evidence retained; the package allowlist and isolated empty dotenv were corrected.
- Main package run: 142 tests / 3,270 assertions, one stale power-label assertion. Corrected and verified in affected reruns.
- Export/review rerun: **26 tests / 887 assertions passed**.
- Adjacent Maintenance/rollback/booking privacy/alerts: **38 tests / 1,202 assertions passed**.
- Reminder privacy, calendar, workspace and reports: 53 tests / 1,192 assertions, 52 passed; one test fixture reached a permission-level 403 before the expected source-level 404. Its manager fixture was corrected and is rerun separately.
- Frontend after inline Undo: **149 tests / 12 files passed**. TypeScript, scoped ESLint, Pint and Prettier passed. Production Vite build passed in 3m10s; existing >500KB chunk warning is a performance advisory.
- Final privacy rerun and current-navigation verification are recorded in the completion addendum.

Actual Chrome page zoom was set by the user to **125%**, with DPR 1.25 and 1536 CSS-pixel width captured. Later the user expanded the window to 2752 CSS pixels, still DPR 1.25. IAB separately tested 1280/1440 desktop layouts, expanded/collapsed sidebar, light/dark appearance and 390px mobile width. Recorded views have no document-wide horizontal overflow. IAB viewport and light appearance were restored. Keyboard Enter opened section search; Tab focused the filtered Mileage result and Enter navigated there.

Persisted browser actions: policy expiry changed to 29 November with linked renewal reminder 30 October; appointment 09:45 → 09:50 → Undo restored 09:45; reminder action changed then Undo restored it. User selected the native downloaded PDF after the extension chooser remained blocked; Save created **AUDIT-NATIVE-UPLOAD**, displayed Waiting for virus check, and withheld download. No fake clean-scan result was injected.

A real isolated outbox job delivered synthetic signal 1 to **CR-2026-0007 / ControlRoomAlert 15**. UI triage persisted. Maintenance creation correctly refused a fixture Site without coordinator/backup. Successful handoff transitions and access denial are covered by automated tests; a live operating queue/hardware feed is not certified.

Native Chrome PDF and Excel downloads succeeded for 23–24 September: two trips, 13.8km, 27 minutes. pypdf/openpyxl independently read the files; Excel has four sheets, two embedded PNG images and totals independently recalculated to 13.8/27. Poppler rendered the PDF. Workbook cell rendering checked layout/formulas but omitted imported drawings; image bytes and OOXML anchors/relationships were checked separately. Native desktop Excel rendering is not claimed. Fixture report branding is unconfigured and falls back to Laravel/default purple; configured branding is tested separately. Long event lists may continue onto another PDF page.

Full synthetic captures/reports live in the task-owned visualization `pkg02b-final-audit` directory; selected evidence accompanies this report. Capture 35 is a failed early Undo attempt, superseded by successful 36. Captures made during loading or affected by Chrome full-page cropping are not counted as visual proof.

## Scope, design and authoritative sources

Approved v13 candidate `56781fb5239a1b5a65fe69be015f782e15f4b665ea7a3c203bb00ca355090181`, manifest `C537D72D3617A05467C3A9FC91CA9A9E7B422FCF30E7D1C87A687D2145874C30`. Frozen v1–v13 evidence and Revision 10 master were not edited. Later recovered user decisions supersede literal older mockup copy. Production does not import preview fixed dates/IDs, synthetic policy/store or localStorage catalogues.

- **Readiness/identity:** VehicleReadinessService composes authoritative compliance, check, odometer, service and restriction evidence. Asset owns identity/responsibility; private scanned AssetDocument publication owns the photo. Unknown/stale evidence is not a pass. Browser verified readiness and its next action.
- **Compliance/RUC/mileage:** FleetVehicleComplianceRecord points at immutable versions. Applicability/reasons, RUC purchased distance, dashboard observations/corrections and tracker estimates stay distinct. Browser exercised list/cards, expiry editing, renewal timing and disabled stale cross-check; tests cover evidence, distance, month/date/timezone and readiness transitions.
- **Service/checks:** schedules persist month/km intervals, owners/reminders/evidence. Completion/history stays Maintenance-owned. Versioned checklist definitions and submitted runs retain original questions/answers/files; amendments do not overwrite submissions. Assessment/retest/release remain separate and daily checks non-gating. Templates, schedule choices/wizard and history states were rendered; automated tests cover positive transitions the restricted browser fixture cannot complete.
- **Calendar/bookings/custody:** five views (Month/Week/Day/Agenda/Timeline), context-specific actions and keyboard alternatives were inspected. Appointments persist as Maintenance plans; estimates are estimates; reminders never reserve the vehicle. Canonical booking events retain independent approval versus reasoned emergency self-confirmation, conflict/current-readiness checks, checkout/return/custody and unavailable periods. Tests cover concurrency, retry and access; browser reschedule/Undo verifies the actual work-order path.
- **Documents/Finance:** AssetDocumentSet owns metadata/revisions/expiry; AssetDocument owns private bytes/scan state. One renewal identity survives replacement. Finance owns records/review decisions; vehicle links cannot post or approve. Native upload and expiry workflow were exercised; Finance decisions and private-file routes were tested.
- **Maps/geofences/telemetry:** shared Security & Devices geofences retain canonical ownership; linking does not silently enable monitoring. Greyscale filled map, compact context actions, hover stats, source/time/stale states and shared picker were rendered. Demonstrated locations are historical synthetic fixtures.
- **Trips/insights/Control Room:** telemetry, trip points, driver confirmations and human event reviews retain provenance. Journey list/cards/table and selected point update were verified; driver search works. Person scoring is withheld when identity/policy/evidence is insufficient. Fleet threshold is not labelled a verified road limit. FleetSignal/outbox → ControlRoomAlert → triage → canonical Maintenance is traceable and tested.
- **Exports:** inclusive Auckland ranges/filters and role/Site/privacy apply to both on-screen records and downloads. Personal/restricted trips cannot expose locations/routes. PDF uses DejaVu Sans for Unicode; Excel keeps untrusted strings literal. Branded exports operate on authorised persisted records, not preview fixtures.

## Operating prerequisites and provider limits

Actual roles/Sites, coordinator/backup, approved checklist content, compliance/RUC sources, readiness/release and scoring policy, thresholds, reminder ownership, queue workers/recovery/scheduler, scan provider/private-storage operations and organisation report branding must be configured. Synthetic fixtures grant none of these approvals. Intentionally disabled signal sources remain disabled.

**Street-map backgrounds in saved reports remain a user-authorised follow-up.** The user initially required them, then reported no provider/key and said to record it as something to look at. Explained that this is a street-map picture saved in PDF/Excel, separate from the existing interactive map. Current exports clearly label recorded-route sketches. No account/key/paid service was created and no private coordinates were sent to a new provider.

[OSM public tile policy](https://operations.osmfoundation.org/policies/tiles/) does not make public tiles an unrestricted export backend. OSM data may underpin a suitably hosted/licensed service. [Geoapify static maps](https://apidocs.geoapify.com/how-to/maps/create-static-map/) is an option; Google requires the organisation's appropriate Maps setup and terms review. Provider/hosting, attribution/terms, credentials and a real configured export check remain necessary. Public Overpass was not silently treated as an operational speed-limit service; unknown road limits remain unknown, with explicit manual evidence/fleet thresholds.

[GV500CG official evidence](https://queclink.com.br/wp-content/uploads/2025/02/GV500CG.pdf) does not establish direct ECU VIN/DTC/dashboard odometer reading from this power-only OBD variant. Unsupported values remain unknown. Suspected collision requires supported protocol/firmware/provider evidence and approved alert settings; synthetic tests do not certify live crash, DTC or voltage feeds.

## Integration and worktree disposition

Claude's integrated preservation/build/UI/checkpoint commits are `e9432132d`, `5891b62e3`, `89b6c9062`, `a8233846d`; later decisions/calendar `beffbb811`, `3a1c216ec`; Finance queue `f92a6169a`; daily checks `4a26610e7` / merge `88ffdaef5`; legacy file boundary `4fd8e621e`, `ed07faae2` / merge `47d89ffbd`. Adjacent `ea4ad1c97`, `1865c32da`, `6ea08a674` remain. Full commit identities are captured alongside this packet.

A fresh fetch found published Main **4ea64c547ed85a5b7504e59599db351f6eba7deb**, five navigation commits beyond audit base fa7b52919. The dirty local Main remains at fa7b52919. Reconciliation must preserve the new seven-entry permission-aware navigation and leave dirty Main untouched.

- `5b0a`: original Designer is preserved. It is the calling checkout and not an attached managed artifact; no invented archive identity will be used.
- `pkg02b-final-audit`: managed correction checkout remains in use for verification/Main review. Archive with the managed tool after integration and stopping its processes.
- Claude `nifty-fermi-50967a`: initial PKG-owned clean detached a8233846d, already integrated; verify no active process before retirement.
- Claude `sharp-feynman-28fae8`: subsequently used for broader programme work; retain. Other trees and dirty Main are unrelated and untouched.

## Completion addendum

Exact correction/integration commits, final focused rerun, Main review result and managed archive disposition will be recorded before declaring completion.
