# Deferred until the approved mockups are built

Stephan, 3 Oct 2026: "please start only focusing on getting the new mockups in we can deal with the rest after".

Everything below is real but not part of a mockup build. It waits until the 14 approved packages are in, unless it blocks a package or is a P0 safety or privacy defect in code being changed anyway.

## Paused work
- **Respite times stored as UTC** (session "Fix respite times saved as UTC instead of NZ"). PAUSED at WIP `f24690a76` on `claude/bold-pare-bed009`: 21 files, with the new `RespiteWallClock` converting on save and the code that relied on raw storage moved to NZ days. Gates still to run: Pest (tests/Feature/Respite and others), tsc and Vitest. Read-only local count: 1 booking and 1 stay, neither affected. Backfill proposal in `docs/respite-timezone-backfill.md`, not run.
  - The booking, request and extend inputs save NZ wall-clock time as UTC (`RespiteBookingController.php:92,187`).
  - The fix is to convert input times. A backfill of existing rows needs Stephan's decision.
  - eMAR Away is NOT affected: it reads system-written stay times.

## Left over from the P01 foundation (Lane C closing summary)
- The mobile API `todays_summary` widget (`MedicationAlertService::getGlobalDashboardWidgets`) counts `dose_times` on the UTC day. Move it to the projection's day totals.
- `MarScheduleService::statusForDose()` has no callers; delete it.
- Rounds' stored counters (`administered_count` … `missed_count`, `total_medications`) are written but never read; drop them in a cleanup.
- The profile calendar shows unrecorded doses only for today ±3 NZ days. That's the existing clutter rule.
- Self-managed doses map to "upcoming" in `listStatus`; this changes with P03.

## UI and design backlog
- Legacy `PageHero` stat pills don't enforce `href` (157 files). Enforce it as pages migrate to `PageHeader`.
- `FleetCompactHero` (2 fleet map pages) paints an unfloored brand band and has 3 stats with no link.
- Some band-slot content still uses the `text-primary-foreground` class; it's safe through the scoped redefinition. An explicit sweep plus a page-wide guard can come later.

## Decisions for Stephan's end review (live data / policy)
- **Recruitment hires:** their personal email is probably stored as work_email, so it's published in My HR. Read-only query: join on `candidate_id` and compare `lower(trim())` of work_email with the candidate's personal_email. Clean up or not?
- **Settings › Profile phone mirror:** before the fix, it may have overwritten or blanked HR work phones. The audit-log query can find them. Clean up or not?
- **Away from leave:** switched off (`MEDICATION_AWAY_FROM_LEAVE`) until leave has approve, withdraw and returned actions with times.
- **Away from hospital stays:** deferred until admission and discharge clinical event types exist (that needs a designed form).
- **Overdue alerts:** under the approved v5 defaults, managers stop getting overdue alerts unless the safety net fires.
- **Office staff managing orders without a shift:** needed before the P04 build.
- **Paper reconciliation (P10):** build it or not?
- **Header band amendment:** PAGE_HEADER §3 sky floor V2.

## Site actions (Stephan, on the TEST server)
- Save the PIN rules once; staff then set their PINs.
- Run `php artisan emar:backfill-dose-slots --dry-run`, then run it for real.
- Reseed so the demo team leads and clinical lead exist.

## Found during the P01 UI build (Lane C, 3 Oct)
- **Privacy, triage after the mockups:** client photos are served from the public disk (`Client.php:105-108`, `Storage::disk('public')->url`, appended to every serialised Client).
- Settings copy says "recording is never blocked" (`MedicationSettingsRegistry.php:244`), but EMS:786 requires a reason outside the window.
- The registry ranks an allergy "confirm" value (:185) that `MedicationSafetyPolicySettings` doesn't accept.
- Unused columns: `client_medications.covert`, `self_administered`, `photo_path`. Unused components: `ClientAllergyBanner.tsx`, `prn-sheet.tsx`.
- A duplicate TilePicker in `pages/sites/_dialog-shared.tsx:260`.
- The rostering ShiftCtxItem menus have no disabled state.
- The MedicationError form has no request id, so it isn't idempotent.
- Offline capture fields are kept only in audit metadata, not on the dose record.
- **Same raw-save bug elsewhere** (found by the respite session; offered to Stephan as task chips):
  - H&S `RestraintController::storeEvent` saves datetime input raw.
  - The portal shift 'date' is the UTC date, so a morning NZ shift is grouped under the previous day.
- **Profile Audit tab:** no controlled-medicine filter (`ClientController:929-945`). Privacy; found by P02.
- **Old ClinicalObservation dose-vitals notes** name controlled medicines in plain text. New copies stop at P02-6; whether to redact the old rows is Stephan's call.
