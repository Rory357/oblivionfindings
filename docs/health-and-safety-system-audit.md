# Health & Safety System Audit — Oblivion Findings

**Date:** 2026-04-09
**Auditor Role:** Principal Systems Architect & H&S Compliance Auditor
**Scope:** Full platform audit — all modules
**Standard:** WorkSafe New Zealand HSWA 2015 compliance

---

## 1. Executive Summary

The platform already has significant H&S infrastructure scattered across modules. There are **30+ H&S-related models** including incident tracking, hazard management, workplace injuries, emergency drills, hazardous substance management, safeguarding, restraint events, site inspections, fleet checklists, and an H&S committee/representative structure.

**What exists and works well:**
- ClientIncident with WorkSafe notification fields (is_notifiable, worksafe_reference, site_preserved)
- SafeguardingConcern with full investigation lifecycle
- SiteHazard with risk rating, control hierarchy, and residual risk
- WorkplaceInjury with ACC integration and return-to-work tracking
- ComprehensiveAlertBridgeService routing high-severity events to Control Room
- EmergencyDrill with participant tracking and findings
- HazardousSubstance/SDS/exposure tracking (HSNO compliant)
- HsCommittee, HsRepresentative, HsConsultation (HSWA worker participation)

**Critical gaps identified:**
1. **No unified H&S Event model** — incidents, hazards, injuries, and near-misses are separate entities with no common backbone (unlike FinFinancialEvent for finance)
2. **No formal risk assessment entity** — ClientRisk exists but is minimal (label, severity, controls text). No structured risk assessment with likelihood x consequence matrix
3. **No corrective action tracking system** — corrective actions are stored as JSON arrays on ClientIncident and text fields on SiteHazardAction, not as a trackable entity with lifecycle
4. **No PPE tracking** — no model exists for PPE allocation, condition, or replacement
5. **No staff H&S training integration** — HrCourse/HrCourseEnrollment exist but have no H&S-specific classification or mandatory training enforcement
6. **Fleet incidents disconnected** — FleetIncident has no link to the broader H&S event chain or WorkSafe notification pathway
7. **Asset safety inspections are thin** — AssetInspection has only 5 fields; no defect tracking, no compliance scheduling
8. **No H&S dashboard or KPI model** — no way to aggregate LTIFR, TRIFR, near-miss ratios, or compliance percentages
9. **Site compliance checks lack template linkage** — SiteComplianceCheck and SiteComplianceTemplate exist but aren't formally related
10. **Control Room bridge only handles high/critical severity** — medium-severity patterns and trend detection are invisible

---

## 2. Health & Safety Domain Model

### 2.1 Core Entities

#### HsEvent (NEW — Central Record)
**Purpose:** Single source of truth for every H&S-significant event across the platform. Equivalent to FinFinancialEvent for finance.

| Field | Type | Purpose |
|-------|------|---------|
| id | uuid | Primary key |
| organization_id | fk | Tenant isolation |
| reference_number | string | Auto-generated: HS-YYYY-NNNN |
| source_type | string | Polymorphic: ClientIncident, FleetIncident, SiteHazard, WorkplaceInjury, etc. |
| source_id | uuid | Polymorphic FK |
| event_category | enum | incident, near_miss, hazard, injury, exposure, restraint, safeguarding, drill_failure, inspection_failure, equipment_fault, vehicle_incident |
| event_date | datetime | When it happened |
| site_id | fk(nullable) | Where it happened |
| client_id | fk(nullable) | Affected client |
| staff_id | fk(nullable) | Involved/affected staff |
| asset_id | fk(nullable) | Involved asset/vehicle |
| shift_id | fk(nullable) | Active shift at time of event |
| severity | enum | low, medium, high, critical |
| worksafe_notifiable | boolean | Requires WorkSafe notification |
| worksafe_status | enum(nullable) | not_required, pending, notified, acknowledged |
| worksafe_reference | string(nullable) | WorkSafe reference number |
| status | enum | open, investigating, corrective_action, monitoring, closed |
| investigation_required | boolean | Auto-set based on severity rules |
| closed_at | datetime(nullable) | Closure timestamp |
| closed_by | fk(nullable) | Who closed |
| idempotency_key | string(unique) | sha256(source_type + source_id + event_category) |
| created_by | fk | Reporter |

**Lifecycle:** Created automatically when source event is logged -> triggers investigation if required -> corrective actions assigned -> monitoring period -> closed with evidence.

**Relationships:**
- Polymorphic to source (ClientIncident, FleetIncident, SiteHazard, WorkplaceInjury, SubstanceExposureRecord, RestraintEvent, EmergencyDrillFinding, AssetInspection, FleetChecklistRun)
- Has many HsCorrectiveAction
- Has many HsInvestigation
- Has one ControlRoomAlert (via bridge)
- Belongs to Site, Client, User (staff), Asset, Shift

---

#### HsRiskAssessment (NEW — Replaces minimal ClientRisk)
**Purpose:** Structured risk assessment using likelihood x consequence matrix. Applicable to sites, clients, tasks, substances, and equipment.

| Field | Type | Purpose |
|-------|------|---------|
| id | uuid | Primary key |
| organization_id | fk | Tenant |
| assessable_type | string | Polymorphic: Site, Client, HazardousSubstance, Asset, task context |
| assessable_id | uuid | Polymorphic FK |
| title | string | Assessment title |
| risk_description | text | What could go wrong |
| existing_controls | text | Current controls in place |
| likelihood | enum | rare, unlikely, possible, likely, almost_certain |
| consequence | enum | insignificant, minor, moderate, major, catastrophic |
| inherent_risk_score | integer | Auto-calculated: likelihood x consequence (1-25) |
| inherent_risk_level | enum | low, medium, high, extreme |
| additional_controls | text | Controls to be implemented |
| residual_likelihood | enum | Post-control likelihood |
| residual_consequence | enum | Post-control consequence |
| residual_risk_score | integer | Auto-calculated |
| residual_risk_level | enum | low, medium, high, extreme |
| risk_acceptable | boolean | Is residual risk acceptable? |
| assessed_by | fk | Assessor |
| assessed_at | datetime | Assessment date |
| review_due_at | date | Next review date |
| review_frequency_days | integer | Auto-schedule interval |
| status | enum | draft, current, under_review, superseded, archived |
| superseded_by_id | fk(nullable) | Links to newer version |
| approved_by | fk(nullable) | Approval authority |
| approved_at | datetime(nullable) | Approval timestamp |

**Lifecycle:** Draft -> Approved (current) -> Review due -> Under review -> New version created (supersedes old) -> Old marked superseded.

**Risk Matrix (NZ standard 5x5):**
```
                    Consequence
                 1    2    3    4    5
Likelihood  1  | 1  | 2  | 3  | 4  | 5  |
            2  | 2  | 4  | 6  | 8  | 10 |
            3  | 3  | 6  | 9  | 12 | 15 |
            4  | 4  | 8  | 12 | 16 | 20 |
            5  | 5  | 10 | 15 | 20 | 25 |

1-4: Low    5-9: Medium    10-15: High    16-25: Extreme
```

---

#### HsCorrectiveAction (NEW — Replaces JSON arrays)
**Purpose:** Trackable corrective/preventive actions arising from any H&S event.

| Field | Type | Purpose |
|-------|------|---------|
| id | uuid | Primary key |
| organization_id | fk | Tenant |
| hs_event_id | fk(nullable) | Parent H&S event |
| source_type | string | Polymorphic: HsEvent, SiteHazard, EmergencyDrillFinding, SiteInspectionRecord, HsRiskAssessment |
| source_id | uuid | Polymorphic FK |
| reference_number | string | CA-YYYY-NNNN |
| action_type | enum | corrective, preventive, improvement |
| priority | enum | low, medium, high, critical |
| description | text | What needs to be done |
| root_cause_link | text(nullable) | How this addresses the root cause |
| assigned_to_user_id | fk | Responsible person |
| assigned_by_user_id | fk | Who assigned |
| due_date | date | Deadline |
| status | enum | open, in_progress, completed, verified, overdue, cancelled |
| completed_at | datetime(nullable) | Completion timestamp |
| completed_by_user_id | fk(nullable) | Who completed |
| completion_evidence | text(nullable) | What was done |
| evidence_paths | json(nullable) | Uploaded evidence files |
| verified_at | datetime(nullable) | Verification timestamp |
| verified_by_user_id | fk(nullable) | Who verified effectiveness |
| verification_notes | text(nullable) | Verification outcome |
| effectiveness_confirmed | boolean(nullable) | Did the action resolve the issue? |
| escalated_at | datetime(nullable) | If overdue, when escalated |

**Lifecycle:** Open -> In Progress -> Completed -> Verified (effectiveness confirmed) OR Escalated if overdue.

---

#### HsInvestigation (NEW — Dedicated investigation entity)
**Purpose:** Formal investigation record for incidents, injuries, and significant events. Currently investigation fields are embedded in ClientIncident — this extracts them into a proper entity.

| Field | Type | Purpose |
|-------|------|---------|
| id | uuid | Primary key |
| organization_id | fk | Tenant |
| hs_event_id | fk | Parent H&S event |
| reference_number | string | INV-YYYY-NNNN |
| investigation_type | enum | initial, full, worksafe_directed |
| lead_investigator_id | fk | Lead investigator |
| team_members | json | Investigation team user IDs |
| started_at | datetime | Investigation start |
| target_completion_date | date | Expected completion |
| completed_at | datetime(nullable) | Actual completion |
| status | enum | assigned, in_progress, report_draft, under_review, completed, closed |
| methodology | enum | 5_whys, fishbone, bow_tie, icam, taproot |
| timeline_of_events | json | Structured timeline |
| immediate_causes | text | Direct causes |
| root_causes | text | Underlying causes |
| contributing_factors | json | Categorised contributing factors |
| systemic_factors | text(nullable) | Organisational/systemic issues |
| findings | text | Investigation findings |
| recommendations | text | Recommended actions |
| reviewed_by_user_id | fk(nullable) | Reviewer |
| reviewed_at | datetime(nullable) | Review date |
| review_notes | text(nullable) | Review comments |
| approved_by_user_id | fk(nullable) | Final approval |
| approved_at | datetime(nullable) | Approval date |

**Lifecycle:** Assigned -> In Progress -> Report Draft -> Under Review -> Completed -> Closed (after corrective actions verified).

---

#### HsPpeAllocation (NEW)
**Purpose:** Track PPE issued to staff, condition, and replacement schedules.

| Field | Type | Purpose |
|-------|------|---------|
| id | uuid | Primary key |
| organization_id | fk | Tenant |
| user_id | fk | Staff member |
| site_id | fk(nullable) | Primary site |
| ppe_type | enum | gloves, apron, face_shield, safety_glasses, ear_protection, non_slip_footwear, first_aid_kit, other |
| description | string | Specific item description |
| issued_at | date | Issue date |
| condition | enum | new, good, fair, replace |
| replacement_due_at | date(nullable) | Scheduled replacement |
| replaced_at | date(nullable) | Actual replacement |
| acknowledged_by_user | boolean | Staff acknowledged receipt |
| acknowledged_at | datetime(nullable) | Acknowledgement timestamp |
| notes | text(nullable) | Condition notes |

**Note:** PPE in supported living is limited compared to construction/manufacturing. Primary items: disposable gloves, aprons, face shields (COVID protocols), non-slip footwear. This model is intentionally lightweight.

---

#### HsTrainingRequirement (NEW — Links HR training to H&S compliance)
**Purpose:** Define mandatory H&S training requirements and track compliance.

| Field | Type | Purpose |
|-------|------|---------|
| id | uuid | Primary key |
| organization_id | fk | Tenant |
| hr_course_id | fk | Links to HrCourse |
| requirement_type | enum | mandatory_all, mandatory_role, mandatory_site, recommended |
| applicable_roles | json(nullable) | Which roles require this |
| applicable_site_ids | json(nullable) | Which sites require this |
| frequency_months | integer | Re-certification interval |
| grace_period_days | integer | Days after expiry before non-compliant |
| regulatory_reference | string(nullable) | HSWA section or regulation |
| is_active | boolean | Currently enforced |

**Relationships:**
- Belongs to HrCourse
- Through HrCourseEnrollment, determines staff compliance status

**Compliance check:** Staff is compliant if they have a completed HrCourseEnrollment for the linked HrCourse within the frequency_months window.

---

### 2.2 Existing Entities — Assessment

| Entity | Status | Action Required |
|--------|--------|-----------------|
| ClientIncident | Strong | Add hs_event_id FK; extract investigation fields to HsInvestigation; replace corrective_actions JSON with HsCorrectiveAction records |
| SafeguardingConcern | Strong | Add hs_event_id FK for cross-referencing; already has its own investigation chain |
| SiteHazard | Strong | Add hs_event_id FK; link SiteHazardAction to HsCorrectiveAction |
| WorkplaceInjury | Strong | Add hs_event_id FK; already links to ClientIncident |
| RestraintEvent | Good | Add hs_event_id FK; ensure all restraints create HsEvent records |
| FleetIncident | Weak | Add hs_event_id FK; add worksafe_notifiable, injury fields; needs Control Room bridge |
| EmergencyDrill | Good | No change needed; EmergencyDrillFinding already generates corrective actions |
| EmergencyDrillFinding | Good | Add hs_corrective_action_id FK to link into unified tracking |
| HazardousSubstance | Strong | No change needed |
| SubstanceExposureRecord | Good | Add hs_event_id FK |
| SiteInspectionRecord | Good | Add hs_event_id FK for failed inspections |
| SiteComplianceCheck | Adequate | Add compliance_template_id FK to formally link to SiteComplianceTemplate |
| AssetInspection | Weak | Needs defect_type, compliance_standard, defect_severity, corrective_action_required fields |
| FleetChecklistRun | Adequate | Add hs_event_id FK for failed runs; add defect linkage |
| HsCommittee | Good | No change needed |
| HsRepresentative | Good | No change needed |
| HsConsultation | Good | No change needed |
| ClientRisk | Weak | Superseded by HsRiskAssessment; migrate existing data; deprecate |

---

## 3. Cross-Module Integration Map

### 3.1 Shifts Module

**Integration points:**

| Trigger | Flow | Outcome |
|---------|------|---------|
| Incident during shift | ClientIncident.shift_id links to active shift | HsEvent created; staff and client linked; handover flagged |
| Shift handover | ShiftHandover must reference open H&S events at site | Incoming staff see active hazards, open incidents, client risk flags |
| Shift start | Pre-shift safety briefing prompt if site has open high/extreme hazards | Staff acknowledges hazard awareness |
| Staff injury on shift | WorkplaceInjury.related_incident_id links to ClientIncident if applicable | HsEvent created; ACC workflow triggered; shift may need coverage |
| Lone working | Shift with single staff at site | Control Room check-in protocol; escalation if no response |

**Data flow:**
```
Shift → ClientIncident → HsEvent → HsInvestigation → HsCorrectiveAction
  ↓
ShiftHandover (includes open H&S events for site)
```

**Implementation:** Add `openHsEventsForSite()` scope to HsEvent. ShiftHandover controller queries this when generating handover content.

---

### 3.2 HR Module

**Integration points:**

| Trigger | Flow | Outcome |
|---------|------|---------|
| Training expired | HsTrainingRequirement + HrCourseEnrollment check | HsComplianceAlert → Control Room (warn) |
| Training completed | HrCourseEnrollment marked complete | H&S compliance status updated |
| Staff incident history | HsEvent records where staff_id = user | Visible on HR profile; informs risk assessment |
| New hire onboarding | HsTrainingRequirement.mandatory_all items | Auto-enrolled in required H&S courses |
| Return to work | WorkplaceInjury.actual_return_date set | Graduated return plan; restrictions recorded |
| Disciplinary for safety | HrDisciplinaryAction linked to HsEvent | Cross-referenced for pattern detection |

**Data flow:**
```
HsTrainingRequirement → HrCourse → HrCourseEnrollment
                                        ↓
                              HrStaffComplianceStatus (H&S flag)
                                        ↓
                              Control Room (if expired)
```

**Implementation:** Extend existing HrComplianceMatrix to include H&S training requirements. The ComprehensiveAlertBridgeService already handles `training_expired` — extend it to check HsTrainingRequirement records.

---

### 3.3 Fleet Module

**Integration points:**

| Trigger | Flow | Outcome |
|---------|------|---------|
| Vehicle incident | FleetIncident created | HsEvent auto-created; Control Room alert; WorkSafe assessment |
| Pre-trip checklist fail | FleetChecklistRun.passed = false | HsEvent created (equipment_fault); vehicle status → defective; corrective action assigned |
| WOF/COF expired | Asset.wof_expires_at < now | Control Room alert (existing); vehicle grounded |
| Driver safety metric breach | FleetDrivingMetric threshold exceeded | Alert to fleet manager; training recommendation |
| Medication in transit | FleetMedicationTransitLog | If temperature breach or loss → HsEvent |

**Data flow:**
```
FleetIncident → HsEvent → HsInvestigation (if injury or serious damage)
                   ↓
            HsCorrectiveAction (vehicle repair, driver retraining)
                   ↓
            FinFinancialEvent (repair cost, insurance claim)

FleetChecklistRun (failed) → HsEvent → vehicle grounded → FleetWorkOrder
```

**Gap:** FleetIncident currently has no WorkSafe notification fields and no bridge to Control Room. This is a critical gap — vehicle accidents involving staff or clients are notifiable events.

---

### 3.4 Assets Module

**Integration points:**

| Trigger | Flow | Outcome |
|---------|------|---------|
| Inspection failed | AssetInspection.result = 'fail' | HsEvent created (equipment_fault); asset status → defective |
| Maintenance overdue | AssetMaintenanceLog next_due exceeded | Control Room alert; asset flagged |
| Equipment fault reported | Via asset defect report | HsEvent; corrective action; if safety-critical → asset quarantined |
| Asset incident link | AssetIncidentLink to ClientIncident | Cross-reference in HsEvent |

**Gap:** AssetInspection model is too thin. Needs: defect_type, compliance_standard, defect_severity, defect_description, corrective_action_required, follow_up_date.

---

### 3.5 Sites Module

**Integration points:**

| Trigger | Flow | Outcome |
|---------|------|---------|
| Hazard reported | SiteHazard created | HsEvent auto-created; risk assessment required if high/extreme |
| Inspection failed | SiteInspectionRecord.result = 'fail' | HsEvent created; corrective actions assigned |
| Compliance check failed | SiteComplianceCheck.status = 'failed' | HsEvent; escalation based on check_type |
| Fire drill failed | EmergencyDrill.outcome = 'failed' | HsEvent; findings become corrective actions |
| Site certified as high-risk | Site.is_high_risk = true | Triggers additional inspection frequency; higher staffing ratios |
| Fire system maintenance | SiteInspectionSchedule (fire type) | Scheduled inspections with compliance tracking |

**Existing strength:** SiteHazard already has risk_rating, control_hierarchy, residual_risk — this is well-built. SiteInspectionSchedule handles recurring inspections.

**Gap:** No formal fire safety compliance entity (alarm test records, extinguisher inspections, evacuation plan reviews). These are currently handled through generic SiteComplianceCheck but lack specificity.

---

### 3.6 Clients Module

**Integration points:**

| Trigger | Flow | Outcome |
|---------|------|---------|
| Incident logged | ClientIncident created | HsEvent auto-created; severity determines investigation requirement |
| Risk profile updated | HsRiskAssessment (assessable = Client) | Visible to shift staff; informs care planning |
| Behavioural risk | ClientRisk or HsRiskAssessment for behaviour | Informs BehaviourSupportPlan; restraint protocols |
| Medication error | MedicationError → ClientIncident | HsEvent; bridge to Control Room already exists |
| Safeguarding concern | SafeguardingConcern created | HsEvent; mandatory external referral assessment |
| Restraint used | RestraintEvent created | HsEvent; mandatory review within 24h |
| Client consent for risk | ClientConsent for high-risk activities | Documented consent before activity proceeds |

**Existing strength:** ClientIncident is the most mature H&S entity — WorkSafe fields, investigation fields, severity classification, template-based reporting.

---

### 3.7 Control Room

**Integration points:** See Section 6 (dedicated section below).

---

### 3.8 Finance Module

**Integration points:**

| Trigger | Flow | Outcome |
|---------|------|---------|
| Vehicle repair from incident | FleetWorkOrder → FinFinancialEvent | Cost allocated to H&S cost centre |
| ACC claim costs | WorkplaceInjury.acc_claim_lodged | Track ACC levies, claim costs |
| Training costs | HrCourseEnrollment → FinFinancialEvent (training_cost) | Already exists; tag as H&S training |
| Compliance costs | External audit, certification renewal | FinFinancialEvent with hs_compliance event_type |
| Insurance claims | FleetIncident.insurance_claimed | Track claim amounts, excesses |
| Lost time cost | WorkplaceInjury.lost_time_days × daily rate | FinFinancialEvent (lost_time_cost) |

**Implementation:** Add event_types to FinFinancialEvent: `hs_incident_cost`, `hs_training_cost`, `hs_compliance_cost`, `hs_lost_time_cost`, `hs_insurance_claim`. Add `hs_event_id` nullable FK to FinFinancialEvent for cross-reference.

---

## 4. Event Flow Design

### 4.1 Incident Logged

```
TRIGGER: Staff submits ClientIncident form
  ↓
ClientIncident created (status: draft → submitted)
  ↓
HsEvent auto-created via observer/listener
  ├── event_category: incident (or near_miss if type = near_miss)
  ├── severity: from ClientIncident.severity
  ├── site_id, client_id, staff_id, shift_id: from incident
  ├── worksafe_notifiable: auto-assessed (injury requiring hospitalisation,
  │   serious harm, death, or notifiable disease)
  └── idempotency_key: sha256(ClientIncident:{id}:incident)
  ↓
IF severity >= high OR worksafe_notifiable:
  ├── ComprehensiveAlertBridgeService.bridgeHsEvent(hsEvent)
  │   → ControlRoomAlert created (severity mapped)
  │   → SLA attached (4h response for critical, 24h for high)
  │   → Assigned to triage queue
  ├── investigation_required = true
  └── HsInvestigation auto-created (status: assigned)
  ↓
IF worksafe_notifiable:
  ├── Notification to H&S Officer
  ├── worksafe_status = pending
  ├── Site preservation flag set
  └── 48-hour notification deadline tracked
  ↓
HsCorrectiveAction records created from investigation recommendations
  ↓
Corrective actions tracked to completion and verification
  ↓
HsEvent closed when all corrective actions verified
```

### 4.2 Hazard Identified

```
TRIGGER: Staff reports hazard via site hazard form
  ↓
SiteHazard created (status: open)
  ↓
HsEvent auto-created
  ├── event_category: hazard
  ├── severity: from risk_rating
  └── site_id: from hazard
  ↓
IF risk_rating = high OR extreme:
  ├── Control Room alert (warn/critical)
  ├── Immediate control required
  ├── Area/equipment isolated if needed
  └── HsRiskAssessment required within 24h
  ↓
SiteHazardAction records created
  → Linked to HsCorrectiveAction for unified tracking
  ↓
Controls implemented → residual risk assessed
  ↓
IF residual_risk acceptable → SiteHazard closed → HsEvent closed
IF residual_risk NOT acceptable → escalate → additional controls
```

### 4.3 Inspection Failed

```
TRIGGER: SiteInspectionRecord.result = 'fail' OR FleetChecklistRun.passed = false
  ↓
HsEvent auto-created
  ├── event_category: inspection_failure
  ├── severity: based on inspection type and findings
  └── Links to site/asset
  ↓
IF safety-critical inspection (fire, electrical, gas):
  ├── Control Room alert (critical)
  ├── Immediate remediation required
  └── Site/equipment may be quarantined
  ↓
HsCorrectiveAction created for each finding
  ↓
Re-inspection scheduled after remediation
  ↓
Re-inspection passed → HsEvent closed
Re-inspection failed → escalation
```

### 4.4 Training Expired

```
TRIGGER: Scheduled job checks HsTrainingRequirement against HrCourseEnrollment
  ↓
Staff member's H&S training past expiry + grace period
  ↓
ComprehensiveAlertBridgeService.bridgeComplianceExpiry(
  type: 'hs_training_expired',
  severity: based on requirement_type
)
  → Control Room alert (warn for recommended, critical for mandatory)
  ↓
Notification to:
  ├── Staff member
  ├── Line manager
  └── H&S Officer (if mandatory training)
  ↓
IF mandatory training expired:
  ├── Staff restricted from relevant duties
  ├── Shift eligibility affected
  └── HrStaffComplianceStatus updated (non-compliant)
  ↓
Training completed → compliance restored → alert resolved
```

### 4.5 Equipment Fault Reported

```
TRIGGER: Asset defect logged OR FleetChecklistRun item failed
  ↓
HsEvent auto-created (event_category: equipment_fault)
  ↓
IF safety-critical equipment:
  ├── Asset status → defective/quarantined
  ├── Control Room alert
  └── Out of service until repaired
  ↓
FleetWorkOrder OR maintenance request created
  → HsCorrectiveAction linked
  → FinFinancialEvent for repair cost
  ↓
Repair completed → inspection → asset returned to service → HsEvent closed
```

### 4.6 Workplace Injury

```
TRIGGER: WorkplaceInjury record created
  ↓
HsEvent auto-created (event_category: injury)
  ↓
IF worksafe_notifiable (serious harm):
  ├── Control Room alert (critical)
  ├── Site preservation
  ├── WorkSafe notification within 48h (for deaths: immediately)
  ├── H&S Officer notified immediately
  └── Investigation mandatory
  ↓
ACC claim process:
  ├── acc_claim_lodged = true
  ├── Medical certificate obtained
  └── Return to work plan created (ReturnToWorkPlan)
  ↓
IF lost_time > 0:
  ├── FinFinancialEvent (hs_lost_time_cost)
  ├── Shift coverage arranged
  └── Graduated return tracked via WorkCapacityAssessment
  ↓
Investigation completed → corrective actions → verified → HsEvent closed
```

### 4.7 Restraint Event

```
TRIGGER: RestraintEvent created
  ↓
HsEvent auto-created (event_category: restraint)
  ├── severity: based on duration, injury, within_support_plan
  ├── Always triggers review requirement
  └── Links to client, site, staff involved
  ↓
IF injury_occurred OR NOT within_support_plan:
  ├── Control Room alert (high)
  ├── Investigation required
  └── Safeguarding assessment triggered
  ↓
Mandatory debrief within 24h
  ├── With client (age/capacity appropriate)
  ├── With staff involved
  └── Review of BehaviourSupportPlan
  ↓
HsCorrectiveAction if support plan needs updating
  ↓
HsEvent closed after debrief and any plan updates
```

### 4.8 Emergency Drill Failure

```
TRIGGER: EmergencyDrill.outcome = 'failed' OR evacuation_time_seconds > threshold
  ↓
HsEvent auto-created (event_category: drill_failure)
  ↓
EmergencyDrillFinding records → HsCorrectiveAction records
  ↓
Control Room alert if critical findings (blocked exits, missing residents)
  ↓
Re-drill scheduled within corrective action timeline
  ↓
Re-drill passed → HsEvent closed
```

---

## 5. Single Source of Truth Architecture

### 5.1 The HsEvent Model — Central Backbone

Just as `FinFinancialEvent` is the single entry point for all financial transactions, `HsEvent` is the single entry point for all H&S-significant events.

**Design principles:**
1. **Every H&S-significant event creates one HsEvent** — no exceptions
2. **Source models retain their detail** — HsEvent is the index, not the container
3. **Cross-module queries go through HsEvent** — "show me all H&S events at Site X" queries HsEvent, not 8 different tables
4. **Idempotency prevents duplicates** — same source event cannot create two HsEvents
5. **Lifecycle is tracked centrally** — status progression is on HsEvent regardless of source type

### 5.2 How Modules Link to HsEvent

```
┌─────────────────────────────────────────────────┐
│                    HsEvent                       │
│  (source_type, source_id — polymorphic)          │
│  (site_id, client_id, staff_id, asset_id,        │
│   shift_id — contextual FKs)                     │
├─────────────────────────────────────────────────┤
│ Sources:                                         │
│  ├── ClientIncident      (incident, near_miss)   │
│  ├── FleetIncident       (vehicle_incident)      │
│  ├── SiteHazard          (hazard)                │
│  ├── WorkplaceInjury     (injury)                │
│  ├── SubstanceExposure   (exposure)              │
│  ├── RestraintEvent      (restraint)             │
│  ├── SafeguardingConcern (safeguarding)          │
│  ├── EmergencyDrillFinding (drill_failure)       │
│  ├── SiteInspectionRecord (inspection_failure)   │
│  ├── AssetInspection     (equipment_fault)       │
│  └── FleetChecklistRun   (equipment_fault)       │
├─────────────────────────────────────────────────┤
│ Downstream:                                      │
│  ├── HsInvestigation     (formal investigation)  │
│  ├── HsCorrectiveAction  (actions & tracking)    │
│  ├── HsRiskAssessment    (risk evaluation)       │
│  ├── ControlRoomAlert    (alerting & escalation)  │
│  └── FinFinancialEvent   (cost tracking)         │
└─────────────────────────────────────────────────┘
```

### 5.3 Duplication Prevention

| Risk | Prevention |
|------|------------|
| Same incident creates multiple HsEvents | Idempotency key: sha256(source_type + source_id + event_category) with unique constraint |
| Corrective actions tracked in both source and HsCorrectiveAction | Source models (ClientIncident.corrective_actions JSON, SiteHazardAction) become read-only references; HsCorrectiveAction is the live tracker |
| Investigation tracked on ClientIncident AND HsInvestigation | ClientIncident investigation fields become denormalized summaries; HsInvestigation is the source of truth |
| Same event alerts Control Room multiple times | ComprehensiveAlertBridgeService already deduplicates (30-min window) — extend to use HsEvent.id as dedup key |

### 5.4 Query Patterns

```php
// All open H&S events at a site
HsEvent::where('site_id', $siteId)->where('status', '!=', 'closed')->get();

// All events involving a client
HsEvent::where('client_id', $clientId)->with('source')->get();

// Overdue corrective actions
HsCorrectiveAction::where('status', 'open')
    ->where('due_date', '<', now())->get();

// LTIFR calculation
$lostTimeInjuries = HsEvent::where('event_category', 'injury')
    ->whereHas('source', fn($q) => $q->where('lost_time_days', '>', 0))
    ->whereBetween('event_date', [$start, $end])
    ->count();
$hoursWorked = Timesheet::whereBetween('date', [$start, $end])
    ->sum('total_hours');
$ltifr = ($lostTimeInjuries / $hoursWorked) * 1_000_000;

// WorkSafe notification compliance
HsEvent::where('worksafe_notifiable', true)
    ->where('worksafe_status', 'pending')
    ->where('event_date', '<', now()->subHours(48))->get(); // Overdue notifications
```

---

## 6. Control Room Integration Design

### 6.1 Events That Trigger Alerts

| Event | Severity | SLA | Auto-Escalation |
|-------|----------|-----|-----------------|
| Death or serious harm | critical | 15 min acknowledge, 1h response | Immediate: CEO, H&S Officer, Board Chair |
| WorkSafe-notifiable incident | critical | 30 min acknowledge, 4h response | 1h: H&S Officer; 4h: Regional Manager |
| High-severity incident | high | 1h acknowledge, 8h response | 4h: Site Manager; 8h: Regional Manager |
| Restraint with injury | high | 1h acknowledge, 4h debrief | 2h: Clinical Lead; 4h: H&S Officer |
| Restraint without injury | medium | 4h acknowledge, 24h review | 12h: Clinical Lead |
| Safeguarding concern (any) | high (minimum) | 30 min acknowledge, 4h assessment | 2h: Safeguarding Lead; 4h: Regional Manager |
| Hazard — extreme risk | critical | 30 min acknowledge, 2h control | 1h: Site Manager; 2h: Operations Manager |
| Hazard — high risk | high | 2h acknowledge, 24h control | 8h: Site Manager |
| Fire inspection failed | critical | 1h acknowledge, 24h remediation | 4h: Facilities Manager |
| Equipment fault (safety-critical) | high | 2h acknowledge, 8h resolution | 4h: Asset Manager |
| Vehicle incident (injury) | critical | 30 min acknowledge, 4h response | 1h: Fleet Manager; 4h: H&S Officer |
| Vehicle incident (damage only) | medium | 4h acknowledge, 24h response | 12h: Fleet Manager |
| Mandatory H&S training expired | warn | 24h acknowledge | 48h: Line Manager; 72h: HR Manager |
| Emergency drill failed | medium | 4h acknowledge, 7d corrective | 3d: Site Manager |
| Substance exposure | high | 1h acknowledge, 4h response | 2h: H&S Officer |
| Corrective action overdue | warn → high | 24h acknowledge | 48h: escalate to assigner's manager |
| Multiple near-misses (same site, 7d) | medium | 4h acknowledge | Pattern alert — investigation recommended |

### 6.2 Severity Mapping

```
CRITICAL (immediate danger to life or regulatory breach):
  - Death, serious harm, WorkSafe-notifiable
  - Fire safety failure
  - Extreme-risk hazard uncontrolled

HIGH (significant risk, needs prompt action):
  - High-severity incidents
  - Safeguarding concerns
  - Restraint with injury
  - Vehicle incident with injury
  - Substance exposure

MEDIUM (needs attention within shift):
  - Restraint without injury
  - Vehicle incident (damage only)
  - Emergency drill failure
  - Near-miss patterns

WARN (compliance/administrative):
  - Training expiry
  - Overdue corrective actions
  - Upcoming inspection deadlines
```

### 6.3 Escalation Paths

```
Level 0: Auto-assigned to triage queue
  ↓ (SLA acknowledge deadline)
Level 1: Assigned operator / site manager
  ↓ (SLA response deadline)
Level 2: Regional Manager / Department Head
  ↓ (SLA resolution deadline)
Level 3: Operations Director / H&S Officer
  ↓ (Critical only)
Level 4: CEO / Board notification
```

### 6.4 Resolution Tracking

Every Control Room alert from H&S must be resolved with:
1. **Immediate action taken** — what was done right away
2. **Root cause identified** — or investigation initiated
3. **Corrective actions assigned** — with due dates and owners
4. **Evidence attached** — photos, statements, forms

Alert cannot be closed until:
- All linked HsCorrectiveAction records are at minimum `completed` status
- For WorkSafe-notifiable: notification confirmed
- For safeguarding: external referral made if required
- Investigation completed (if required)

### 6.5 Bridge Service Extension

Extend `ComprehensiveAlertBridgeService` with:

```php
public function bridgeHsEvent(HsEvent $event): ?ControlRoomAlert
{
    // All H&S events with severity >= medium get bridged
    // Dedup on hs_event_id (not just 30-min window)
    // Severity and SLA mapped from event_category + severity
    // Escalation path determined by event_category
}
```

This replaces the current individual bridges (`bridgeClientIncident`, etc.) with a single entry point that uses HsEvent as the routing layer.

---

## 7. Compliance & Audit Model

### 7.1 Required Audit Trails

Every H&S record must capture:

| Field | Purpose | Implementation |
|-------|---------|----------------|
| created_by | Who created the record | User FK (existing via AuditableChanges trait) |
| created_at | When created | Timestamp (existing) |
| updated_by | Who last modified | User FK (existing via AuditableChanges trait) |
| updated_at | When last modified | Timestamp (existing) |
| Full change history | What changed, old value, new value | AuditableChanges trait logs all field changes |
| Soft deletes | Records never hard-deleted | SoftDeletes trait (existing on most H&S models) |

**The AuditableChanges trait already provides complete field-level audit logging.** This is a significant strength. Every model using this trait has a full history of who changed what and when.

### 7.2 Evidence Tracking

| Evidence Type | Model | Storage |
|---------------|-------|---------|
| Incident photos/documents | ClientIncidentAttachment | S3/disk with path reference |
| Investigation evidence | HsAttachment (polymorphic to HsInvestigation) | S3/disk |
| Corrective action evidence | HsCorrectiveAction.evidence_paths | S3/disk |
| Inspection evidence | SiteInspectionRecord.evidence_photos | S3/disk |
| Hazard photos | SiteHazard.photo_paths | S3/disk |
| Emergency drill records | EmergencyDrill (full model) | Database |
| Training certificates | HrCourseEnrollment + document uploads | S3/disk |
| WorkSafe correspondence | HsEvent attachment | S3/disk |
| Witness statements | Linked to HsInvestigation | S3/disk |

### 7.3 Regulatory Compliance Requirements

#### HSWA 2015 (Health and Safety at Work Act)

| Requirement | Section | Implementation |
|-------------|---------|----------------|
| Duty to notify WorkSafe of notifiable events | s56 | HsEvent.worksafe_notifiable + worksafe_status tracking + 48h deadline alert |
| Preserve site after notifiable event | s60 | HsEvent.site_preserved flag + ClientIncident.site_preserved |
| Worker participation | Part 4 | HsCommittee, HsRepresentative, HsConsultation models |
| Risk management | s30 | HsRiskAssessment with 5x5 matrix, control hierarchy |
| Provide information and training | s36 | HsTrainingRequirement + HrCourse integration |
| Monitor health and conditions | s36(d) | SiteInspectionSchedule, SubstanceExposureRecord |
| Maintain work environment | s36(c) | SiteHazard, SiteComplianceCheck, EmergencyDrill |
| Engage with workers on H&S | s58-62 | HsConsultation records with worker feedback |

#### Notifiable Events (HSWA s25)

| Event | Notification Deadline | System Check |
|-------|----------------------|--------------|
| Death | Immediately | HsEvent severity=critical + event_category=injury → auto-flag |
| Notifiable injury/illness | As soon as possible | WorkplaceInjury.worksafe_notifiable assessment |
| Notifiable incident (serious risk) | As soon as possible | HsEvent.worksafe_notifiable = true |
| Dangerous incident | As soon as possible | Auto-flagged based on event criteria |

#### Supported Living Specific

| Requirement | Source | Implementation |
|-------------|--------|----------------|
| Restraint reporting | HDSS standards | RestraintEvent with mandatory 24h review |
| Medication incident tracking | HDSS standards | ClientIncident type=medication + MedicationError bridge |
| Safeguarding external referral | VCA 2014 | SafeguardingConcern.requires_external_referral |
| Client risk assessments | Funding agreements | HsRiskAssessment (assessable=Client) |
| Fire safety drills | Fire Safety Regs | EmergencyDrill with frequency tracking |
| Vehicle safety (WOF/COF) | Land Transport Act | Asset.wof_expires_at/cof_expires_at with alerts |

### 7.4 Reporting Outputs

| Report | Frequency | Data Source | Audience |
|--------|-----------|-------------|----------|
| LTIFR / TRIFR | Monthly | HsEvent (injury) + Timesheet hours | Board, WorkSafe |
| Incident summary | Monthly | HsEvent grouped by category, severity, site | Management, H&S Committee |
| Near-miss ratio | Monthly | HsEvent (near_miss) / HsEvent (incident) | H&S Committee |
| Open corrective actions | Weekly | HsCorrectiveAction where status != verified | Site Managers, H&S Officer |
| Overdue actions | Weekly | HsCorrectiveAction where due_date < now | Management (escalation) |
| Training compliance | Monthly | HsTrainingRequirement vs HrCourseEnrollment | HR, H&S Officer |
| Hazard register | Live | SiteHazard where status = open | Site staff, H&S Committee |
| WorkSafe notifications | As required | HsEvent where worksafe_notifiable = true | H&S Officer, CEO |
| Risk assessment register | Quarterly | HsRiskAssessment (current, review due) | Board, H&S Committee |
| Site compliance status | Monthly | SiteComplianceCheck + SiteInspectionRecord | Facilities, Management |
| Restraint report | Monthly | RestraintEvent summary | Clinical Lead, Board |
| Fleet safety report | Monthly | FleetIncident + FleetChecklistRun failures | Fleet Manager |
| Emergency preparedness | Quarterly | EmergencyDrill outcomes + findings status | H&S Committee |
| H&S cost report | Quarterly | FinFinancialEvent (hs_* event_types) | Finance, Board |

---

## 8. Gap Analysis

### 8.1 Critical Gaps

| # | Gap | Risk | Priority |
|---|-----|------|----------|
| G1 | No unified HsEvent model | Cannot query H&S events cross-module; no single view for auditors | P0 |
| G2 | FleetIncident not bridged to Control Room | Vehicle accidents with injury go unescalated | P0 |
| G3 | No formal corrective action tracking | Corrective actions stored as JSON arrays — untraceable, no deadlines, no verification | P0 |
| G4 | No structured risk assessment | ClientRisk has 4 fields; no likelihood x consequence matrix; no review cycle | P0 |
| G5 | FleetIncident missing WorkSafe notification fields | Vehicle-related serious harm cannot be properly notified | P1 |

### 8.2 Significant Gaps

| # | Gap | Risk | Priority |
|---|-----|------|----------|
| G6 | No H&S training enforcement | Staff can work shifts without mandatory H&S training | P1 |
| G7 | AssetInspection model too thin | Equipment safety inspections lack defect tracking, compliance standards | P1 |
| G8 | No PPE tracking | Cannot demonstrate PPE provision for WorkSafe | P1 |
| G9 | Investigation entity doesn't exist | Investigation data embedded in ClientIncident; no formal process for non-client investigations | P1 |
| G10 | SiteComplianceCheck not linked to SiteComplianceTemplate | Cannot track which template a check was performed against | P2 |

### 8.3 Moderate Gaps

| # | Gap | Risk | Priority |
|---|-----|------|----------|
| G11 | No H&S dashboard/KPI model | LTIFR, TRIFR, near-miss ratios not calculated | P2 |
| G12 | No trend detection for near-misses | Pattern of near-misses at same site/with same client not flagged | P2 |
| G13 | Shift handover doesn't include open H&S events | Incoming staff unaware of active hazards | P2 |
| G14 | No formal fire safety compliance entity | Generic compliance checks lack fire-specific fields | P2 |
| G15 | No H&S cost tracking via FinFinancialEvent | Cannot report on cost of incidents, compliance, lost time | P3 |

### 8.4 Duplication Risks

| Risk | Current State | Resolution |
|------|---------------|------------|
| ClientIncident.corrective_actions (JSON) vs HsCorrectiveAction | Both will exist during migration | Phase: migrate JSON data to HsCorrectiveAction; deprecate JSON field |
| ClientIncident investigation fields vs HsInvestigation | Both will exist during migration | Phase: HsInvestigation becomes source of truth; ClientIncident fields become computed/denormalized |
| SiteHazardAction vs HsCorrectiveAction | Overlapping purpose | SiteHazardAction becomes a lightweight record; HsCorrectiveAction handles lifecycle tracking |
| ClientRisk vs HsRiskAssessment | ClientRisk is minimal, HsRiskAssessment is comprehensive | Migrate ClientRisk data to HsRiskAssessment; deprecate ClientRisk |

### 8.5 Compliance Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| WorkSafe notification deadline missed | Prosecution under HSWA s56 | HsEvent with 48h deadline tracking + critical Control Room alert |
| Site not preserved after notifiable event | Evidence destruction; prosecution | Auto-flag + alert; physical lock procedure in playbook |
| Mandatory training not tracked | Staff working without competency | HsTrainingRequirement + shift eligibility block |
| Restraint not reviewed within 24h | Regulatory non-compliance | RestraintEvent → HsEvent → 24h SLA in Control Room |
| Risk assessments not reviewed | Outdated controls; incidents | HsRiskAssessment.review_due_at + scheduled job + alert |

---

## 9. Implementation Roadmap

### Phase 1: Core H&S Event Backbone (Foundation)

**Scope:**
- Create HsEvent model, migration, observer
- Create HsCorrectiveAction model, migration
- Create HsInvestigation model, migration
- Create HsRiskAssessment model, migration
- Add hs_event_id FK to: ClientIncident, SiteHazard, WorkplaceInjury, FleetIncident, RestraintEvent, SubstanceExposureRecord
- Auto-creation logic: observers on source models create HsEvent records
- Idempotency protection

**Dependencies:** None — this is foundational.

**Risks:**
- Observer performance on high-volume models (ClientIncident) — mitigate with queue-dispatched creation
- Data migration for existing records — backfill script needed

**Deliverable:** Every new H&S-significant event automatically creates an HsEvent. Existing records backfilled.

---

### Phase 2: Corrective Action & Investigation System

**Scope:**
- HsCorrectiveAction full lifecycle (assign, track, complete, verify)
- HsInvestigation full lifecycle (assign team, methodology, findings, recommendations)
- Migrate ClientIncident.corrective_actions JSON data to HsCorrectiveAction records
- Link EmergencyDrillFinding to HsCorrectiveAction
- Link SiteHazardAction to HsCorrectiveAction
- Overdue corrective action detection job
- Corrective action dashboard

**Dependencies:** Phase 1 (HsEvent must exist).

**Risks:**
- JSON-to-record migration may have inconsistent data — manual review required for high-severity incidents
- Staff workflow change (corrective actions now have formal tracking)

**Deliverable:** Every corrective action is tracked with owner, deadline, evidence, and verification. No more untraceable JSON arrays.

---

### Phase 3: Risk Assessment & Training Compliance

**Scope:**
- HsRiskAssessment with 5x5 matrix
- Migrate ClientRisk data to HsRiskAssessment
- Risk assessment review scheduling (automated)
- HsTrainingRequirement model
- Link HsTrainingRequirement to HrCourse
- Training compliance check job
- HsPpeAllocation model
- Shift eligibility check: block if mandatory H&S training expired

**Dependencies:** Phase 1 (HsEvent for assessment-triggered events), HR module (HrCourse, HrCourseEnrollment).

**Risks:**
- Blocking shifts for expired training may cause rostering gaps initially — grace_period_days provides buffer
- Risk assessment rollout requires training for site managers

**Deliverable:** Structured risk assessments with review cycles. H&S training formally tracked and enforced. PPE allocation recorded.

---

### Phase 4: Cross-Module Integration

**Scope:**
- Fleet: Add WorkSafe fields to FleetIncident; bridge FleetIncident to Control Room; link failed FleetChecklistRun to HsEvent
- Assets: Extend AssetInspection with defect fields; link failed inspections to HsEvent
- Sites: Link SiteComplianceCheck to SiteComplianceTemplate; failed inspections auto-create HsEvent
- Shifts: ShiftHandover includes open HsEvents for site; pre-shift hazard acknowledgement
- HR: HrStaffComplianceStatus includes H&S training status
- Finance: Add hs_* event_types to FinFinancialEvent; cost tracking for incidents

**Dependencies:** Phases 1-3 (all core entities must exist).

**Risks:**
- Touching many modules increases regression risk — feature flag each integration
- Fleet and Asset modules may need UI updates for new fields

**Deliverable:** H&S data flows seamlessly across all modules. No module is a data island.

---

### Phase 5: Control Room Integration & Alerting

**Scope:**
- Extend ComprehensiveAlertBridgeService with `bridgeHsEvent()` method
- Route all H&S events through single bridge method
- Define SLA definitions for each H&S event category
- Escalation path configuration per category
- Near-miss pattern detection (same site, 7-day window)
- Overdue corrective action escalation
- WorkSafe notification deadline tracking (48h alert)
- Playbook templates for H&S response procedures

**Dependencies:** Phase 4 (all events must be flowing through HsEvent).

**Risks:**
- Alert fatigue if thresholds too low — start with high/critical only, tune down
- SLA definitions need operations input for realistic response times

**Deliverable:** Control Room is the single pane of glass for all H&S alerts. Automated escalation ensures nothing falls through the cracks.

---

### Phase 6: Compliance Reporting & Dashboard

**Scope:**
- LTIFR / TRIFR calculation engine
- Near-miss ratio reporting
- Training compliance dashboard
- Open corrective actions dashboard
- Risk assessment register view
- Site compliance scorecards
- WorkSafe notification log
- H&S cost reporting (via FinFinancialEvent)
- Board-level H&S summary report
- H&S Committee meeting pack generator
- Scheduled report generation jobs

**Dependencies:** Phases 1-5 (all data must be flowing and tracked).

**Risks:**
- LTIFR calculation depends on accurate timesheet hours — validate data quality first
- Report performance on large datasets — use materialized views or snapshot tables

**Deliverable:** Complete H&S reporting suite that satisfies WorkSafe audit, board reporting, and operational management needs.

---

### Phase Summary

| Phase | Focus | Estimated Entities | Dependencies |
|-------|-------|-------------------|--------------|
| 1 | HsEvent backbone | 4 new models, 6 FKs added | None |
| 2 | Corrective actions & investigations | 0 new models (built in P1), lifecycle logic, data migration | Phase 1 |
| 3 | Risk assessment & training | 2 new models, 1 data migration | Phase 1, HR |
| 4 | Cross-module wiring | FK additions, field extensions across 6 modules | Phases 1-3 |
| 5 | Control Room integration | Bridge service extension, SLA config, playbooks | Phase 4 |
| 6 | Reporting & dashboards | Report engines, dashboard views, scheduled jobs | Phases 1-5 |

---

## Appendix A: Entity Relationship Summary

```
HsEvent (central)
├── source → polymorphic (ClientIncident, FleetIncident, SiteHazard,
│            WorkplaceInjury, RestraintEvent, SubstanceExposureRecord,
│            EmergencyDrillFinding, SiteInspectionRecord, AssetInspection,
│            FleetChecklistRun, SafeguardingConcern)
├── HsInvestigation (1:many)
│   ├── lead_investigator → User
│   ├── team_members → [User]
│   └── attachments → HsAttachment (polymorphic)
├── HsCorrectiveAction (1:many)
│   ├── assigned_to → User
│   ├── verified_by → User
│   └── evidence_paths → [files]
├── HsRiskAssessment (many — via assessable polymorphic on site/client/asset)
│   ├── assessed_by → User
│   ├── approved_by → User
│   └── superseded_by → HsRiskAssessment
├── ControlRoomAlert (1:1 via bridge)
│   ├── SLA → AlertSla
│   ├── escalation_level
│   └── playbook_run → PlaybookRun
├── FinFinancialEvent (1:many — cost tracking)
│   ├── debit/credit accounts
│   └── cost_centre
└── Context FKs
    ├── site → Site
    ├── client → Client
    ├── staff → User
    ├── asset → Asset
    └── shift → Shift
```

## Appendix B: WorkSafe Notification Decision Tree

```
Is anyone dead?
  YES → Notify WorkSafe IMMEDIATELY. Do not disturb scene. → CRITICAL alert
  NO ↓

Has anyone suffered serious injury requiring:
  - Hospitalisation for 48h+ within 7 days?
  - Amputation?
  - Serious burns?
  - Spinal injury?
  - Loss of consciousness from head injury?
  - Loss of consciousness from substance exposure?
  YES → Notify WorkSafe ASAP (within 48h). Preserve scene. → CRITICAL alert
  NO ↓

Did a dangerous incident occur:
  - Uncontrolled escape of substance?
  - Uncontrolled fire or explosion?
  - Uncontrolled escape of gas/steam?
  - Imminent risk of any of the above?
  YES → Notify WorkSafe ASAP (within 48h). → HIGH alert
  NO ↓

Not a notifiable event.
  Record normally. Investigate if severity warrants.
```

## Appendix C: Supported Living Specific Considerations

1. **Clients are not workers** — HSWA duties apply to workers and "others in the workplace" (clients are the latter). Incident tracking must distinguish staff injuries (WorkplaceInjury) from client incidents (ClientIncident).

2. **Homes are workplaces** — residential houses where clients live are PCBUs (Persons Conducting a Business or Undertaking) workplaces. All site H&S requirements apply.

3. **Restraint is a last resort** — every restraint event must be documented, reviewed, and connected to the behaviour support plan. Patterns of restraint increase scrutiny.

4. **Medication incidents are common** — the existing medication → incident → Control Room pipeline is strong. Ensure medication errors also create HsEvent records for unified tracking.

5. **Lone workers** — many shifts are single-staff. Lone worker protocols need check-in systems (existing via Control Room) and escalation if no response.

6. **Community settings** — incidents happen in vehicles, in the community, at appointments. Site_id may be null for these. Location capture (lat/lng) is important.

7. **Family/whanau involvement** — incident notifications may need to go to family contacts (ClientEmergencyContact). Portal visibility (ClientIncident.portal_visible) already supports this.

8. **Cultural safety** — risk assessments for Maori and Pasifika clients should consider cultural needs. This is a process requirement, not a system field, but reporting should be able to surface culturally relevant patterns.
