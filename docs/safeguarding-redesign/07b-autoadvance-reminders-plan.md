# Safeguarding Redesign — Step Plan: 07b — Auto-advance (W5) + reminders (W9) + W10 verify

## 0. Identity
- **Step:** 7b — close out the lifecycle gaps: W5 auto-advance, W9 scheduled reminders, W10 verify
- **New:** `app/Observers/SafeguardingInvestigationObserver.php`, `app/Console/Commands/SafeguardingReviewReminders.php`
- **Edits:** `AppServiceProvider` (register observer), `routes/console.php` (schedule command)
- **Drop refs:** HANDOFF §3 (auto-advance) + §7.5; SAFEGUARDING_LIFECYCLE_PLAN W5/W9/W10; gap A5/A7/E.

## 1. W5 — auto-advance on investigation completion
- `SafeguardingInvestigationObserver::updated`: if `wasChanged('status')` && `status==='completed'` && concern `status==='investigating'` → set concern `action_plan`. (referred_external/other left as-is.)
- Register: `SafeguardingInvestigation::observe(SafeguardingInvestigationObserver::class)` in AppServiceProvider (beside the concern observer).
- Test: PUT investigation status=completed advances the concern investigating→action_plan; a non-completing update leaves it.

## 2. W9 — scheduled review/ack reminders
- `php artisan safeguarding:review-reminders {--days=7}`: counts (a) non-terminal concerns with a `SafeguardingRiskAssessment.next_review_date <= now`; (b) `SafeguardingExternalReport` `acknowledgement_received=false` with `reported_at <= now-days` on non-terminal concerns. `$this->info(...)` + `Log::info('safeguarding.review_reminders', [...])`.
- Schedule: `routes/console.php` daily 08:20 NZ (`->timezone('Pacific/Auckland')->dailyAt('08:20')`).
- Test: seed a due risk review + an old unacked report → `artisan('safeguarding:review-reminders')->expectsOutputToContain('1 risk review')` etc.; exit 0.

## 3. W10 — verify (no code)
- ClosePane already shows the subject-not-informed warning (`!infOk` InfoCard) + the backend close gate warns/soft-blocks. Confirm present; note in PROGRESS.

## 4. Incidents-consistency (§7)
- Mirrors the H&S/Incidents scheduled-job idiom (CheckOverdueInvestigationsJob etc. in routes/console.php) + the observer pattern (SafeguardingConcernObserver). Backend-only; no UI surface.

## 5. Verify
- pint new PHP; `artisan test` safeguarding suite green (+ 2 new tests); tsc/build N/A (no TS). Commit + tick PROGRESS (Step 7 fully done).

## 6. Then Step 8 (final)
- X1 `ClientIncident::safeguardingConcerns()` reverse relation + surface on the incident; X2 Control Room safeguarding quick-actions; X3 concern↔HsEvent↔NotifiableIncident↔alert state sync; NZ authority currency in `SafeguardingExternalReportController` (+MSD-DSS, Whaikaha=monitoring). Then final summary + stop the loop.
