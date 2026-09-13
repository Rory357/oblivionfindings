# W11 mailbox worker interruption recovery

11 September 2026. W11 / F03 / F05 / E05 / E10 partial evidence; not whole-package acceptance.

## Confirmed defect and implementation

The baseline actual-process test finished with one failure after 92 assertions: killing a mailbox worker before canonical commit left a physical copy behind an unclassified reservation. Both providers otherwise recovered canonical work and acknowledgements. See `w11-worker-interruption-baseline-tests.txt` and `it_71ecdcdc462e491a.diagnostic.jsonl`. All 14 postflight isolation checks passed, including exact disposable-schema absence.

Migration `2026_09_11_000021_bind_mailbox_attachment_reservations.php` adds nullable immutable receipt, source-file, connection, configuration-version and claim-token bindings to the existing storage intentions. New scanned mailbox copies capture these bindings under the canonical transaction. Recovery uses the existing bounded cleanup service, locks the original writer before receipt/source/intention records, protects committed files, and revokes an expired matching writer before authorizing deletion. Required audit failure rolls back classification and writer revocation. Missing or conflicting source evidence requires reconciliation. Unclassified legacy reservations remain protected; there is no disk scan, age-based promotion, retention decision or quarantine purge.

Changed implementation: `ItScannedEmailAttachment`, `ItInboundAttachmentStaging`, `ItAttachmentStorageIntent`, `ItAttachmentStorageIntentService`, `ItAttachmentStorageService`, `ItMailboxPollState`, the additive migration, and existing Operations guidance. Operational instructions are in `docs/runbooks/it-attachment-storage-recovery.md`.

## Verified evidence

- `w11-worker-interruption-recovery-tests.txt`: focused test exit 0; token `it_b9426aa60058458f`; 1 test, 137 assertions, 183 seconds from execution start to finish in its diagnostic JSONL. All 14 postflight checks passed, including exact schema absence.
- `it_b9426aa60058458f.worker-interruption.json`: four actual live-child terminations, Microsoft and Gmail each before and after canonical commit. Pre-commit abandoned copies reach `deleted`; post-commit copies remain `attached`. Every recovered canonical file is preserved, original messages are acknowledged once through the ledger, replay creates no additional ticket, and the orphan list is empty.
- The same test verifies active-writer deferral, classification audit failure and retry, legacy preservation, mismatched hash and conflicting canonical-path refusal, missing-source preservation, and cleanup after original connection removal. These additional boundaries are test assertions, not separate browser journeys.
- `w11-interruption-ui-tests-final.txt`: 25 tests across Operations and mailbox recovery/quarantine components passed in 3.91 seconds. Initial run retained in `w11-interruption-ui-tests.txt`: one obsolete copy expectation failed; 24 passed. The expectation was updated to assert protection of unclassified reservations.
- `w11-interruption-regression-tests.txt`: exit 0, token `it_b1a44f6ff1064e82`, 58 tests / 661 assertions / 220 seconds; mailbox state, cleanup authorization/evidence, Operations and cleanup command validation. All 14 postflight checks passed, including exact schema absence.
- `w11-interruption-cleanup-command-tests.txt`: exit 0, token `it_bc9b7d80770d4289`, 1 real-commit command test / 74 assertions / 177 seconds. Existing shared limits, scheduler/run evidence, failures and retries remain functional. All 14 postflight checks passed, including exact schema absence.
- `w11-interruption-build.txt`: exit 0, 3m24s. Asset manifest `a3da74abe29220ea6fb05da857a5c18414b5ef9d628513688081ae9784b22912`, entry `app-DwqXogvZ.js`. Focused Pint check passed. `w11-interruption-source-hashes.json` records 15 relevant source hashes and confirms all 10 protected design sources match the original baseline.

All provider traffic and scanning in these tests are isolated synthetic fixtures. No real provider acceptance or production scanner readiness is claimed. The deterministic expiry-during-read regression simulates time passing after SQL selection; it is not evidence of an actual lock wait.

## Remaining verification

Working-database migrations 000017–000021 remain unapplied. Full W11 still requires the remaining settled/merged/related-work policy journeys and complete E10 review; operational scanner, retention, restore and real-provider dependencies remain separate. The Codex desktop application's self-close cause remains unproven; this application worker test does not diagnose or fix that desktop crash.

## Desktop browser observations and cleanup

Actual in-app tab 27 used `http://127.0.0.1:8766/it/setup?tab=operations` against owned schema `oblivion_it_draft_browser_fa19d4c835ae4072`. Runtime identity is captured in `w11-interruption-browser-runtime.json`; mail was array, queue sync, provider configuration cleared and CSRF bypass false. Desktop remained 1235×856 without any resize. Screenshots and accessibility trees were inspected inline in the tool conversation; no saved PNG artifact is claimed.

- Synthetic `w06-audit@demo.test` has `it.manage` plus `audit.viewAny`: the page displayed the updated interruption/quarantine guidance and all three counts, each zero in this fresh empty storage-intent fixture. No completed automation was invented: each schedule reported “No verified check”.
- `w06-tech@demo.test` lacks audit permission: the same guidance/readiness remained visible, but the count list was absent and the explicit access notice was shown. This was rechecked on the final build after the shared header correction below.
- `w06-requester@demo.test` received the rendered 403 Forbidden page from the direct Operations URL, then successfully returned to `/it`, showing its five permitted requests and no Setup navigation.
- Keyboard verification found a shared Find-palette focus defect; it was fixed and reverified, along with the previously recorded shared rail arrow-key gap. See `w27-header-keyboard-results.md` for exact scope and sequence.

Final build `w27-rail-build.txt` exited 0 in 3m20s, manifest `f2834ff231cc6ce5521e7838ac2dfd1c62a089bb29203bf4bd1e27fd1b2ce0dc`, `app-CE5taJm1.js`. Reviewed asset refresh preserved the same database, session and server PID 26484; preview/applied JSON are recorded. Final runtime identity is `w27-rail-browser-runtime-final.json`. Final fingerprint: `a88ba388b60546be2f70b104eb7f46b3eb5c97be75854649488f941cb61762eb`.

Owned tab 27 was closed; user tab 3 was preserved. Cleanup session 67635 exited 0 (`w11-interruption-browser-cleanup.txt`), and independent `w11-interruption-browser-postflight.json` confirms exact schema and owned-directory absence. No runtime, build or test remains active. These browser observations verify the guidance, permission boundary and shared keyboard correction; the actual interrupted-worker recovery and audit-failure retry are backend proofs, not browser fault-injection claims.
