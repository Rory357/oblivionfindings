# Temporal PRN safety patch — handoff to Main

Prepared 4 October 2026, Pacific/Auckland. Stable commit **`d2b973c334b7736e9aaa740b4e8fbd83ccd5d33e`**, branch `codex/emar-prn-temporal`, managed checkout `C:/Users/steph/.codex/worktrees/emar-prn-temporal/oblivionfindings`. Base: Main **`1ba7c011239817c0553b0ed6dc976303c9d0b174`**. Working tree clean after commit; no push.

The commit owns only `app/Services/MedicationSafetyService.php` and new `tests/Feature/Emar/TemporalPrnSafetyTest.php`. No helper file was needed. EnhancedMarService, scope decisions, P10/P07, grants, schema and UI are unchanged. One operating organisation; queries constrain both canonical person and order and use effective clinical evidence.

## Behavior and required caller contract

- `performSafetyCheck()` now passes its existing clinical `adminTime` to both PRN helpers. `checkPrnLimits()` and `checkPrnInterval()` accept an optional `CarbonInterface`; omitted time still means current time.
- Limits use effective **given** evidence for that person/order with non-null clinical time. The proposed dose's inclusive `[at − 24 hours, at]` window is bounded in UTC, retaining the existing maximum/near-limit threshold and dose-count semantics. Instants match the database's second precision without mutating the caller's NZ time.
- A bounded 48-hour query also checks windows ending at later recorded doses through `at + 24 hours`, inclusive. A sliding window adds the proposed dose virtually once. A later-window breach returns the affected instant/count; it neither writes evidence nor changes a limit.
- Intervals compare signed elapsed time against the chronological predecessor and successor. Exactly the existing minimum gap passes; an existing dose at the same instant fails. No absolute difference or today's latest-dose substitution is used.
- Approved effective corrections replace their originals; pending/rejected/superseded corrections and non-given history do not count.

**Canonical recording callers must resolve replay/dose duplicates first, hold the same canonical medication row mutex across evaluation and insertion, and use the unchanged clinical instant for both.** These helpers are read-side checks, confer no recording authority, and are not an after-insert validator: the proposed dose is virtual. Read-only previews remain advisory. P01 owns any recorder integration and non-given outcome handling; a refusal/withhold must retain existing given-only block behavior rather than becoming a virtual given insertion. Deleted/superseded medicine recording remains held. A safety breach still uses the existing exception/review path, with no new override or grant.

## Verification and next step

Passed: PHP 8.4 syntax checks on both final files and staged `git diff --check`. No application boot, database test, migration, build or other heavy run was launched.

The new test class defines **22 cases** after data-provider expansion: live empty/near/full usage and disabled limits; historical versus today's history; prior historical breach; later rolling-window breach and one virtual dose; inclusive 24-hour edges; both interval neighbors and exact boundary; NZST/NZDT and clock-change instants without input mutation; effective/canonical ownership and corrections; non-given corrections/history; and full safety-check propagation of clinical time. These tests are **written, not yet run**. This worktree has no physical `vendor` copy yet.

Main retains the heavy slot. After granting test permission and preparing the worktree's own physical dependencies and isolated synthetic test database, run `TemporalPrnSafetyTest` plus the relevant existing PRN limit/interval regression tests against the actual adopted source. Confirm the current caller evaluates before insertion under its order lock. P10 should release its unchanged-ordinary-PRN hold only after those integrated checks pass; signed paper/stock/witness authority adapters are separate work.
