# W09 merge command concurrency — 10 September 2026

Implemented and focused real-worker verification passed. W09/B07/E08 remain partial; this does not replace the outstanding browser recovery and file/approval cases or W27 full acceptance.

Added tests/Concurrency/It/ItTicketMergeCommandConcurrencyTest.php and tests/Support/It/merge-command-concurrency-worker.php; registered the standalone invocation in tests/Concurrency/It/README.md. No application, migration, design-guide or provider configuration change for this test slice.

One standalone test runs three rounds, each with two actual PHP workers behind a held, ordered canonical ticket-pair lock: same UUID submit/submit, opposite-direction distinct merges, and submit/cancel for the same UUID. Both workers must reach attempt barriers and remain running before the parent releases the locks; timestamps must bracket release. The test checks one receipt/canonical outcome, exactly one source and no cycle, version increments, merge audit/event counts and retained public/internal message IDs, authors and audiences. Worker schema and file barriers are bounded to the exact parent random token, with array mail, sync queue, disabled broadcasts and notification fake. Only the reviewed wrapper parent imports/removes its schema.

Initial run67218, token it_4820396fe3f7435e: Pest2/wrapper1, failed after16assertions/178.34s on an incorrect test query for event ticket_id. Canonical events use subject_type/subject_id; the assertion was corrected without modifying product behavior. Initial postflight all14guards true and schema absent. Failure log preserved in w09-merge-concurrency-backend.txt.

Final run78703, token it_bd4c82120c29467f: Pest0/wrapper0. Diagnostics show Test Passed, Test Finished **82assertions**, and normal runner/application completion at07:53:31UTC (preparation began07:50:29UTC). One test passed. All14 postflight guards true, exact schema absent. See w09-merge-concurrency-backend-final.txt and it_bd4c82120c29467f.diagnostic.jsonl. The diagnostics-enabled console omitted the usual pretty test summary; counts are taken from actual PHPUnit events. Pint and PHP syntax checks passed. No assertion of which nondeterministic cancellation order won is made; the test requires both workers agree on the same committed-or-cancelled outcome and its corresponding persisted invariants.

Source hashes are in w09-merge-focus-concurrency-source-hashes.json. Run this target independently through the isolated wrapper at W27, sequentially with other committed-transaction targets. Standard Feature tests do not run it.
