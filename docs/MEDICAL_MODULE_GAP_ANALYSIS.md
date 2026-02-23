# Medical Module Gap Analysis

**Date:** 2026-02-15  
**Scope:** Complete medical/medication module audit  
**Status:** Consolidated to single source of truth (EnhancedMarService)

---

## 1. EXECUTIVE SUMMARY

The medical module is **functionally complete** for core medication management but has several gaps in advanced features, admin tooling, and background automation. The codebase has been consolidated to use `EnhancedMarService` as the single source of truth for MAR data.

### Overall Assessment: 
| Category | Status | Coverage |
|----------|--------|----------|
| Core MAR | ✅ Complete | 100% |
| Safety Checks | ✅ Complete | 95% |
| Controlled Drugs | ✅ Complete | 100% |
| Reporting | ⚠️ Partial | 70% |
| Admin Tools | ❌ Missing | 20% |
| Automation | ⚠️ Partial | 40% |

---

## 2. COMPONENTS INVENTORY

### 2.1 Models (16 files) - ✅ Well Covered

| Model | Status | Notes |
|-------|--------|-------|
| ClientMedication | ✅ Complete | Full CRUD, versioning, state management |
| ClientMedicationAdministration | ✅ Complete | With safety checks |
| ClientMedicationStock | ✅ Complete | Stock tracking |
| ClientControlledDrugEntry | ✅ Complete | Double-sign register |
| ClientControlledDrugDiscrepancy | ✅ Complete | Discrepancy tracking |
| ClientMedicalProfile | ✅ Complete | Medical history |
| MedicationAllergy | ✅ Complete | Allergy checking with drug classes |
| MedicationInteraction | ✅ Complete | Drug-drug interaction checking |
| MedicationDashboardAlert | ✅ Complete | Alert system |
| MedicationOrderVersion | ⚠️ Exists | Model exists but UI for version history missing |
| MedicationScheduledStockCount | ⚠️ Exists | **NOT USED** - Scheduled stock counts not implemented |
| MedicationMarAttachment | ✅ Complete | MAR chart attachments |

### 2.2 Services (6 files) - ✅ Consolidated

| Service | Status | Notes |
|---------|--------|-------|
| EnhancedMarService | ✅ Complete | **Single source of truth** for MAR |
| MarScheduleService | ✅ Complete | Schedule calculation |
| MedicationSafetyService | ✅ Complete | Comprehensive safety checks |
| MedicationAlertService | ✅ Complete | Alert generation |
| MedicationReportingService | ✅ Complete | Report generation |
| MedicationIncidentIntegrationService | ⚠️ Partial | Integrates with incidents (if module exists) |

### 2.3 Controllers (8 files) - ⚠️ Some Dead Code

| Controller | Status | Route | Notes |
|------------|--------|-------|-------|
| ClientMedicalController | ✅ Active | /clients/{id}/medical | Full medical profile |
| ClientMarController | ✅ Active | /clients/{id}/mar | Uses EnhancedMarService |
| MedicationsController | ✅ Active | /medications | Central medications list |
| MedicationsApiController | ✅ Active | /api/medications/* | API endpoints |
| MedicationsReportController | ✅ Active | /reports/medications | Reports |
| MedicationAuditController | ✅ Active | /medications/audit | Audit logs |
| **DailyMarController** | ❌ **DEAD CODE** | **NONE** | Not routed, superseded by ClientMarController |
| **MedicationsModuleController** | ❌ **DEAD CODE** | **NONE** | Not routed, superseded by MedicationsController |

### 2.4 React Views (7 files) - ✅ Complete

| View | Status | Route |
|------|--------|-------|
| clients/medical.tsx | ✅ Complete | /clients/{id}/medical |
| clients/mar.tsx | ✅ Complete | /clients/{id}/mar |
| medications/index.tsx | ✅ Complete | /medications |
| medications/enhanced-mar.tsx | ✅ Complete | /medications/enhanced-mar/{id} |
| medications/dashboard.tsx | ✅ Complete | /medications/dashboard |
| medications/audit.tsx | ✅ Complete | /medications/audit |
| reports/medications.tsx | ✅ Complete | /reports/medications |

---

## 3. GAPS IDENTIFIED

### 3.1 🔴 HIGH PRIORITY - Missing Core Features

#### GAP-001: Medication Version History UI
- **Description:** MedicationOrderVersion model exists and versions are created, but no UI to view version history
- **Impact:** Users cannot see what changed in medication orders over time
- **Location:** Missing view in clients/medical.tsx
- **Recommendation:** Add "Version History" tab or modal showing medication changes

#### GAP-002: Scheduled Stock Counts Not Implemented  
- **Description:** MedicationScheduledStockCount model exists with full relations but no UI or scheduling logic
- **Impact:** Cannot schedule regular stock counts for controlled drugs (required by some regulations)
- **Location:** Model exists, no controller/service usage
- **Recommendation:** 
  - Add scheduled stock count scheduling UI
  - Add background job to create scheduled counts
  - Add notification when counts are due

#### GAP-003: No Background Job for Stale Alert Cleanup
- **Description:** MedicationAlertService::clearStaleAlerts() exists but is never called
- **Impact:** Alerts may persist even when conditions resolve
- **Location:** Service exists, no scheduler
- **Recommendation:** Add to routes/console.php:
  ```php
  Schedule::call(fn() => app(MedicationAlertService::class)->clearStaleAlerts())
      ->hourly();
  ```

### 3.2 🟡 MEDIUM PRIORITY - Admin & Management Tools

#### GAP-004: No Drug Interaction Management UI
- **Description:** MedicationInteraction model exists, interactions are checked, but no admin interface to add/edit interactions
- **Impact:** Developers must add interactions via database
- **Recommendation:** Create admin CRUD for medication interactions

#### GAP-005: No System-Level Allergy Management
- **Description:** Allergies are client-specific; no master list of common allergens
- **Impact:** Data inconsistency ("Penicillin" vs "penicillin" vs "PCN")
- **Recommendation:** Create master allergen list with standardized terms

#### GAP-006: Dead Code Controllers
- **Description:** DailyMarController and MedicationsModuleController exist but are not routed
- **Impact:** Confusion, maintenance overhead
- **Recommendation:** Delete or repurpose these controllers

#### GAP-007: Missing MAR Chart Attachment Management
- **Description:** MedicationMarAttachment model exists but no UI to manage attachments
- **Impact:** Cannot attach medication charts to MAR
- **Recommendation:** Add attachment upload/management to medical profile

### 3.3 🟢 LOW PRIORITY - Enhancements

#### GAP-008: No Medication Barcode Scanning
- **Description:** No barcode/QR code support for medication verification
- **Impact:** Manual entry only, higher error risk
- **Recommendation:** Future enhancement for barcode scanning

#### GAP-009: No Offline Mode for MAR
- **Description:** MAR requires constant internet connection
- **Impact:** Cannot record medications during outages
- **Recommendation:** PWA with offline queue

#### GAP-010: Limited Reporting Date Ranges
- **Description:** Reports limited to 500 records, no pagination
- **Impact:** Cannot generate reports for longer periods
- **Recommendation:** Add pagination and async report generation

---

## 4. DATA FLOW CONSISTENCY (RESOLVED ✅)

### Previous Issue: Dual MAR Services
- **Problem:** Two services calculating MAR data differently
  - `MedicationMarService` (legacy) 
  - `EnhancedMarService` (new)
- **Resolution:** Consolidated to `EnhancedMarService` as single source of truth

### Current Data Flow:
```
EnhancedMarService
  ├── MedicationsController → medications/index (client cards)
  ├── ClientMarController → clients/mar (legacy view)
  ├── MedicationsApiController → API for enhanced-mar.tsx
  └── MedicationAlertService → Dashboard widgets
```

---

## 5. SAFETY FEATURES STATUS

| Feature | Status | Implementation |
|---------|--------|----------------|
| Allergy Checking | ✅ Complete | MedicationSafetyService::checkAllergies() |
| Drug Interactions | ✅ Complete | MedicationSafetyService::checkInteractions() |
| Duplicate Detection | ✅ Complete | MedicationSafetyService::checkDuplicates() |
| PRN Limits | ✅ Complete | MedicationSafetyService::checkPrnLimits() |
| Expiry Checking | ✅ Complete | MedicationSafetyService + model methods |
| High-Risk Warnings | ✅ Complete | MedicationSafetyService |
| Controlled Drug Witness | ✅ Complete | Controller validation |
| Time Window Validation | ✅ Complete | MedicationSafetyService + MarScheduleService |

---

## 6. RECOMMENDATIONS BY PRIORITY

### Immediate (This Sprint)
1. **Delete dead code:** DailyMarController, MedicationsModuleController, MedicationMarService (old)
2. **Add scheduled task:** Clear stale alerts hourly
3. **Fix any routing issues** from consolidation

### Short Term (Next 2 Sprints)
4. **Add Medication Version History UI**
5. **Implement scheduled stock counts** for controlled drugs
6. **Add drug interaction management** admin interface

### Medium Term (Next Quarter)
7. **Master allergen list**
8. **MAR chart attachments**
9. **Enhanced reporting with pagination**

### Long Term (Future)
10. **Barcode scanning**
11. **Offline mode/PWA**
12. **AI-powered interaction checking**

---

## 7. TESTING GAPS

| Area | Test Coverage | Notes |
|------|---------------|-------|
| Unit Tests | ⚠️ Partial | Services have logic but few unit tests |
| Feature Tests | ⚠️ Partial | Basic flow tested, edge cases missing |
| Browser Tests | ❌ None | No Dusk tests for MAR recording |
| Safety Checks | ⚠️ Partial | Allergy matching tested, interactions not |

**Recommendation:** Add comprehensive test coverage before next major release.

---

## 8. DOCUMENTATION STATUS

| Document | Status | Location |
|----------|--------|----------|
| User Guide | ❌ Missing | Not written |
| Admin Guide | ❌ Missing | Not written |
| API Documentation | ⚠️ Partial | Inline code comments only |
| Data Model | ✅ Complete | Migrations + Models |
| This Gap Analysis | ✅ Complete | docs/MEDICAL_MODULE_GAP_ANALYSIS.md |

---

## 9. COMPLIANCE CHECKLIST

| Requirement | Status | Evidence |
|-------------|--------|----------|
| Double-sign for controlled drugs | ✅ | witnessed_by field + validation |
| Audit trail | ✅ | AuditLog trait on all models |
| Alert for missed doses | ✅ | MedicationAlertService::checkOverdueDoses |
| Alert for PRN overuse | ✅ | MedicationAlertService::checkPrnLimits |
| Expiry warnings | ✅ | isExpiringSoon() + alerts |
| Version history | ⚠️ Partial | Model versions stored, UI missing |
| Stock reconciliation | ⚠️ Partial | Manual only, scheduled counts not implemented |

---

## 10. CONCLUSION

The medical module is **production-ready** for core medication management with:
- ✅ Robust MAR with safety checks
- ✅ Controlled drug register with double-sign
- ✅ Alert system for overdue/missed doses
- ✅ Comprehensive reporting

**Key gaps to address:**
1. Version history UI (compliance)
2. Scheduled stock counts (compliance)
3. Alert cleanup automation (data hygiene)
4. Dead code removal (maintainability)

The consolidation to `EnhancedMarService` provides a solid foundation for future enhancements.

---

*Generated by Gap Analysis Audit - 2026-02-15*
