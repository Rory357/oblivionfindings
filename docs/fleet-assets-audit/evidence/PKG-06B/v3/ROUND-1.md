# Additional audit round 1 — workflows

Frozen local design revision v3. Based on the completed Vehicle Profile consistency pass v2.

Corrected two consequential gaps: all kit items arriving no longer silently converts an explicitly discrepant receipt into complete custody; a new reported problem no longer automatically links to an unrelated existing brake issue. Discrepancy/dispute needs notes; only explicit complete receipt with all items changes the confirmed custodian. New issue and existing-work follow-up have distinct review destinations and confirmation labels. Failed new-issue submission retains its draft; retry creates one local record. The kit collection carries the current movement reference.

Chromium verified the discrepancy guard, all-arrived discrepancy preserving Mara, complete receipt acknowledging Nia while keeping the hold, and a failed/retried new issue creating a single MW-DEMO-272 alongside MW-271. Four checks passed; no page errors were recorded. Browser results and screenshots are retained here. Synthetic state only; no server workflow or operational acceptance is claimed.

Next round audits documents, lineage and recovery. No implementation is authorised by this iteration.
