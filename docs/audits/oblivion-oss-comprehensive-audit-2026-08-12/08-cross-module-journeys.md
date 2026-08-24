# Cross-module journeys

All eight mandatory journeys were independently source-cross-reviewed in Pass 8 (8/8). The preserved impersonation pass sampled 11 actor classes, and a later direct-login pass at historical browser-evidence pin `ad19f994a280835d039d1a31ebdcb05778733c5a` sampled the synthetic Clinical/Medication Lead on Health & Clinical and eMAR at all four required viewports, bringing bounded actor-entry coverage to 12/12. No domain form was submitted. None of the eight journeys was executed end to end, so the state maps below remain source-backed rather than runtime acceptance.

## J1 — Hire to first shift

**Flow:** HR candidate → offer/intake → identity/profile → onboarding/credentials/competency → availability → eligibility → assignment.

**State machine:** Candidate; offered; accepted; identity-linked; active profile; prerequisites incomplete/complete; eligible; assigned.

**Required hand-off/completion evidence:** Fail closed on identity collision, onboarding and eligibility; preserve an explicit first-shift owner.

**Retained finding roots:** WF-EMAIL-IDENTITY-CONVERGENCE, WF-AVAILABILITY-LIFECYCLE, WF-ELIG-FAIL-OPEN, MED-COMP-01.

**Benchmark disposition:** Frappe HR and Timefold; no match for supported-living medication eligibility as a whole.

**Failure/recovery acceptance:** wrong-site/person/source IDs disclose nothing; validation preserves entered data; concurrent or replayed actions create one effect; background failure stays visible and retryable; close/reopen and correction preserve lineage; next owner and due state remain explicit.

**Runtime status:** Blocked—representative actor account, safe fixture and write authority were not available. The mapped task scripts and controller actions remain the reproducible start point for later acceptance.

## J2 — Availability to payroll

**Flow:** Availability/leave → published roster → attendance → timesheet → approval → payroll run → bank settlement/reconciliation.

**State machine:** Available/unavailable; assigned; clocked; draft; submitted; approved; exported; accepted/rejected; settled; reconciled.

**Required hand-off/completion evidence:** Local-time fatigue and leave must alter eligibility; every pay source needs unique provenance and correction.

**Retained finding roots:** WF-FATIGUE-TIMEZONE, WF-TIMESHEET-CLIENT-REASSIGN, WF-ATTENDANCE-FORCED-END-SITE, PAY-LEAVE-REPLAY, FIN-SETTLEMENT-01.

**Benchmark disposition:** Timefold, Kimai, LedgerSMB; supported-living payroll handoff remains No Credible Match.

**Failure/recovery acceptance:** wrong-site/person/source IDs disclose nothing; validation preserves entered data; concurrent or replayed actions create one effect; background failure stays visible and retryable; close/reopen and correction preserve lineage; next owner and due state remain explicit.

**Runtime status:** Blocked—representative actor account, safe fixture and write authority were not available. The mapped task scripts and controller actions remain the reproducible start point for later acceptance.

## J3 — Client intake to outcome

**Flow:** Client intake/identity → consent/authority → care plan/version/sign-off → shift task → note/outcome → timeline/review.

**State machine:** Referred; identity resolved; authority pending/verified; plan draft/active/review; task due/done/exception; note draft/signed/amended.

**Required hand-off/completion evidence:** Keep resident/site/version context persistent and do not let staff-entered labels substitute for authority or attestation.

**Retained finding roots:** CONSENT-AUTH-01, CONSENT-CAPACITY-01, CARE-SIGNOFF-01, TASK-RBAC-001.

**Benchmark disposition:** OpenMRS/OHC CARE/Bahmni partial benchmarks; exact supported-living authority and continuity are No Credible Match.

**Failure/recovery acceptance:** wrong-site/person/source IDs disclose nothing; validation preserves entered data; concurrent or replayed actions create one effect; background failure stays visible and retryable; close/reopen and correction preserve lineage; next owner and due state remain explicit.

**Runtime status:** Blocked—representative actor account, safe fixture and write authority were not available. The mapped task scripts and controller actions remain the reproducible start point for later acceptance.

## J4 — Prescription to administration/review

**Flow:** Medication/order → verification → stock → due round → administration/omit/refuse/error → PRN effect → review/correction.

**State machine:** Draft; pending verification; active; due/late; given/omitted/refused; error/incident; effect due/recorded; corrected/ceased.

**Required hand-off/completion evidence:** One server-resolved medication aggregate, valid competency, privileged override and immutable corrections.

**Retained finding roots:** MED-SCOPE-01, MED-OVERRIDE-01, MED-COMP-01, MED-RBAC-01, MED-VERIFY-01, CLIN-SCHEDULE-01.

**Benchmark disposition:** Bahmni medication/IPD plus OpenMRS forms; no match for Oblivion's complete supported-living safety authority.

**Failure/recovery acceptance:** wrong-site/person/source IDs disclose nothing; validation preserves entered data; concurrent or replayed actions create one effect; background failure stays visible and retryable; close/reopen and correction preserve lineage; next owner and due state remain explicit.

**Runtime status:** Blocked—representative actor account, safe fixture and write authority were not available. The mapped task scripts and controller actions remain the reproducible start point for later acceptance.

## J5 — Incident/hazard to verified closure

**Flow:** Report → triage/notifiability → investigation → corrective action/evidence → effectiveness verification → closure/reopen → Control Room/H&S reconciliation.

**State machine:** Draft; reported; triaged; potentially notifiable/reviewed; investigating; action open/complete/pending verification/verified; close-ready/closed/reopened.

**Required hand-off/completion evidence:** Protect site scope, parent-child integrity, independent approval, statutory hard blockers and symmetric alert visibility.

**Retained finding roots:** HS-SITE-01, SAFE-NESTED-01, HS-NOTIFIABLE-01, HS-CLOSE-01, HS-ASSURANCE-01, INCIDENT-ALERT-LIFECYCLE-01, SAFE-TERMINAL-SYNC-01.

**Benchmark disposition:** BeaconHS/OpenProject/OneUptime partial; WorkSafe decision and supported-living safeguarding authority are No Credible Match.

**Failure/recovery acceptance:** wrong-site/person/source IDs disclose nothing; validation preserves entered data; concurrent or replayed actions create one effect; background failure stays visible and retryable; close/reopen and correction preserve lineage; next owner and due state remain explicit.

**Runtime status:** Blocked—representative actor account, safe fixture and write authority were not available. The mapped task scripts and controller actions remain the reproducible start point for later acceptance.

## J6 — Purchase to asset/device/vehicle retirement

**Flow:** Purchase/bill → asset identity → site/custody assignment → device link/observed state → inspection/maintenance → depreciation → disposal.

**State machine:** Requested; approved; purchased; received; assigned; active/degraded; maintenance due/in progress; depreciated; disposed.

**Required hand-off/completion evidence:** Preserve source and one canonical identity/custody timeline; finance posts from source documents, not parallel operational guesses.

**Retained finding roots:** ASSET-RBAC-01, ARCH-P0-B, SEC-PROV-003, SEC-HEALTH-004, FIN-GL-RECURRING-01.

**Benchmark disposition:** Snipe-IT, NetBox, Ditto and ERPNext; no single credible supported-living end-to-end match.

**Failure/recovery acceptance:** wrong-site/person/source IDs disclose nothing; validation preserves entered data; concurrent or replayed actions create one effect; background failure stays visible and retryable; close/reopen and correction preserve lineage; next owner and due state remain explicit.

**Runtime status:** Blocked—representative actor account, safe fixture and write authority were not available. The mapped task scripts and controller actions remain the reproducible start point for later acceptance.

## J7 — Telemetry to resolved operational work

**Flow:** Provider event/telemetry → normalized signal → atomic outbox/process → deduplicated alert → acknowledge/escalate → incident/work order → close/reopen.

**State machine:** Observed; normalized; queued; processing/failed; alert open/acknowledged/escalated; work active; resolved/reopened.

**Required hand-off/completion evidence:** Stable source identity, site binding, one active alert, delivery/replay visibility and Control Room-owned lifecycle.

**Retained finding roots:** ARCH-P0-C, CTRL-SIGNAL-002, INTEG-WEBHOOK-001, SEC-UNIFI-TLS-01, INCIDENT-ALERT-LIFECYCLE-01.

**Benchmark disposition:** ThingsBoard, OpenRemote, OneUptime, Traccar and libOSDP; Oblivion must build native care/privacy governance.

**Failure/recovery acceptance:** wrong-site/person/source IDs disclose nothing; validation preserves entered data; concurrent or replayed actions create one effect; background failure stays visible and retryable; close/reopen and correction preserve lineage; next owner and due state remain explicit.

**Runtime status:** Blocked—representative actor account, safe fixture and write authority were not available. The mapped task scripts and controller actions remain the reproducible start point for later acceptance.

## J8 — Agreement/delivery to claim or invoice and GL

**Flow:** Agreement/funding line → shift/timesheet delivery → approval → one monetisation path → claim/invoice → external/payment state → GL/reversal/reconciliation.

**State machine:** Eligible agreement; delivered; approved; prepared; submitted; accepted/rejected; paid; posted; reconciled/amended.

**Required hand-off/completion evidence:** Bind all evidence to client/agreement/period and use exactly one monetisation/provenance path with visible posting recovery.

**Retained finding roots:** FUND-BIND-01, FIN-GST-01, FIN-GL-REVERSAL-01, FIN-PAYMENT-MATCH-01, FIN-SETTLEMENT-01.

**Benchmark disposition:** ERPNext/Bigcapital/LedgerSMB for accounting controls; supported-living funding claims are No Credible Match.

**Failure/recovery acceptance:** wrong-site/person/source IDs disclose nothing; validation preserves entered data; concurrent or replayed actions create one effect; background failure stays visible and retryable; close/reopen and correction preserve lineage; next owner and due state remain explicit.

**Runtime status:** Blocked—representative actor account, safe fixture and write authority were not available. The mapped task scripts and controller actions remain the reproducible start point for later acceptance.
