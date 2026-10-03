# P09 reporting validation

Owned checkout: `codex/emar-p09-reporting`. This receipt is not a production readiness claim.

## Initial focused run

The frozen PHP source was `9bfb5f988` plus the pending governance/reached-filter/SAC pairing follow-up, before the PDF test-double repair. The run included:

- `tests/Feature/Emar/UnifiedMedicationReportsTest.php`
- `tests/Unit/MedicationEventFingerprintTest.php`
- `tests/Feature/Emar/MedicationEventRecorderTest.php`
- `tests/Concurrency/Emar/MedicationEventConcurrencyTest.php`

Actual result: **1 failed, 16 warning-marked, 2 passed; 522 assertions; 668.15 seconds**. All warnings were the missing checkout `.env` file reported by phpdotenv. The failure was a generic PDF test double returned from a method requiring `Barryvdh\DomPDF\PDF`, which prevented the multi-person rendering race check from reaching its assertion. The fixture now uses that concrete mock type. A local ignored `.env.testing` contains only `APP_ENV=testing`; it copies no operational environment or credentials. A rerun has not yet verified these repairs.

The initial recorder-only run was **2 passed, 8 warning-marked; 362 assertions; 305.77 seconds**, with no failed/skipped tests. Warning-marked tests are not reported as clean passes.

All clinical fixtures use the existing isolated per-process MySQL test database guard. The shared heavy-command lock had an owner-label parsing defect; Main requested no new heavy runs while the overlapping runs finish. No further command is queued by this worker.

## Integration still to verify

The reporting worker owns reconciliation with the combined P05 review, P06 stock lot, P07 controlled register and P08b error source contracts after merging the integration branch into this checkout. Browser, combined frontend checks and final integration are owned by Main. These have not yet been verified here.
