# Audit evidence index

This folder supports the 11 September 2026 audit and implementation specification. It is not implementation acceptance evidence. All 24 implementation tasks remain Not started; all 28 acceptance criteria remain Not run.

- dirty-tree-baseline.txt and dirty-tree-final.txt: baseline/final working-tree inventories. Other IT/Fleet/ControlRoom work continued during the audit; it was not edited or reverted by this audit. Do not attribute those differences to Governance.
- asset-and-design-hashes.json and package-check-results.json: exact retained browser asset, selected Governance source and protected design hashes. The final comparison found no hash changes. The tracked Governance/protected paths checked had no diff. A fresh build was not run.
- governance-baseline-tests.txt: the completed pre-implementation Governance feature/unit suite: 162 passed, two failed, 1,474 assertions, 410.15 seconds. The two failures remain baseline findings; they were not repaired during this audit.
- browser-observations.md: personas, steps, visible results, recovery and explicit limitations. Ordinary member, chair, secretary, actual finance-committee member and CEO roles were exercised with normal login. No admin-only acceptance shortcut. The observer role was inspected in source/permission probes, not a complete browser journey.
- runtime-state.json / runtime-bootstrap.txt: historical synthetic fixture identity. The original process was interrupted; the same disposable database was reused after inspection. These files are historical evidence, not proof that a preview or database is still running.
- probe-results.json / timeline-probe-results.json / fixture-extra-result.json: runtime and read-only diagnostic outputs. Probes describe their method and rollback boundaries. B12's timeline crash was confirmed with sparse list keys after filtering pack activity.
- governance-practice-research.md: primary-source New Zealand guidance and D1 voting, D2 privacy and D3 supported-living content decisions. Defaults do not replace the actual governing document or contract applicability.
- source-navigation.txt / design-surface-inventory.txt / surface-specification.md: source navigation, existing UI inventory and required retained-surface field/action specification. Line numbers describe the audited checkout and may move during implementation.
- audit-runtime.php, audit-router.php, audit-probes.php, audit-timeline-probe.php, audit-fixture-extra.php and connection-check.php: one-off diagnostic scripts retained for review. Inspect guards and current runtime before any reuse. The runtime script's initial risk/path/treasurer fixtures were corrected as documented; do not rerun the old fixture blindly. No diagnostic route/auth bypass was added to application code.
- audit-pack.pdf: tiny synthetic pack retained as evidence. No real board material.
- check-audit-package.ps1: package consistency checks only. It verifies finding/task/acceptance traceability, honest initial statuses, local evidence links, protected hashes and selected tracked path preservation. The final run exited 0 with 26 findings, 24 required tasks, 28 criteria and no package gaps. Its first attempt at an empty Git ignore override was corrected to a real empty file; the recorded final result is from the successful rerun.
- cleanup-results.json / audit-cleanup.php: cleanup of the exact owned database and exact private-disk synthetic PDF. The script checked the expected seven synthetic users, exact database identity, file containment and hash before removal. No recursive directory deletion or other database cleanup was performed.

## End-of-audit cleanup

Stopped only the loopback PHP preview session on port 8776 with Ctrl+C; the process exited and a follow-up local-port check found no listener. Restored the temporary Chrome viewport override and closed only the audit-created tab. The isolated oblivion_gov_audit_20260911_9888 database and its private-disk synthetic pack were removed; the supporting evidence PDF remains here. Browser-managed cache/download state and generic framework log/session files were not bulk-cleared.

No application implementation, fresh type/build/frontend test run, live deployment, real communications, production votes or human usability acceptance is claimed.
