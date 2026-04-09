# Health & Safety Implementation Plan — Safe Integration

**Date:** 2026-04-09
**Role:** Principal Systems Architect & WorkSafe H&S Auditor
**Status:** PLANNING ONLY — No code, no migrations
**Prerequisite:** docs/health-and-safety-system-audit.md

---

## CRITICAL FINDINGS FROM CODEBASE AUDIT

Before the plan: three facts that change everything.

**Finding 1: Control Room bridge is built but UNWIRED.**
`ComprehensiveAlertBridgeService` has 7 public bridge methods (`bridgeClientIncident`, `bridgeSafeguardingConcern`, etc.) but **nothing calls them**. No observers, no event listeners, no controller hooks. High-severity incidents currently trigger email/database notifications only via `NotificationService::notifyCrud()`. The Control Room infrastructure (alerts, SLAs, queues, playbooks) is production-ready but dormant for safety events.

**Finding 2: Only SiteHazard has an observer.**
`SiteHazardObserver` is registered in `AppServiceProvider` (line 66). It handles reference number generation, risk rating calculation, auto-assignment, and status change logging. ClientIncident, FleetIncident, WorkplaceInjury, RestraintEvent, SafeguardingConcern — none have observers. None fire Laravel events.

**Finding 3: Shift eligibility is rule-based and extensible.**
`ShiftStaffEligibilityService` evaluates an ordered list of rules (conflicts, time off, turnaround, compliance, coverage, overfill, availability, fatigue, site assignment, driver licence). Each rule returns `{rule, passed, severity, overrideable, message}`. New rules can be injected via constructor without modifying existing rules. `ComplianceMatrixService::canAssignToShift()` is already in the chain.

**Implication:** Phase 1 must wire the existing bridge service before building HsEvent. The platform currently has zero automated safety alerting despite having the infrastructure. This is the highest-priority fix.

---

## 1. FINAL ARCHITECTURE (REFINED)

### 1.1 Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    EXISTING SYSTEMS (PROTECTED)              │
│                                                              │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────────────┐  │
│  │  Shifts   │  │    HR    │  │     Control Room          │  │
│  │ (backbone)│  │ (training│  │  (alerting engine)        │  │
│  │          │  │  payroll) │  │  ComprehensiveAlertBridge │  │
│  └────┬─────┘  └────┬─────┘  │  TriageQueue / SLA        │  │
│       │              │        │  Playbook / Escalation     │  │
│       │              │        └────────────┬───────────────┘  │
│       │              │                     │                  │
├───────┼──────────────┼─────────────────────┼──────────────────┤
│       │     NEW: H&S SAFETY LAYER          │                  │
│       │              │                     │                  │
│  ┌────▼──────────────▼─────────────────────▼───────────────┐ │
│  │                     HsEvent                              │ │
│  │  (single source of truth — polymorphic to sources)       │ │
│  │  ┌──────────────┐ ┌──────────────┐ ┌────────────────┐  │ │
│  │  │HsInvestigation│ │HsCorrectiveAction│ │HsRiskAssessment│ │ │
│  │  └──────────────┘ └──────────────┘ └────────────────┘  │ │
│  │  ┌──────────────────┐ ┌──────────────┐                  │ │
│  │  │HsTrainingRequirement│ │HsPpeAllocation│                │ │
│  │  └──────────────────┘ └──────────────┘                  │ │
│  └─────────────────────────────────────────────────────────┘ │
│       │              │              │                         │
│  ┌────▼────┐  ┌──────▼────┐  ┌─────▼─────┐                 │
│  │ Sources │  │  Sources  │  │  Sources  │                  │
│  │ClientInc│  │FleetInc   │  │SiteHazard │  ...etc          │
│  │(existing)│  │(existing) │  │(existing) │                  │
│  └─────────┘  └───────────┘  └───────────┘                  │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 How Control Room Remains Intact

- **No changes to ControlRoomAlert model, schema, or relationships**
- **No changes to ComprehensiveAlertBridgeService methods** — they are called, not modified
- **No changes to TriageQueue, AlertSla, SlaDefinition schemas**
- **New SlaDefinition and TriageQueue records seeded** for H&S alert types
- **New Playbook records seeded** for H&S incident response
- HsEvent triggers alerts by calling the EXISTING `bridgeOperationalAlert()` or existing specific bridge methods
- The bridge service's 30-minute deduplication, queue routing, and SLA attachment work unchanged

### 1.3 How Shifts Remain Intact

- **No changes to Shift model, schema, status transitions, or booted() hooks**
- **No changes to ShiftSafetyInvariantService**
- **No changes to ShiftHandoverService core logic**
- **No changes to Timesheet validation or payroll-critical field locking**
- H&S training enforcement added as a NEW eligibility rule in `ShiftStaffEligibilityService` — injected alongside existing rules, not replacing any
- ShiftHandover data enriched with open H&S events via a query at handover generation time — handover schema unchanged (uses existing `incidents_to_note` and `follow_up_items` arrays)
- ClientIncident already has `shift_id` FK — no schema change needed for incident-to-shift linking

---

## 2. CORE ENTITIES (FINAL LIST)

### 2.1 HsEvent — Central Safety Record

**Owner:** H&S safety layer (cross-cutting)
**Boundary:** Index and lifecycle tracker. Does NOT duplicate source data. Source models retain all detail.

| Field | Type | Notes |
|-------|------|-------|
| id | uuid | PK |
| organization_id | fk | Tenant isolation |
| reference_number | string | Auto: HS-YYYY-NNNN |
| source_type | string | Polymorphic: ClientIncident, FleetIncident, SiteHazard, WorkplaceInjury, RestraintEvent, SubstanceExposureRecord, SafeguardingConcern |
| source_id | uuid | Polymorphic FK |
| event_category | enum | incident, near_miss, hazard, injury, exposure, restraint, safeguarding, drill_failure, inspection_failure, equipment_fault, vehicle_incident |
| event_date | datetime | When the event occurred |
| site_id | fk(nullable) | Where |
| client_id | fk(nullable) | Affected client |
| staff_id | fk(nullable) | Involved staff |
| asset_id | fk(nullable) | Involved asset/vehicle |
| shift_id | fk(nullable) | Active shift (if during shift) |
| severity | enum | low, medium, high, critical |
| worksafe_notifiable | boolean | Requires WorkSafe notification |
| worksafe_status | enum(nullable) | not_required, pending, notified, acknowledged |
| worksafe_reference | string(nullable) | WorkSafe ref |
| status | enum | open, investigating, corrective_action, monitoring, closed |
| investigation_required | boolean | Auto-set by severity rules |
| control_room_alert_id | fk(nullable) | Back-reference to generated alert |
| closed_at | datetime(nullable) | |
| closed_by | fk(nullable) | |
| closure_summary | text(nullable) | |
| idempotency_key | string(unique) | sha256(source_type:source_id:event_category) |
| created_by | fk | Reporter/system |

**Relationships:**
- `source()` → MorphTo (polymorphic)
- `investigations()` → HasMany HsInvestigation
- `correctiveActions()` → HasMany HsCorrectiveAction
- `controlRoomAlert()` → BelongsTo ControlRoomAlert (nullable)
- `site()`, `client()`, `staff()`, `asset()`, `shift()` → BelongsTo (all nullable)

**Lifecycle:**
```
open → investigating → corrective_action → monitoring → closed
  │                                                        ↑
  └──── (low severity, no actions needed) ────────────────┘
```

**Auto-creation rules:**
- HsEvent created by observers on source models (see Section 3)
- `investigation_required = true` when severity ∈ {high, critical} OR worksafe_notifiable = true
- `control_room_alert_id` set when bridge service returns an alert

**What HsEvent does NOT do:**
- Does not replace source models
- Does not store incident details (those stay on ClientIncident, etc.)
- Does not manage Control Room alert lifecycle (Control Room does that)
- Does not change shift status or eligibility (separate rule does that)

---

### 2.2 HsInvestigation — Formal Investigation Record

**Owner:** H&S safety layer
**Boundary:** Investigation process and findings. Supplements (does not replace) ClientIncident's investigation fields.

| Field | Type | Notes |
|-------|------|-------|
| id | uuid | PK |
| organization_id | fk | Tenant |
| hs_event_id | fk | Parent HsEvent |
| reference_number | string | Auto: INV-YYYY-NNNN |
| investigation_type | enum | initial, full, worksafe_directed |
| lead_investigator_id | fk | Lead |
| team_members | json | User IDs |
| started_at | datetime | |
| target_completion_date | date | |
| completed_at | datetime(nullable) | |
| status | enum | assigned, in_progress, report_draft, under_review, completed |
| methodology | enum(nullable) | 5_whys, fishbone, bow_tie, icam, taproot |
| timeline_of_events | json(nullable) | Structured timeline |
| immediate_causes | text(nullable) | |
| root_causes | text(nullable) | |
| contributing_factors | json(nullable) | |
| findings | text(nullable) | |
| recommendations | text(nullable) | |
| reviewed_by | fk(nullable) | |
| reviewed_at | datetime(nullable) | |
| approved_by | fk(nullable) | |
| approved_at | datetime(nullable) | |

**Relationship to existing ClientIncident investigation fields:**
- ClientIncident already has: `investigation_status`, `investigation_assigned_to`, `root_cause_category`, `root_cause_description`, `contributing_factors`, `corrective_actions`, `lessons_learned`
- These fields remain and continue to work for the existing UI
- HsInvestigation is the formal record for auditors, WorkSafe, and cross-source investigations
- For ClientIncidents that generate an HsInvestigation, the ClientIncident fields become a summary view; HsInvestigation is the detailed record
- No migration of existing data required — HsInvestigation is net-new for new events

---

### 2.3 HsCorrectiveAction — Trackable Actions

**Owner:** H&S safety layer
**Boundary:** Action assignment, tracking, and verification. Replaces JSON arrays over time.

| Field | Type | Notes |
|-------|------|-------|
| id | uuid | PK |
| organization_id | fk | Tenant |
| hs_event_id | fk(nullable) | Parent HsEvent |
| actionable_type | string(nullable) | Polymorphic: HsInvestigation, SiteHazard, EmergencyDrillFinding, SiteInspectionRecord, HsRiskAssessment |
| actionable_id | uuid(nullable) | Polymorphic FK |
| reference_number | string | Auto: CA-YYYY-NNNN |
| action_type | enum | corrective, preventive, improvement |
| priority | enum | low, medium, high, critical |
| description | text | What needs doing |
| root_cause_link | text(nullable) | How this addresses root cause |
| assigned_to_user_id | fk | Responsible person |
| assigned_by_user_id | fk | Who assigned |
| due_date | date | Deadline |
| status | enum | open, in_progress, completed, verified, overdue, cancelled |
| completed_at | datetime(nullable) | |
| completed_by_user_id | fk(nullable) | |
| completion_evidence | text(nullable) | |
| evidence_paths | json(nullable) | File paths |
| verified_at | datetime(nullable) | |
| verified_by_user_id | fk(nullable) | |
| verification_notes | text(nullable) | |
| effectiveness_confirmed | boolean(nullable) | |

**Lifecycle:**
```
open → in_progress → completed → verified
  │                       │
  └── overdue (auto) ─────┘ (if due_date passed and status ∈ {open, in_progress})
```

**Relationship to existing corrective action data:**
- ClientIncident.corrective_actions (JSON array) — remains as-is; new incidents ALSO create HsCorrectiveAction records
- SiteHazardAction — remains as-is; new hazard actions ALSO create HsCorrectiveAction records
- EmergencyDrillFinding — already has assigned_to, due_date, status; HsCorrectiveAction adds verification layer
- No forced migration of historical data. Parallel tracking for new events.

---

### 2.4 HsRiskAssessment — Structured Risk Evaluation

**Owner:** H&S safety layer
**Boundary:** Risk evaluation with 5x5 matrix. Applies to sites, clients, substances, assets, tasks.

| Field | Type | Notes |
|-------|------|-------|
| id | uuid | PK |
| organization_id | fk | Tenant |
| assessable_type | string | Polymorphic: Site, Client, HazardousSubstance, Asset |
| assessable_id | uuid | Polymorphic FK |
| title | string | |
| risk_description | text | |
| existing_controls | text | |
| likelihood | integer | 1-5 (rare→almost_certain) |
| consequence | integer | 1-5 (insignificant→catastrophic) |
| inherent_risk_score | integer | Auto: likelihood × consequence |
| inherent_risk_level | enum | Auto: low (1-4), medium (5-9), high (10-15), extreme (16-25) |
| additional_controls | text(nullable) | |
| residual_likelihood | integer(nullable) | |
| residual_consequence | integer(nullable) | |
| residual_risk_score | integer(nullable) | Auto-calculated |
| residual_risk_level | enum(nullable) | Auto-calculated |
| risk_acceptable | boolean(nullable) | |
| assessed_by | fk | |
| assessed_at | datetime | |
| review_due_at | date | |
| review_frequency_days | integer | |
| status | enum | draft, current, under_review, superseded, archived |
| superseded_by_id | fk(nullable) | Version chain |
| approved_by | fk(nullable) | |
| approved_at | datetime(nullable) | |

**Relationship to existing ClientRisk:**
- ClientRisk has: label, severity, controls (text), review_date, active
- ClientRisk remains for existing UI — it serves the client profile quick-view
- HsRiskAssessment is the formal, auditable record with matrix scoring
- Over time, ClientRisk can be populated FROM HsRiskAssessment data (read-only summary)
- No deprecation of ClientRisk required

---

### 2.5 HsTrainingRequirement — H&S Training Rules

**Owner:** H&S safety layer, integrates with HR
**Boundary:** Defines which HrCourse is mandatory for H&S compliance. Does NOT modify HrCourse or HrCourseEnrollment.

| Field | Type | Notes |
|-------|------|-------|
| id | uuid | PK |
| organization_id | fk | Tenant |
| hr_course_id | fk | Links to existing HrCourse |
| requirement_type | enum | mandatory_all, mandatory_role, mandatory_site, recommended |
| applicable_roles | json(nullable) | Role names requiring this training |
| applicable_site_ids | json(nullable) | Site IDs requiring this training |
| frequency_months | integer | Re-certification interval |
| grace_period_days | integer | Buffer after expiry |
| regulatory_reference | string(nullable) | HSWA section |
| is_active | boolean | |

**How it integrates with HR without breaking HR:**
- Reads HrCourseEnrollment to determine compliance: staff is compliant if they have a completed enrollment for the linked HrCourse within frequency_months
- Does NOT modify HrCourse schema
- Does NOT modify HrCourseEnrollment schema
- Does NOT modify HrComplianceMatrix schema
- Provides its own compliance check method that the shift eligibility rule calls
- HR module is completely unaware of HsTrainingRequirement — it's a read-only consumer of HR data

**How it integrates with Shifts without breaking Shifts:**
- New eligibility rule `HsTrainingRule` added to `ShiftStaffEligibilityService` rule list
- Rule calls `HsTrainingRequirement::isStaffCompliant($userId, $siteId, $roles)`
- Returns `{rule: 'hs_training', passed: bool, severity: 'block'|'warning', overrideable: true, message: '...'}`
- Fits into existing eligibility framework — no changes to EligibilityResult, ShiftEligibilityOverride, or any existing rule
- `overrideable: true` means managers CAN override in emergencies (with audited justification via existing ShiftEligibilityOverride)

---

### 2.6 HsPpeAllocation — PPE Tracking

**Owner:** H&S safety layer
**Boundary:** Lightweight PPE issuance tracking. Supported living has minimal PPE needs.

| Field | Type | Notes |
|-------|------|-------|
| id | uuid | PK |
| organization_id | fk | Tenant |
| user_id | fk | Staff member |
| site_id | fk(nullable) | |
| ppe_type | enum | gloves, apron, face_shield, safety_glasses, ear_protection, non_slip_footwear, first_aid_kit, other |
| description | string(nullable) | |
| issued_at | date | |
| condition | enum | new, good, fair, replace |
| replacement_due_at | date(nullable) | |
| replaced_at | date(nullable) | |
| acknowledged_by_user | boolean | |
| acknowledged_at | datetime(nullable) | |

**Note:** This is the lowest-priority entity. Supported living PPE is primarily disposable gloves and aprons. This model exists for WorkSafe compliance evidence ("we provide and track PPE") rather than complex inventory management.

---

## 3. SYSTEM FLOWS (SAFE INTEGRATION)

### 3.1 Incident Creation Flow

```
STAFF ACTION: Submits incident via existing ClientIncident form
  │
  ▼
ClientIncident::created() — existing controller logic runs unchanged
  │
  ▼
NEW: ClientIncidentObserver::created(ClientIncident $incident)
  │
  ├─► Create HsEvent record
  │     source_type: ClientIncident
  │     source_id: $incident->id
  │     event_category: map($incident->type) → incident|near_miss|safeguarding
  │     severity: $incident->severity
  │     site_id: from $incident->client->site or shift
  │     client_id: $incident->client_id
  │     staff_id: $incident->reported_by
  │     shift_id: $incident->shift_id
  │     worksafe_notifiable: assess($incident)
  │     investigation_required: severity ∈ {high, critical} OR worksafe_notifiable
  │     idempotency_key: sha256(ClientIncident:{id}:{category})
  │
  ├─► IF severity ∈ {high, critical}:
  │     Call EXISTING bridgeClientIncident($incident)
  │     → ControlRoomAlert created (existing logic)
  │     → SLA attached (existing logic)
  │     → Queue assigned (existing logic)
  │     → Store alert ID on HsEvent.control_room_alert_id
  │
  ├─► IF investigation_required:
  │     Create HsInvestigation (status: assigned)
  │     Auto-assign lead_investigator from H&S officer role
  │
  └─► Existing NotificationService::notifyCrud() continues unchanged
      (email/database notifications still fire as before)
```

**What changes:** One observer added, registered in AppServiceProvider.
**What doesn't change:** ClientIncident model, controller, form, validation, notifications, status transitions.

---

### 3.2 Hazard Identification Flow

```
STAFF ACTION: Reports hazard via existing SiteHazard form
  │
  ▼
SiteHazard::created() — existing SiteHazardObserver runs unchanged
  (reference number, risk rating, auto-assignment, notification)
  │
  ▼
EXTEND: SiteHazardObserver::created() — add HsEvent creation
  │
  ├─► Create HsEvent record
  │     source_type: SiteHazard
  │     event_category: hazard
  │     severity: map(risk_rating) → low|medium|high|critical
  │
  ├─► IF risk_rating ∈ {high, extreme}:
  │     Call EXISTING bridgeOperationalAlert('hazard_identified', severity, context)
  │     → ControlRoomAlert created
  │     Store alert ID on HsEvent
  │
  └─► Existing SiteHazardObserver logic continues unchanged
```

**What changes:** Additional logic in existing SiteHazardObserver.
**What doesn't change:** SiteHazard model, form, risk calculation, assignment logic.

---

### 3.3 Fleet Incident Flow

```
STAFF ACTION: Reports vehicle incident via FleetIncident form
  │
  ▼
FleetIncident::created()
  │
  ▼
NEW: FleetIncidentObserver::created(FleetIncident $incident)
  │
  ├─► Create HsEvent record
  │     source_type: FleetIncident
  │     event_category: vehicle_incident
  │     asset_id: $incident->asset_id
  │     staff_id: $incident->driver_user_id
  │
  ├─► IF severity ∈ {high, critical} OR involves injury:
  │     Call EXISTING bridgeOperationalAlert('fleet_incident', severity, context)
  │     → ControlRoomAlert created
  │
  └─► Assess worksafe_notifiable (vehicle accident with serious harm)
```

**What changes:** New observer. FleetIncident model gets optional new nullable fields in a later phase (worksafe_notifiable, injury_occurred) — additive only.
**What doesn't change:** FleetIncident model core fields, fleet booking system, trip tracking.

---

### 3.4 Workplace Injury Flow

```
WorkplaceInjury::created()
  │
  ▼
NEW: WorkplaceInjuryObserver::created(WorkplaceInjury $injury)
  │
  ├─► Create HsEvent record
  │     event_category: injury
  │     worksafe_notifiable: $injury->worksafe_notifiable
  │     staff_id: $injury->user_id
  │
  ├─► IF worksafe_notifiable:
  │     Call EXISTING bridgeOperationalAlert('workplace_injury', 'critical', context)
  │     → ControlRoomAlert with CRITICAL SLA
  │     HsEvent.worksafe_status = 'pending'
  │
  └─► IF investigation_required:
      Create HsInvestigation
```

---

### 3.5 Control Room Escalation (Uses Existing System)

```
ControlRoomAlert created (by bridge service)
  │
  ▼
EXISTING: SlaDefinition::findForAlert() attaches SLA
EXISTING: TriageQueue::findForAlert() assigns queue
EXISTING: Playbook::findForAlert() auto-attaches playbook (if configured)
  │
  ▼
EXISTING: Operator acknowledges → SLA.recordAcknowledge()
EXISTING: Operator responds → SLA.recordResponse()
EXISTING: If SLA breached → auto-escalation to next queue tier
  │
  ▼
NEW (data flow only): When ControlRoomAlert resolved:
  → HsEvent status updated to reflect resolution
  → BUT: HsEvent cannot close until corrective actions verified
  → Alert resolution and HsEvent closure are INDEPENDENT lifecycles
```

**Critical distinction:** Control Room tracks alert response time. HsEvent tracks safety lifecycle. An alert can be "resolved" (operator handled it) while the HsEvent remains "investigating" or "corrective_action" for weeks.

---

### 3.6 Investigation Lifecycle

```
HsInvestigation created (status: assigned)
  │
  ▼
Lead investigator starts → status: in_progress
  │
  ├─► Collect evidence (witness statements, photos, records)
  ├─► Determine methodology (5 whys, fishbone, etc.)
  ├─► Document timeline, causes, contributing factors
  │
  ▼
Draft report → status: report_draft
  │
  ▼
Reviewed by H&S officer → status: under_review
  │
  ├─► IF changes needed → back to in_progress
  │
  ▼
Approved → status: completed
  │
  ├─► Create HsCorrectiveAction records from recommendations
  │
  ▼
HsEvent status → corrective_action (waiting for actions to complete)
```

---

### 3.7 Corrective Action Lifecycle

```
HsCorrectiveAction created (status: open)
  │
  ▼
Assignee works on it → status: in_progress
  │
  ▼
SCHEDULED JOB: Daily check for overdue actions
  IF due_date < today AND status ∈ {open, in_progress}:
    status → overdue
    Call EXISTING bridgeOperationalAlert('corrective_action_overdue', 'warn', context)
    → Control Room alert for management
  │
  ▼
Assignee completes → status: completed
  Upload evidence, completion notes
  │
  ▼
Verifier checks effectiveness → status: verified
  effectiveness_confirmed = true/false
  │
  ├─► IF effectiveness_confirmed = false:
  │     New corrective action created
  │     Original marked with verification_notes explaining failure
  │
  ▼
ALL corrective actions for HsEvent verified?
  YES → HsEvent status → monitoring (or closed if no monitoring needed)
  NO → HsEvent remains in corrective_action status
```

---

### 3.8 Event Closure

```
HsEvent closure requires ALL of:
  ├─► All HsCorrectiveAction records: status = verified
  ├─► HsInvestigation (if exists): status = completed
  ├─► IF worksafe_notifiable: worksafe_status ∈ {notified, acknowledged}
  ├─► Closure summary provided
  ├─► Closed by authorized user (H&S officer or above)
  │
  ▼
HsEvent.status → closed
HsEvent.closed_at → now
HsEvent.closed_by → user
```

---

## 4. FULL MODULE INTEGRATION MATRIX

### 4.1 Shifts / Rostering

**Requires H&S integration:** YES

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| Incidents linked to active shift | ClientIncident.shift_id already exists | NONE — field already in schema |
| H&S training eligibility check | New `HsTrainingRule` in eligibility service | SAFE — additive rule, overrideable, existing override UI works |
| Handover includes open H&S events | Query HsEvent at handover generation | SAFE — data injected into existing `incidents_to_note` array |
| Pre-shift hazard awareness | ShiftSignal emitted if site has open high/extreme hazards | SAFE — signal system already async/idempotent |

**Events generated:** None directly. Shifts are the CONTEXT for events, not the source.
**Link to HsEvent:** HsEvent.shift_id references active shift when incident occurs during shift.
**Shift core changes:** ZERO. No model changes, no status changes, no validation changes.

---

### 4.2 Timesheets / Payroll

**Requires H&S integration:** NO (with one minor exception)

**Why not:** Timesheets record hours worked and drive payroll. They are not a safety system.

**Exception:** WorkplaceInjury.lost_time_days — when a staff member is on injury leave, this affects timesheet generation. BUT: this is already handled through HrLeaveRequest (injury leave type). No new integration needed. The existing leave system handles absence from shifts.

**Events generated:** None.
**Link to HsEvent:** Indirect only — Timesheet hours used in LTIFR calculation (reporting layer, Phase 6).

---

### 4.3 HR

**Requires H&S integration:** YES

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| H&S training requirements | HsTrainingRequirement reads HrCourse + HrCourseEnrollment | SAFE — read-only consumer, no HR schema changes |
| Staff incident history | HsEvent where staff_id = user.id | SAFE — read-only query, surfaced on HR profile |
| Workplace injury → leave | WorkplaceInjury links to HrLeaveRequest | Already works via existing leave system |
| Compliance status | HrStaffComplianceStatus could include H&S flag | SAFE — additive field on existing model, or computed at query time |

**Events generated:** Training completion (HrCourseEnrollment) can clear H&S compliance alerts.
**Link to HsEvent:** HsEvent.staff_id links to User (HR employee). HsTrainingRequirement.hr_course_id links to HrCourse.
**HR core changes:** ZERO schema changes. One additive computed attribute on HrStaffComplianceStatus at most.

---

### 4.4 Fleet Management

**Requires H&S integration:** YES

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| Vehicle incidents → HsEvent | FleetIncidentObserver creates HsEvent | SAFE — new observer, no model changes |
| Failed pre-trip checklist → HsEvent | FleetChecklistRunObserver on passed=false | SAFE — new observer |
| Vehicle incidents → Control Room | bridgeOperationalAlert() call from observer | SAFE — uses existing bridge |
| WOF/COF expiry alerts | Already handled by existing fleet alerting | NO CHANGE needed |

**Events generated:** FleetIncident (vehicle_incident), FleetChecklistRun failure (equipment_fault).
**Link to HsEvent:** HsEvent.source_type = FleetIncident, HsEvent.asset_id = vehicle asset.
**Fleet core changes:** ZERO in Phase 1-3. Phase 4 adds optional nullable fields to FleetIncident (worksafe_notifiable, injury_occurred) — additive only.

---

### 4.5 Asset Management

**Requires H&S integration:** YES

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| Failed inspection → HsEvent | AssetInspectionObserver on result='fail' | SAFE — new observer |
| Equipment fault → Control Room | bridgeOperationalAlert() for safety-critical assets | SAFE — uses existing bridge |
| Maintenance overdue → alert | Scheduled job checks next_due_at | SAFE — new job, no model changes |

**Events generated:** AssetInspection failure (equipment_fault).
**Link to HsEvent:** HsEvent.source_type = AssetInspection, HsEvent.asset_id.
**Asset core changes:** Phase 4 extends AssetInspection with optional fields (defect_type, defect_severity, compliance_standard) — additive only.

---

### 4.6 Sites / Houses / Facilities

**Requires H&S integration:** YES

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| Hazards → HsEvent | Extend existing SiteHazardObserver | SAFE — adds to existing observer |
| Failed inspections → HsEvent | SiteInspectionRecordObserver on result='fail' | SAFE — new observer |
| Compliance checks → HsEvent | SiteComplianceCheckObserver on failed status | SAFE — new observer |
| Emergency drill failures → HsEvent | EmergencyDrillObserver on outcome='failed' | SAFE — new observer |
| Site risk profile | HsRiskAssessment (assessable=Site) | SAFE — new records, no site schema changes |

**Events generated:** SiteHazard (hazard), SiteInspectionRecord failure (inspection_failure), SiteComplianceCheck failure, EmergencyDrill failure (drill_failure).
**Link to HsEvent:** HsEvent.source_type = SiteHazard/SiteInspectionRecord/etc, HsEvent.site_id.
**Site core changes:** ZERO. Existing site models untouched. SiteComplianceCheck gets optional compliance_template_id FK in Phase 4 — additive.

---

### 4.7 Client Profiles

**Requires H&S integration:** YES

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| Client incidents → HsEvent | ClientIncidentObserver (primary flow) | SAFE — new observer |
| Safeguarding → HsEvent | SafeguardingConcernObserver | SAFE — new observer |
| Restraint → HsEvent | RestraintEventObserver | SAFE — new observer |
| Client risk assessment | HsRiskAssessment (assessable=Client) | SAFE — new records alongside existing ClientRisk |
| Substance exposure → HsEvent | SubstanceExposureRecordObserver | SAFE — new observer |

**Events generated:** ClientIncident (incident/near_miss), SafeguardingConcern (safeguarding), RestraintEvent (restraint), SubstanceExposureRecord (exposure).
**Link to HsEvent:** HsEvent.source_type = ClientIncident/SafeguardingConcern/etc, HsEvent.client_id.
**Client core changes:** ZERO. ClientRisk remains. ClientIncident unchanged. No schema modifications.

---

### 4.8 Control Room

**Requires H&S integration:** YES — as the RECIPIENT of H&S alerts

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| Receive H&S alerts | Existing bridgeOperationalAlert() / bridgeClientIncident() / bridgeSafeguardingConcern() called by observers | SAFE — bridge methods already exist and are tested |
| SLA tracking for H&S | New SlaDefinition seed records | SAFE — additive data |
| Queue routing for H&S | New TriageQueue seed records | SAFE — additive data |
| Playbooks for H&S | New Playbook seed records | SAFE — additive data |

**Events generated:** None by Control Room. Control Room RECEIVES events.
**Link to HsEvent:** HsEvent.control_room_alert_id back-references the generated ControlRoomAlert.
**Control Room core changes:** ZERO code changes. Seed data only.

**Critical note:** The existing bridge methods (`bridgeClientIncident`, `bridgeSafeguardingConcern`) have NEVER been called. Phase 0 of implementation must wire these up. This is a bug fix, not a new feature.

---

### 4.9 Finance

**Requires H&S integration:** YES (optional, low priority)

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| Incident cost tracking | FinFinancialEvent with hs_* event_types | SAFE — new event_type values, existing polymorphic pattern |
| Lost time cost | WorkplaceInjury.lost_time_days × rate → FinFinancialEvent | SAFE — new job, no finance schema changes |
| Training cost tagging | HrCourseEnrollment already creates training_cost FinFinancialEvent | EXISTING — just needs H&S tag in context |

**Events generated:** FinFinancialEvent (hs_incident_cost, hs_lost_time_cost) — created by H&S layer, consumed by Finance.
**Link to HsEvent:** FinFinancialEvent.source_type = HsEvent (or the operational source model).
**Finance core changes:** ZERO. New event_type string values only.

---

### 4.10 Reporting / Insights

**Requires H&S integration:** YES (Phase 6)

| Integration | Mechanism | Impact on Existing Workflow |
|------------|-----------|---------------------------|
| LTIFR / TRIFR | Query HsEvent (injury) + Timesheet hours | SAFE — read-only queries |
| Incident dashboards | Query HsEvent grouped by category/severity/site | SAFE — new dashboard views |
| Compliance status | Query HsTrainingRequirement + HrCourseEnrollment | SAFE — read-only |
| Corrective action tracking | Query HsCorrectiveAction by status/due_date | SAFE — new views |

**Events generated:** None.
**Link to HsEvent:** Read-only consumer of all H&S data.
**Reporting core changes:** New report definitions and dashboard components. No changes to existing reports.

---

## 5. CONTROL ROOM INTEGRATION

### 5.1 What Triggers Alerts

| Source Event | Alert Type | Severity | Bridge Method |
|-------------|-----------|----------|---------------|
| ClientIncident (high/critical) | incident.client | high/critical | bridgeClientIncident() (EXISTING, needs wiring) |
| SafeguardingConcern (any) | safeguarding.concern | high (min) | bridgeSafeguardingConcern() (EXISTING, needs wiring) |
| SiteHazard (high/extreme risk) | operations.hazard_identified | high/critical | bridgeOperationalAlert() |
| FleetIncident (high/critical) | operations.fleet_incident | high/critical | bridgeOperationalAlert() |
| WorkplaceInjury (worksafe_notifiable) | operations.workplace_injury | critical | bridgeOperationalAlert() |
| RestraintEvent (with injury) | operations.restraint_event | high | bridgeOperationalAlert() |
| SubstanceExposure (medical attention) | operations.substance_exposure | high | bridgeOperationalAlert() |
| EmergencyDrill (failed, critical findings) | operations.drill_failure | medium | bridgeOperationalAlert() |
| AssetInspection (failed, safety-critical) | operations.equipment_fault | high | bridgeOperationalAlert() |
| FleetChecklistRun (failed) | operations.equipment_fault | medium/high | bridgeOperationalAlert() |
| HsCorrectiveAction (overdue) | operations.corrective_action_overdue | warn | bridgeOperationalAlert() |
| H&S training expired (mandatory) | compliance.hs_training_expired | warn/high | bridgeComplianceExpiry() (EXISTING) |
| Near-miss pattern (≥3 at same site in 7d) | operations.near_miss_pattern | medium | bridgeOperationalAlert() |

### 5.2 How Alerts Enter Control Room

```
Observer detects H&S-significant event
  │
  ▼
Observer creates HsEvent
  │
  ▼
Observer calls ComprehensiveAlertBridgeService method
  (uses EXISTING public methods — no new methods needed)
  │
  ▼
Bridge service internally:
  1. Deduplicates (30-min window) ← EXISTING
  2. Creates ControlRoomAlert ← EXISTING
  3. Assigns TriageQueue ← EXISTING
  4. Attaches SLA ← EXISTING
  5. Auto-attaches Playbook ← EXISTING
  │
  ▼
Control Room operators handle alert via EXISTING UI
```

### 5.3 Confirmation: NO Duplication of Alerting Logic

- H&S observers call existing bridge methods — they do NOT create ControlRoomAlert directly
- Bridge service handles all deduplication — observers don't need to check
- SLA definitions are seed data matched by alert_type/severity — no code-level SLA logic in H&S layer
- Queue routing is seed data matched by alert_type/severity — no code-level routing in H&S layer
- Playbook auto-attachment is seed data matched by trigger criteria — no code-level playbook logic in H&S layer

**The H&S layer is a PRODUCER of events. Control Room is the CONSUMER. The bridge service is the interface.**

### 5.4 New Seed Data Required

**SlaDefinitions to seed:**

| Code | Alert Types | Severities | Ack (min) | Response (min) | Resolution (min) |
|------|------------|-----------|-----------|----------------|-------------------|
| hs_critical | operations.workplace_injury, operations.fleet_incident | critical | 15 | 60 | 240 |
| hs_high | operations.hazard_identified, operations.restraint_event, operations.substance_exposure | high | 60 | 480 | 1440 |
| hs_medium | operations.drill_failure, operations.equipment_fault, operations.near_miss_pattern | medium | 240 | 1440 | 10080 |
| hs_overdue | operations.corrective_action_overdue | warn | 1440 | 2880 | 10080 |

**TriageQueues to seed:**

| Code | Tier | Severities | Sources | Roles |
|------|------|-----------|---------|-------|
| hs_critical_triage | 1 | critical | operations | health_safety_officer, operations_director |
| hs_standard_triage | 1 | high, medium | operations | health_safety_officer, site_manager |
| hs_compliance_triage | 1 | warn | compliance | health_safety_officer, hr_manager |

**Playbooks to seed:**

| Code | Category | Trigger Types | Auto-Attach | Steps |
|------|----------|--------------|-------------|-------|
| hs_serious_harm | SAFETY | operations.workplace_injury | Yes (critical) | 1. Preserve scene 2. Assess harm 3. Notify WorkSafe 4. Assign investigator 5. Evidence collection |
| hs_incident_investigation | INVESTIGATION | operations.* | Yes (high) | 1. Initial assessment 2. Secure evidence 3. Witness statements 4. Root cause analysis 5. Corrective actions |
| hs_hazard_response | SAFETY | operations.hazard_identified | Yes (high/critical) | 1. Isolate hazard 2. Immediate controls 3. Risk assessment 4. Permanent controls |

---

## 6. COMPLIANCE MODEL

### 6.1 Audit Trails

**Already in place (no changes needed):**
- `AuditableChanges` trait on all safety models → full field-level change history with user, timestamp, old/new values
- `SoftDeletes` on all critical models → records never hard-deleted
- `created_by` / `updated_by` fields on all H&S models
- ControlRoomAlert tracks acknowledged_by, resolved_by, closed_by with timestamps

**New audit capabilities from HsEvent:**
- HsEvent.idempotency_key prevents duplicate event creation (auditable proof of no duplication)
- HsInvestigation tracks full investigation lifecycle with reviewer/approver chain
- HsCorrectiveAction tracks assign→complete→verify chain with evidence
- Reference numbers (HS-YYYY-NNNN, INV-YYYY-NNNN, CA-YYYY-NNNN) provide human-readable audit references

### 6.2 WorkSafe Compliance

| HSWA Requirement | Section | Implementation | Status |
|-----------------|---------|----------------|--------|
| Notify notifiable events | s56 | HsEvent.worksafe_notifiable + worksafe_status tracking | NEW |
| Preserve scene | s60 | HsEvent triggers scene preservation playbook; ClientIncident.site_preserved flag | EXISTING + NEW playbook |
| Worker participation | Part 4 | HsCommittee, HsRepresentative, HsConsultation | EXISTING |
| Risk management | s30 | HsRiskAssessment (5x5 matrix, control hierarchy) | NEW |
| Information and training | s36 | HsTrainingRequirement + HrCourse | NEW |
| Monitor conditions | s36(d) | SiteInspectionSchedule, SubstanceExposureRecord | EXISTING |
| Maintain work environment | s36(c) | SiteHazard, SiteComplianceCheck, EmergencyDrill | EXISTING |
| Engage workers on H&S | s58-62 | HsConsultation with worker feedback | EXISTING |
| Record keeping | s56(4) | HsEvent with full audit trail, 5-year retention | NEW |

### 6.3 Evidence Tracking

| Evidence Type | Storage | Linked To |
|---------------|---------|-----------|
| Incident photos/documents | S3 via ClientIncidentAttachment | ClientIncident → HsEvent |
| Investigation evidence | S3 via HsAttachment (polymorphic) | HsInvestigation |
| Corrective action evidence | S3 via HsCorrectiveAction.evidence_paths | HsCorrectiveAction |
| Witness statements | S3 via HsAttachment | HsInvestigation |
| Inspection evidence | S3 via SiteInspectionRecord.evidence_photos | SiteInspectionRecord → HsEvent |
| Hazard photos | S3 via SiteHazard.photo_paths | SiteHazard → HsEvent |
| Training certificates | S3 via HrCourseEnrollment documents | HrCourseEnrollment → HsTrainingRequirement |
| WorkSafe correspondence | S3 via HsAttachment | HsEvent |
| PPE acknowledgements | HsPpeAllocation.acknowledged_at | HsPpeAllocation |
| Risk assessment approvals | HsRiskAssessment.approved_by/approved_at | HsRiskAssessment |

### 6.4 Retention Policy

- HsEvent and all child records: 7 years minimum (WorkSafe statute of limitations for prosecution is 2 years from discovery, but ACC claims can surface much later)
- Soft deletes only — no hard deletion of safety records
- Archived events (status: closed, older than 2 years) can be moved to cold storage but must remain queryable

---

## 7. IMPLEMENTATION PLAN (PHASED + SAFE)

### Phase 0: Wire Existing Bridge Service (URGENT — Bug Fix)

**Scope:**
- Create observers for ClientIncident and SafeguardingConcern
- Wire observers to call existing `bridgeClientIncident()` and `bridgeSafeguardingConcern()` methods
- Register observers in AppServiceProvider
- Seed SlaDefinition records for incident and safeguarding alert types (if not already seeded)
- Verify TriageQueue routing for these alert types

**Dependencies:** None.

**Why this is Phase 0:** The bridge service methods exist and are production-ready but have never been called. High-severity client incidents and ALL safeguarding concerns are currently invisible to Control Room operators. This is a live compliance gap.

**Control Room impact:** NONE to code. Seed data added. Existing methods called for the first time.
**Shift impact:** NONE. No shift code touched.

**Risk:** Low. Bridge methods already handle deduplication and error cases. Main risk is alert volume — start with high/critical severity only (which the existing methods already filter for).

---

### Phase 1: HsEvent Backbone

**Scope:**
- Create HsEvent model and migration
- Create HsEventService (encapsulates event creation logic, idempotency, severity assessment, WorkSafe notifiability assessment)
- Create observers for: FleetIncident, WorkplaceInjury, RestraintEvent, SubstanceExposureRecord
- Extend SiteHazardObserver to create HsEvent
- Extend ClientIncidentObserver (from Phase 0) to create HsEvent
- Extend SafeguardingConcernObserver (from Phase 0) to create HsEvent
- Wire new observers to call bridge service for high-severity events
- Backfill job: create HsEvent records for existing high-severity historical records (last 12 months)

**Dependencies:** Phase 0 (observers for ClientIncident and SafeguardingConcern must exist).

**Control Room impact:** NONE to code. More event sources now flow into Control Room via existing bridge methods. New SlaDefinition and TriageQueue seed records for new alert types.
**Shift impact:** NONE. HsEvent.shift_id is populated from source model's shift_id (which already exists on ClientIncident). No shift model or logic changes.

**Risk:**
- Observer performance: HsEvent creation is lightweight (single INSERT with idempotency check). If concerned, dispatch via queue.
- Backfill volume: For orgs with high incident volumes, backfill should be batched. Run during off-hours.
- Idempotency: sha256 key prevents any duplicate creation — safe to re-run backfill.

---

### Phase 2: Investigation System

**Scope:**
- Create HsInvestigation model and migration
- Create HsInvestigationService (lifecycle management, assignment, review, approval)
- Auto-create HsInvestigation when HsEvent.investigation_required = true (via HsEventObserver)
- Investigation assignment notification (to lead investigator)
- Investigation UI (separate from existing ClientIncident investigation fields)
- Investigation overdue detection job

**Dependencies:** Phase 1 (HsEvent must exist).

**Control Room impact:** NONE. Investigation is an internal H&S process. If investigation is overdue, a new operational alert can be emitted — but this is purely additive.
**Shift impact:** NONE. Investigation has no shift touchpoints.

**Risk:**
- Dual investigation data: ClientIncident has investigation fields AND HsInvestigation exists. Resolution: ClientIncident investigation fields continue to work for quick operational notes. HsInvestigation is the formal auditable record. Document this distinction in UI and training materials.
- Adoption: Staff may not use HsInvestigation if ClientIncident fields feel sufficient. Resolution: For high/critical events, HsInvestigation is mandatory (system enforced). For medium/low, optional.

---

### Phase 3: Corrective Action System

**Scope:**
- Create HsCorrectiveAction model and migration
- Create HsCorrectiveActionService (lifecycle management, assignment, overdue detection, verification)
- Link HsCorrectiveAction to HsEvent (direct FK)
- Link HsCorrectiveAction to HsInvestigation (via actionable polymorphic)
- Overdue detection scheduled job (daily)
- Overdue corrective actions → Control Room alert via bridgeOperationalAlert()
- HsEvent closure logic: all corrective actions must be verified before HsEvent can close

**Dependencies:** Phase 1 (HsEvent), Phase 2 (HsInvestigation — so corrective actions can arise from investigations).

**Control Room impact:** Additive only. New alert type `operations.corrective_action_overdue` flows through existing bridge. New SlaDefinition seed record.
**Shift impact:** NONE. Corrective actions are management tasks, not shift-level operations.

**Risk:**
- Overdue alert fatigue: If many historical corrective actions are overdue, initial alert volume could be high. Resolution: Only alert on actions created AFTER system go-live. Historical backfill of corrective actions is a Phase 4+ activity.
- Verification bottleneck: If few people can verify, actions may pile up. Resolution: Allow delegation of verification authority.

---

### Phase 4: Risk Assessment & Training

**Scope:**
- Create HsRiskAssessment model and migration
- Create HsRiskAssessmentService (lifecycle, scoring, review scheduling)
- Risk assessment review scheduled job (checks review_due_at)
- Create HsTrainingRequirement model and migration
- Create HsTrainingComplianceService (checks enrollment status against requirements)
- Create HsTrainingRule eligibility rule for ShiftStaffEligibilityService
- Register HsTrainingRule in eligibility service constructor
- Create HsPpeAllocation model and migration
- Expiring H&S training → Control Room alert via bridgeComplianceExpiry()

**Dependencies:** Phase 1 (HsEvent), HR module (HrCourse, HrCourseEnrollment — read-only).

**Control Room impact:** Additive only. Training expiry alerts use EXISTING bridgeComplianceExpiry() method.
**Shift impact:** ONE CHANGE — new eligibility rule injected into ShiftStaffEligibilityService. This is the ONLY shift-system touching change in the entire plan. Safeguards:
1. Rule returns `overrideable: true` — managers can override with justification
2. `grace_period_days` on HsTrainingRequirement provides buffer after expiry
3. Rule is evaluated alongside existing rules — does not replace or modify any existing rule
4. ShiftEligibilityOverride already handles override workflow — no UI changes needed
5. Can be disabled per-organization via HsTrainingRequirement.is_active flag
6. **Rollout:** Deploy rule as `severity: 'warning'` first (non-blocking). After 30-day observation period, escalate to `severity: 'block'` for truly mandatory training only.

**Risk:**
- Rostering disruption: If many staff have expired training, blocking could cause coverage gaps. Resolution: Warning-first rollout. Grace period. Override capability. Bulk enrollment support.
- Data quality: HrCourseEnrollment must have accurate completion dates. If historical data is incomplete, compliance checks may false-positive. Resolution: Grace period absorbs this. Admin can bulk-update enrollment records.

---

### Phase 5: Cross-Module Integration

**Scope:**
- FleetIncident: Add optional nullable fields (worksafe_notifiable, injury_occurred, injury_details) — migration only, no existing field changes
- AssetInspection: Add optional nullable fields (defect_type, defect_severity, compliance_standard, defect_description, corrective_action_required) — migration only
- SiteComplianceCheck: Add optional nullable compliance_template_id FK — migration only
- SiteInspectionRecord: Create observer for failed inspections → HsEvent
- EmergencyDrill: Create observer for failed drills → HsEvent
- FleetChecklistRun: Create observer for failed runs → HsEvent
- ShiftHandover enhancement: At handover generation time, query open HsEvents for site and inject into `incidents_to_note` array — change in ShiftHandoverService::save() only
- Near-miss pattern detection job: Query HsEvent (near_miss) grouped by site_id in 7-day window, alert if ≥3

**Dependencies:** Phases 1-4 (all core entities and observers must exist).

**Control Room impact:** Additive only. More event types flow into existing bridge.
**Shift impact:** Minimal. ShiftHandover data enrichment is the only touchpoint — adds items to existing array, does not modify handover validation, submission, or acknowledgement logic.

**Risk:**
- Field additions to existing models: All nullable, all additive. Existing code that doesn't reference these fields is unaffected. Existing forms that don't render these fields are unaffected.
- Handover data volume: If a site has many open HsEvents, handover `incidents_to_note` could be long. Resolution: Cap at 10 most recent/severe. Show count with "view all" link.
- Observer cascade: With 10+ observers creating HsEvents, ensure idempotency key prevents any double-creation if multiple observers fire for the same underlying event.

---

### Phase 6: Reporting & Compliance Dashboards

**Scope:**
- LTIFR / TRIFR calculation service (reads HsEvent injury category + Timesheet total hours)
- H&S dashboard: open events by category, severity, site; overdue corrective actions; training compliance percentage
- Incident trend reporting (monthly/quarterly)
- Risk assessment register view (current assessments, review due dates)
- WorkSafe notification log (all notifiable events, notification status)
- Corrective action tracking dashboard (open, overdue, verified counts)
- H&S Committee meeting pack generator (aggregated safety data for committee meetings)
- Board-level H&S summary report
- Scheduled report generation jobs

**Dependencies:** Phases 1-5 (all data must be flowing).

**Control Room impact:** NONE. Reporting is read-only.
**Shift impact:** NONE. Reporting is read-only.

**Risk:**
- LTIFR accuracy: Depends on accurate timesheet hours AND accurate injury classification. Validate data quality before publishing to board.
- Report performance: Large datasets may require materialized views or snapshot tables. Use Laravel's chunk/cursor for large queries. Consider DashboardSnapshot model (already exists in Governance domain) for caching.

---

### Phase Summary Table

| Phase | What | Models Touched (Existing) | Models Created (New) | Control Room Impact | Shift Impact |
|-------|------|--------------------------|---------------------|--------------------|--------------| 
| 0 | Wire bridge service | None | None | Existing methods called for first time | NONE |
| 1 | HsEvent backbone | None (observers only) | HsEvent | More alert sources | NONE |
| 2 | Investigation system | None | HsInvestigation | NONE | NONE |
| 3 | Corrective actions | None | HsCorrectiveAction | Overdue alerts (additive) | NONE |
| 4 | Risk + training | None schema changes | HsRiskAssessment, HsTrainingRequirement, HsPpeAllocation | Training expiry alerts (existing method) | ONE new eligibility rule (overrideable) |
| 5 | Cross-module wiring | Additive nullable fields on FleetIncident, AssetInspection, SiteComplianceCheck | None | More alert sources | Handover data enrichment only |
| 6 | Reporting | None | None (services/views) | NONE | NONE |

---

## 8. KEY RISKS

### 8.1 Control Room Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Alert fatigue from too many H&S alerts | Medium | High — operators ignore alerts | Start with high/critical only. Tune thresholds based on 30-day data. Near-miss pattern alerts default to medium (not high). |
| Deduplication window (30 min) too short for H&S | Low | Medium — duplicate alerts | H&S events are typically singular (one incident = one report). 30-min window is adequate. HsEvent idempotency key provides additional protection. |
| SLA definitions too aggressive for H&S | Medium | Medium — false breach alerts | Define realistic SLAs with operations team input. H&S high events: 1h acknowledge, 8h response. Adjust after 30 days. |
| Playbook auto-attachment creates noise | Low | Low — operators dismiss playbooks | Only auto-attach for critical severity. High severity playbooks require manual attachment. |

### 8.2 Shift Workflow Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| H&S training rule blocks rostering | Medium | HIGH — shifts go uncovered | Rule is overrideable. Grace period buffer. Warning-first rollout (30 days). Per-organization disable flag. |
| Handover data overload | Low | Low — staff skip reading | Cap injected H&S items at 10. Prioritize by severity. |
| Performance impact from HsEvent queries during shift operations | Low | Medium — slow shift loading | HsEvent queries are indexed (site_id + status). Eager-load only when needed (handover generation, not shift list). |

### 8.3 Duplication Risks

| Risk | Where | Mitigation |
|------|-------|------------|
| Investigation data in both ClientIncident fields AND HsInvestigation | Phase 2 | ClientIncident fields remain for quick ops notes. HsInvestigation is the formal record. Both can coexist — different audiences (shift staff vs. H&S officers). |
| Corrective actions in ClientIncident JSON AND HsCorrectiveAction | Phase 3 | New incidents create HsCorrectiveAction records. ClientIncident.corrective_actions JSON is not migrated — it remains as historical data. Over time, UI can surface HsCorrectiveAction instead. |
| ClientRisk AND HsRiskAssessment for same client | Phase 4 | Both coexist. ClientRisk is the quick profile flag. HsRiskAssessment is the formal evaluated record. ClientRisk can optionally be auto-populated from latest HsRiskAssessment. |
| Multiple observers creating HsEvent for same source | Phase 1/5 | Idempotency key (sha256 of source_type:source_id:event_category) with unique constraint. Second creation attempt fails silently. |

### 8.4 Audit/Compliance Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| WorkSafe notification deadline missed | Low (after system live) | CRITICAL — prosecution | HsEvent.worksafe_notifiable triggers critical ControlRoomAlert with 15-min SLA. Playbook step 3 is "Notify WorkSafe." Scheduled job checks for pending notifications older than 24h. |
| Evidence not attached to investigation | Medium | High — investigation incomplete | HsInvestigation cannot transition to report_draft without at least one HsAttachment. System-enforced. |
| Corrective actions marked complete without evidence | Medium | Medium — audit finding | HsCorrectiveAction.completed status requires non-null completion_evidence OR evidence_paths. System-enforced. |
| Historical events not backfilled | Low | Medium — incomplete reporting | Phase 1 backfill job covers last 12 months. Earlier events remain in source models and are queryable directly. |

---

## 9. DECISION LOG

Decisions made during planning that should be recorded:

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Phase 0 before Phase 1 | Bridge service is built but unwired — this is a live compliance gap that must be fixed before adding new entities |
| D2 | HsEvent is an INDEX, not a container | Source models retain all detail. HsEvent provides cross-module query surface and lifecycle tracking only. Prevents data duplication. |
| D3 | ClientIncident investigation fields NOT deprecated | They serve different audience (shift staff quick notes vs. formal investigation). Coexistence is simpler and less disruptive than migration. |
| D4 | HsTrainingRule starts as WARNING, not BLOCK | Prevents rostering disruption. 30-day observation period before escalating to blocking for mandatory training only. |
| D5 | No changes to ComprehensiveAlertBridgeService code | All H&S alert routing uses existing public methods. No new methods, no modified logic. Pure consumer of existing API. |
| D6 | Observers over events for HsEvent creation | Observers are simpler, synchronous (or easily queued), and don't require event listener registration. Consistent with existing SiteHazardObserver pattern. |
| D7 | Parallel corrective action tracking (not migration) | New HsCorrectiveAction records alongside existing JSON/text data. Avoids risky data migration and UI breakage. Convergence happens naturally over time. |
| D8 | HsRiskAssessment coexists with ClientRisk | Different purposes: ClientRisk is a quick flag on client profile. HsRiskAssessment is the formal scored record. No forced migration. |
| D9 | PPE tracking is lowest priority | Supported living has minimal PPE requirements. Model exists for WorkSafe compliance evidence. Not worth prioritizing over incident tracking and investigation. |
| D10 | Near-miss pattern detection in Phase 5 (not Phase 1) | Requires sufficient HsEvent data to detect patterns. Must accumulate events before pattern detection is useful. |
