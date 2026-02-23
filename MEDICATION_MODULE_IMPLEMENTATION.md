# Enterprise Medication Management System - Implementation Guide

## Overview

This document outlines the comprehensive, enterprise-grade Medication Management System built for NZ Supported Living (Oblivion Findings).

## Components Delivered

### 1. Database Schema Enhancements

**Migration File:** `database/migrations/2026_02_15_000001_enhance_medications_enterprise.php`

#### Enhanced `client_medications` table:
- `high_risk` - Boolean flag for high-risk medications
- `witness_required` - Boolean for witness requirement
- `indication` - Clinical indication for the medication
- `dose_amount` - Structured numeric dose
- `dose_unit` - Dose unit (mg, g, ml, etc.)
- `frequency_code` - Structured frequency
- `version` - Version tracking
- `superseded_by` - Links to newer version
- `superseded_at` - When version was superseded
- `deleted_at` - Soft delete support

#### New Tables Created:

**medication_order_versions**
- Full version history for medication orders
- Complete snapshot of medication state at change time
- Change tracking with user and timestamp

**medication_allergies**
- Client allergy records
- Severity levels (mild, moderate, severe, life_threatening)
- Smart matching for medication checks

**medication_interactions**
- Drug interaction reference database
- Severity levels (minor, moderate, major, contraindicated)
- Management guidance

**medication_scheduled_stock_counts**
- Scheduled stock count tracking
- Discrepancy flagging
- Witness requirements

**medication_mar_attachments**
- File attachments for MAR entries
- Image/PDF support
- Audit trail

**medication_dashboard_alerts**
- Real-time dashboard alerts
- Alert types: overdue, prn_near_limit, controlled_discrepancy, expiring, high_risk
- Acknowledgment and resolution workflow

### 2. Models Created

**MedicationOrderVersion**
- Version history management
- Change summaries
- Formatted dose display

**MedicationAllergy**
- Allergy matching logic
- Severity checking
- Drug class matching

**MedicationInteraction**
- Interaction checking
- Multi-medication checks
- Severity-based sorting

**MedicationScheduledStockCount**
- Overdue checking
- Discrepancy tracking
- Completion workflow

**MedicationMarAttachment**
- File management
- URL generation
- Type checking

**MedicationDashboardAlert**
- Alert lifecycle management
- Acknowledgment/Resolution
- Bulk operations

### 3. Services Created

**MedicationSafetyService**
- Complete safety checking before administration
- Allergy checking with smart matching
- Duplicate medication detection
- Drug interaction checking
- PRN limit enforcement
- Time window validation
- Returns structured safety results with warnings

**EnhancedMarService**
- Builds comprehensive MAR view
- Scheduled and PRN medication handling
- Safety check integration
- PRN history display
- Shift integration
- Statistics calculation
- Administration recording
- Controlled drug entry creation

**MedicationIncidentIntegrationService**
- Auto-creates incidents for:
  - Missed doses
  - PRN over-limit attempts
  - Controlled drug discrepancies
  - Unsafe corrections
  - Late doses
  - Refused high-risk medications
- Links incidents to medications
- Severity-based routing

**MedicationReportingService**
- MAR export
- PRN usage reports
- Missed dose reports
- Late dose reports
- Controlled drug balance
- Controlled discrepancy reports
- Medication change history
- Medication incidents report
- Comprehensive audit report
- CSV export functionality

**MedicationAlertService**
- Dashboard widget generation
- Client-specific alert generation
- Global alert aggregation
- Alert acknowledgment/resolution
- Stale alert cleanup

### 4. Controllers

**MedicationsApiController**
- `/api/medications/clients/{client}/mar` - Get MAR data
- `/api/medications/clients/{client}/medications/{medication}/administrations` - Record administration
- `/api/medications/clients/{client}/medications/{medication}/safety-check` - Safety check
- `/api/medications/clients/{client}/medications/{medication}/prn-history` - PRN history
- `/api/medications/alerts` - Dashboard alerts
- `/api/medications/dashboard/widgets` - Dashboard widgets
- `/api/medications/reports` - Reporting endpoints

### 5. React Components

**SafetyCheckPanel**
- Displays safety check results
- Color-coded severity levels
- Warning details with icons
- Override option for managers

**DashboardWidgets**
- Six widget layout
- Today summary
- Overdue medications
- PRN near limits
- Controlled discrepancies
- Expiring medications
- High-risk medications

**PrnHistoryPanel**
- 24-hour usage bar
- History list with timestamps
- Limit warnings
- Remaining doses display

**RecordAdministrationDialog**
- Medication information display
- Safety check integration
- PRN history inline
- Status selection
- Time recording
- Witness selection
- Outcome tracking
- Reason capture

**Enhanced MAR Page**
- Full-featured MAR interface
- Date navigation
- Allergy alerts
- Statistics dashboard
- Scheduled medications list
- PRN medications panel
- History view
- Quick record functionality

**Medications Dashboard**
- Dashboard widgets overview
- Active alerts list
- Quick action links
- Acknowledgment workflow

### 6. Routes

**Web Routes**
```
GET /medications - List view
GET /medications/dashboard - Dashboard
GET /medications/enhanced-mar/{client} - Enhanced MAR
GET /clients/{client}/mar - Legacy MAR
```

**API Routes**
```
GET /api/medications/dashboard/widgets
GET /api/medications/clients/{client}/mar
POST /api/medications/clients/{client}/medications/{medication}/administrations
GET /api/medications/clients/{client}/medications/{medication}/safety-check
GET /api/medications/alerts
POST /api/medications/alerts/{alertId}/acknowledge
GET /api/medications/reports
```

### 7. Permissions

Existing permissions used:
- `medications.view` - View medications
- `medications.administer.record` - Record administrations
- `medications.administer.correct` - Correct administrations
- `medications.controlled.witness` - Witness controlled drugs
- `medications.reports.export` - Export reports
- `clients.viewAny` / `clients.viewAssigned` - Client access
- `clients.update` - Full client management

## Features Implemented

### ✅ Medication Orders
- [x] Enhanced fields (indication, structured dose, frequency)
- [x] Version history with full audit trail
- [x] Soft delete for non-destructive edits
- [x] State management (active, paused, ceased)
- [x] High-risk flagging
- [x] Witness requirements

### ✅ MAR (Medication Administration Record)
- [x] Today + historical date navigation
- [x] Scheduled and PRN separation
- [x] Visual status indicators
- [x] One-click recording
- [x] Safety check integration
- [x] Witness capture
- [x] Late/early tracking
- [x] Outcome recording

### ✅ PRN Management
- [x] 24-hour rolling count
- [x] Near limit warnings
- [x] Over-limit blocking
- [x] Inline history display
- [x] Usage visualization

### ✅ Safety Controls
- [x] Allergy checking with smart matching
- [x] Duplicate medication detection
- [x] Drug interaction checking
- [x] Expired medication blocking
- [x] High-risk visual indicators
- [x] Time window validation

### ✅ Correction Workflow
- [x] Immutable original records
- [x] Linked correction entries
- [x] Correction reason capture
- [x] 30-minute quick edit window
- [x] Incident creation for late corrections

### ✅ Controlled Drug Register
- [x] Running balance tracking
- [x] Stock in/out recording
- [x] Disposal tracking
- [x] Witness requirements
- [x] Discrepancy logging
- [x] Incident integration

### ✅ Incident Integration
- [x] Auto-creation for missed doses
- [x] PRN over-limit incidents
- [x] Controlled discrepancy incidents
- [x] Unsafe correction flagging
- [x] Late dose tracking
- [x] Refused medication tracking

### ✅ Reporting
- [x] MAR export (date range)
- [x] PRN usage report
- [x] Missed dose report
- [x] Late dose report
- [x] Controlled balance report
- [x] Controlled discrepancy report
- [x] Medication change history
- [x] Medication incidents report
- [x] CSV export

### ✅ Dashboard Alerts
- [x] Overdue medications widget
- [x] PRN near limits widget
- [x] Controlled discrepancies widget
- [x] Expiring medications widget
- [x] High-risk medications widget
- [x] Today's summary widget

### ✅ Shift Integration
- [x] Shift-tagged administrations
- [x] Active shift detection
- [x] End-of-shift summary
- [x] Shift medication timeline

## Usage

### Running Migrations
```bash
php artisan migrate
```

### Running Seeders
```bash
php artisan db:seed --class=MedicationEnterpriseSeeder
```

### Accessing the System

1. **Medications Dashboard:**
   - Navigate to `/medications/dashboard`
   - View real-time widgets and alerts

2. **Enhanced MAR:**
   - Navigate to `/medications/enhanced-mar/{client_id}`
   - Full-featured medication administration

3. **Legacy MAR:**
   - Navigate to `/clients/{client_id}/mar`
   - Backwards-compatible view

### Recording an Administration

1. Open the MAR for a client
2. Click "Record" on the medication row
3. Review safety check results
4. Select status (given/refused/withheld/missed)
5. Enter required reason if applicable
6. Select witness if required
7. Record administration

### Generating Reports

1. Navigate to `/reports/medications`
2. Select report type and filters
3. View or export as CSV

## Compliance Features

- **Audit Trail:** Every change versioned and logged
- **Immutable Records:** Original administrations preserved
- **Witness Requirements:** Double-sign for controlled drugs
- **Safety Checks:** Multi-layered safety validation
- **Incident Integration:** Automatic incident creation
- **Reporting:** Comprehensive audit-ready reports
- **Time Tracking:** Late/early administration tracking
- **PRN Limits:** Enforced 24-hour limits

## Security Features

- Role-based access control
- Permission enforcement at API level
- Witness authorization checks
- Override requires manager permission
- Audit logging of all actions

## NZ HealthCERT Alignment

- Complete audit trail
- Non-repudiable records
- Incident management
- Risk assessment integration
- Controlled drug compliance
- Staff authorization tracking

## Next Steps

1. Run migrations to add new tables
2. Run seeders for sample data
3. Train staff on new workflows
4. Configure alert thresholds
5. Set up scheduled stock counts
6. Review and customize drug interaction database
7. Test incident integration workflows
