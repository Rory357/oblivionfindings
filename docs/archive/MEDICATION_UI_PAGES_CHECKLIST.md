# Medication Management UI Pages - Checklist

> Archived: this checklist references pre-consolidation medication pages that
> now redirect to canonical eMAR routes. Use
> [docs/emar-meds-readiness-plan.md](../emar-meds-readiness-plan.md) for the
> current readiness status.

## ✅ Pages Built and Accessible

### Main Navigation Pages

1. **Medications Index** - `/medications`
   - File: `resources/js/pages/medications/index.tsx`
   - Shows all clients with medication counts
   - Quick links to MAR, orders, and enhanced view
   - Quick stats summary

2. **Medication Dashboard** - `/medications/dashboard`
   - File: `resources/js/pages/medications/dashboard.tsx`
   - Real-time widgets overview
   - Active alerts list
   - Quick actions

3. **Medication Audit** - `/medications/audit`
   - File: `resources/js/pages/medications/audit.tsx`
   - Audit log viewing
   - Filter by client, user, date
   - CSV export

4. **Medication Reports** - `/reports/medications`
   - File: `resources/js/pages/reports/medications.tsx`
   - MAR export
   - Controlled discrepancy reports
   - Date/client/status filtering
   - CSV export functionality

### Client-Specific Pages

5. **Client MAR (Legacy)** - `/clients/{client}/mar`
   - File: `resources/js/pages/clients/mar.tsx`
   - Traditional MAR view
   - Date navigation
   - Recording and corrections

6. **Enhanced MAR** - `/medications/enhanced-mar/{client}`
   - File: `resources/js/pages/medications/enhanced-mar.tsx`
   - Full-featured MAR interface
   - Real-time safety checks
   - PRN history inline
   - Statistics dashboard
   - Allergy alerts
   - Shift integration

7. **Client Medical** - `/clients/{client}/medical`
   - File: `resources/js/pages/clients/medical.tsx`
   - Medication orders management
   - Controlled drug register
   - Stock management

### Reusable Components

8. **SafetyCheckPanel** - `resources/js/components/medications/SafetyCheckPanel.tsx`
   - Visual safety warnings
   - Severity levels
   - Override option

9. **DashboardWidgets** - `resources/js/components/medications/DashboardWidgets.tsx`
   - 6-widget layout
   - Today summary
   - Overdue/PRN/Discrepancies

10. **PrnHistoryPanel** - `resources/js/components/medications/PrnHistoryPanel.tsx`
    - 24-hour usage bar
    - History list
    - Limit warnings

11. **RecordAdministrationDialog** - `resources/js/components/medications/RecordAdministrationDialog.tsx`
    - Complete recording form
    - Safety check integration
    - PRN history
    - Witness selection

## ✅ Navigation

### Sidebar Navigation
- Main "Medications" link → `/medications`
- "Medication Dashboard" link → `/medications/dashboard`
- Visible to users with `medications.view` permission

### In-Page Navigation
All pages include:
- Breadcrumbs
- Quick action buttons
- Links to related pages
- Back navigation

## ✅ Routes

### Web Routes (`routes/medications.php`)
```
GET  /medications                    → MedicationsController@index
GET  /medications/dashboard          → medications/dashboard.tsx
GET  /medications/enhanced-mar/{client} → medications/enhanced-mar.tsx
GET  /medications/audit              → MedicationAuditController@index
GET  /reports/medications            → MedicationsReportController@index
GET  /clients/{client}/mar           → ClientMarController@show
```

### API Routes (`routes/api_medications.php`)
```
GET    /api/medications/dashboard/widgets
GET    /api/medications/clients/{client}/mar
POST   /api/medications/clients/{client}/medications/{medication}/administrations
GET    /api/medications/clients/{client}/medications/{medication}/safety-check
GET    /api/medications/alerts
POST   /api/medications/alerts/{alertId}/acknowledge
GET    /api/medications/reports
```

## ✅ Access Control

All pages check permissions:
- `medications.view` - View medications
- `medications.administer.record` - Record administrations
- `medications.administer.correct` - Correct administrations
- `medications.controlled.witness` - Witness controlled drugs
- `medications.reports.export` - Export reports
- `medications.audit.view` - View audit logs
- `clients.viewAny` / `clients.viewAssigned` - Client access

## Testing Checklist

### Navigation Test
- [ ] Click "Medications" in sidebar → Shows medications index
- [ ] Click "Medication Dashboard" in sidebar → Shows dashboard
- [ ] From index, click client card → Shows MAR
- [ ] From index, click "Enhanced" button → Shows Enhanced MAR
- [ ] From index, click "Reports" button → Shows reports
- [ ] From index, click "Audit" button → Shows audit

### Functionality Test
- [ ] Record medication administration
- [ ] View safety check warnings
- [ ] Access PRN history
- [ ] Navigate dates in MAR
- [ ] Export CSV from reports
- [ ] Filter audit logs
- [ ] Acknowledge dashboard alerts

### Responsive Test
- [ ] Test on desktop
- [ ] Test on tablet
- [ ] Test on mobile

## Next Steps

1. Run database migrations
2. Run seeders
3. Access `/medications` in browser
4. Verify all pages load correctly
5. Test recording an administration
6. Check dashboard widgets populate
