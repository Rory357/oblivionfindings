# Sites/Locations Module - Remaining Work

> Updated: 2026-02-08
> Showing only items still needing implementation

---

## 🔴 CRITICAL (MVP Blockers)

### 1. Fix Tab Display in Site Profile
**File:** `resources/js/pages/sites/show.tsx`
- [ ] Tabs content not displaying (Overview, Clients, Assets, etc. all appear empty)
- [ ] Investigate TabsRoot/TabsContent component issue
- [ ] Ensure all tab panels render correctly
- [ ] Test all 9 tabs: Overview, Clients, Assets, Contacts, Documents, Calendar, Checklists, Hazards, Type-Specific

### 2. Fix Onboarding Resume Logic
**Files:** 
- `app/Http/Controllers/Sites/SiteOnboardingController.php`
- `resources/js/pages/sites/onboarding/wizard.tsx`
- [ ] Calculate last completed step correctly from `onboarding_progress` JSON
- [ ] Resume from next incomplete step (not step 1)
- [ ] Handle URL `?step=X` parameter override
- [ ] Pre-populate basic info form with existing site data
- [ ] Test: Close wizard mid-progress, reopen should resume correctly

### 3. Create Global Calendar Page
**New Files:**
- `app/Http/Controllers/Sites/SiteCalendarController.php` (extend)
- `resources/js/pages/calendar/global.tsx`
- [ ] Full-page calendar view (not site-scoped)
- [ ] Filters: Site (multi-select), Site Type, Event Type, Assigned Staff, Status
- [ ] Toggle: "Show only my events"
- [ ] Quick-add event button
- [ ] List view + Calendar view toggle
- [ ] Route: `GET /calendar` → `SiteCalendarController::global()`

### 4. Create Global Hazards Page
**New Files:**
- `resources/js/pages/compliance/hazards/index.tsx` (extend existing stub)
- [ ] Page title: "Home's and Sites Hazards"
- [ ] Filters: Site, Site Type, Status, Severity, Assignee, Due/Overdue
- [ ] Table columns: Reference #, Site, Type, Severity, Status, Assigned To, Due Date
- [ ] Quick actions: View, Assign, Close
- [ ] "Log Hazard" button (global, requires site selection)
- [ ] Export hazard register (CSV)
- [ ] Ensure route `/compliance/hazards` works

### 5. Create Checklist Run Completion UI
**New Files:**
- `resources/js/pages/sites/checklists/runs/[id].tsx`
- [ ] Display checklist template items
- [ ] Response inputs per type:
  - Yes/No: Toggle buttons
  - Yes/No/NA: Three-way toggle
  - Pass/Fail: Toggle
  - Numeric: Number input with min/max
  - Text: Textarea
  - Photo: File upload
- [ ] Notes field per item
- [ ] Photo upload per item
- [ ] Progress indicator (% complete)
- [ ] "Mark as Failed" creates hazard option
- [ ] Save draft vs Complete
- [ ] Overall notes at end
- [ ] Signature/confirmation on complete

---

## 🟡 HIGH PRIORITY (Core Features)

### 6. Create Site Reporting Controller & Pages
**New Files:**
- `app/Http/Controllers/Sites/SiteReportingController.php`
- `resources/js/pages/sites/reports/index.tsx`
- `resources/js/pages/sites/reports/houses.tsx`
- `resources/js/pages/sites/reports/facilities.tsx`
- `resources/js/pages/sites/reports/head-office.tsx`

**House Report Pack:**
- [ ] Hazards: open/closed, by severity, time-to-close, overdue actions
- [ ] Checklist compliance: completion %, overdue runs, failed item trends
- [ ] Maintenance/Inspections: upcoming/overdue, completion evidence
- [ ] Assets: by condition, warranty expiry upcoming, maintenance due
- [ ] Vendors: key contacts per house
- [ ] Bedroom occupancy report

**Facility Report Pack:**
- [ ] Hazards register (equipment-focused)
- [ ] Safety walkthrough compliance
- [ ] Equipment condition summary
- [ ] Zone utilization

**Head Office Report Pack:**
- [ ] Room booking utilization
- [ ] Safety & facilities compliance
- [ ] IT/Network asset summary

**Common:**
- [ ] Date range filter
- [ ] Site multi-select filter
- [ ] Export CSV
- [ ] Export audit-friendly PDF pack

### 7. Add Assets Step to Onboarding Wizard
**Files:**
- `app/Http/Controllers/Sites/SiteOnboardingController.php`
- `resources/js/pages/sites/onboarding/wizard.tsx`
- [ ] Add "Assets" step to `getSteps()` for all site types
- [ ] Bulk add initial assets UI
- [ ] Fields: Asset name, category, serial/identifier, purchase date, warranty expiry, condition, assigned area
- [ ] Category dropdown: appliances, safety equipment, IT/network, furniture, vehicles, medical equipment, tools
- [ ] Save to site asset register
- [ ] Mark step complete even if empty (optional step)

### 8. Create Room Management Page (Houses)
**New Files:**
- `resources/js/pages/sites/rooms/index.tsx`
- [ ] List all bedrooms for house
- [ ] Add bedroom (name, notes)
- [ ] Edit bedroom
- [ ] Deactivate/archive bedroom (soft delete)
- [ ] Assign client to bedroom (dropdown of clients)
- [ ] View assignment history
- [ ] Link from site profile "Rooms" tab
- [ ] Route: `/sites/{site}/rooms`

### 9. Create Resource Management Page (Head Office)
**New Files:**
- `resources/js/pages/sites/resources/index.tsx`
- [ ] List all rooms/resources
- [ ] Add resource: name, type (boardroom/training_room/meeting_room/other), capacity, amenities
- [ ] Calendar email field (M365/Google sync)
- [ ] Edit resource
- [ ] Activate/deactivate
- [ ] Link from site profile "Resources" tab
- [ ] Route: `/sites/{site}/resources`

### 10. Create Zone Management Page (Facilities)
**New Files:**
- `resources/js/pages/sites/zones/index.tsx`
- [ ] List all zones
- [ ] Add zone: name, description, zone type
- [ ] Edit zone
- [ ] Activate/deactivate
- [ ] Link from site profile "Zones" tab
- [ ] Route: `/sites/{site}/zones`

---

## 🟢 MEDIUM PRIORITY (Operational Enhancements)

### 11. Create Checklist Template Management UI
**New Files:**
- `resources/js/pages/sites/checklists/templates/index.tsx`
- `resources/js/pages/sites/checklists/templates/create.tsx`
- `resources/js/pages/sites/checklists/templates/edit.tsx`

- [ ] List all templates (global admin view)
- [ ] Filter by site type applicability
- [ ] Create template: name, description, applicable to type, frequency
- [ ] Add/edit template items:
  - Question text
  - Response type (yes_no, yes_no_na, pass_fail, numeric, text, photo)
  - Response config (min/max for numeric)
  - Required toggle
  - Guidance text
  - Failure creates hazard toggle
  - Sort order (drag to reorder)
- [ ] Activate/deactivate templates
- [ ] Duplicate template

### 12. Add Inspection Scheduling UI
**Files:**
- `resources/js/pages/sites/inspections/index.tsx` (extend)
- [ ] Create inspection schedule form
- [ ] Fields: inspection type, title, description, frequency, first due date, assigned to
- [ ] Auto-create calendar event toggle
- [ ] List upcoming inspections
- [ ] List completed inspections
- [ ] Complete inspection form (result, findings, corrective actions, evidence photos)
- [ ] Link inspection to hazard
- [ ] Edit recurring series vs single occurrence

### 13. Create Site-Scoped Asset Register View
**New Files:**
- `resources/js/pages/sites/assets/index.tsx`
- [ ] List assets for specific site
- [ ] Filter by category, condition, location
- [ ] Columns: Name, Category, Serial, Location, Condition, Next Maintenance
- [ ] Add asset button
- [ ] Edit asset
- [ ] View asset detail
- [ ] Link to global asset page
- [ ] Route: `/sites/{site}/assets`

### 14. Add Shifts Tab for Houses (Readonly)
**Files:**
- `resources/js/pages/sites/show.tsx` (extend)
- [ ] New tab: "Shifts" (houses only)
- [ ] Display planned shifts from Shift Planner
- [ ] Columns: Date, Staff, Shift Type, Status
- [ ] Filter by date range
- [ ] Link to full shift planner
- [ ] Readonly - no editing

---

## 🔵 LOW PRIORITY (Polish & Advanced Features)

### 15. Add Fleet Vehicle Assignment to Sites
**Files:**
- `app/Http/Controllers/Sites/SiteVehicleController.php` (new)
- `resources/js/pages/sites/vehicles/index.tsx`
- [ ] List vehicles assigned to site
- [ ] Assign vehicle to site
- [ ] Remove vehicle from site
- [ ] Vehicle booking calendar integration
- [ ] Prevent double bookings
- [ ] Route: `/sites/{site}/vehicles`

### 16. Add Document Tagging System
**Files:**
- Database migration: `create_site_document_tags_table`
- `app/Models/SiteDocumentTag.php`
- `resources/js/pages/sites/documents/index.tsx` (extend)
- [ ] Tag documents by type (evacuation plan, floor plan, contract, compliance cert, etc.)
- [ ] Add expiry dates to documents
- [ ] Reminders for expiring documents
- [ ] Filter by tag
- [ ] Tag management in settings

### 17. Create RRULE Builder for Advanced Recurrence
**New Files:**
- `resources/js/components/rrule-builder.tsx`
- [ ] Visual recurrence rule builder
- [ ] Options: daily, weekly, monthly, yearly, custom
- [ ] End: never, after X occurrences, on date
- [ ] Weekly: day(s) of week selector
- [ ] Monthly: day of month or nth weekday
- [ ] Export RRULE string
- [ ] Parse existing RRULE for editing
- [ ] Use in calendar events and inspection schedules

---

## 🛠️ BUG FIXES & REFACTORING

### 18. Settings Helper Function
**Files:**
- `app/helpers.php` (✅ Created)
- `app/Helpers/SettingsHelper.php` (✅ Created)
- `composer.json` (✅ Updated)
- [ ] Run `composer dump-autoload` to register
- [ ] Test `settings()` function works in controllers

### 19. Fix Hazard Type Settings
**File:** `app/Http/Controllers/Sites/SiteHazardController.php`
- [ ] Handle missing `settings()` function gracefully (✅ Done with fallback)
- [ ] Or complete settings helper setup

### 20. Navigation Updates
**File:** `resources/js/components/app-sidebar.tsx`
- [ ] Verify "Inspections & Maintenance" nav item exists (currently missing)
- [ ] Verify "Documents & Notes" nav item exists or is accessible
- [ ] Ensure all permission checks work correctly

### 21. Make Setup Completeness Collapsible
**File:** `resources/js/pages/sites/show.tsx`
- [ ] Add collapse/expand functionality to Setup Completeness card
- [ ] Show summary when collapsed: "X of Y items complete (Z%)"
- [ ] Chevron icon to indicate state
- [ ] Persist collapsed state (optional)
- [ ] Default to expanded when onboarding incomplete
- [ ] Default to collapsed when onboarding complete (100%)

### 22. Add Site Photos Feature
**New Files:**
- Database migration: `add_photos_to_sites_table` OR `create_site_photos_table`
- `app/Models/SitePhoto.php`
- `app/Http/Controllers/SitePhotoController.php`

**Features:**
- [ ] Upload multiple photos per site
- [ ] Photo types: Exterior, Interior, Bedroom(s), Common Areas, Safety Equipment, Evacuation Routes
- [ ] Caption/description per photo
- [ ] Primary/main photo flag
- [ ] Sort order (drag to reorder)
- [ ] Lightbox/gallery view
- [ ] Delete/archive photo
- [ ] Display photos in site profile (gallery or carousel)
- [ ] Add photos during onboarding wizard
- [ ] Route: `/sites/{site}/photos`

---

## 📊 DATA & SEEDING

### 23. Complete Seed Data
**File:** `database/seeders/SitesModuleSeeder.php`
- [ ] Add more house checklist items (currently 12)
- [ ] Complete Head Office checklist template items (currently empty)
- [ ] Add default room resource types for Head Office
- [ ] Add default zone types for Facilities
- [ ] Seed sample hazard types if not using settings

### 24. Backfill Existing Data
- [ ] Run `php artisan db:seed --class=SitesModuleSeeder`
- [ ] Ensure all sites have `type` set (use backfill migration)
- [ ] Update any existing "location" types to "facility"

---

## 🧪 TESTING

### 25. End-to-End Testing
- [ ] Create new house → complete onboarding → verify all steps save
- [ ] Create new facility → complete onboarding
- [ ] Create new head office → complete onboarding
- [ ] Log hazard from site → assign to H&S officer → close
- [ ] Schedule checklist → run checklist → complete
- [ ] Create recurring calendar event → edit one occurrence → verify exception
- [ ] Add vendor → add credential → reveal credential (check audit log)
- [ ] Generate site report → export CSV

### 26. Permission Testing
- [ ] Support worker can only view assigned sites
- [ ] H&S officer can manage hazards
- [ ] Team lead can approve calendar events
- [ ] Maintenance coordinator can schedule inspections
- [ ] Board member readonly access to reports

### 27. Mobile/Responsive Testing
- [ ] Site list works on mobile
- [ ] Checklist run form usable on tablet
- [ ] Calendar view responsive
- [ ] Navigation collapses correctly

---

## 📈 METRICS & MONITORING

### 28. Audit Logging (Verify)
- [ ] Hazard changes logged
- [ ] Checklist run responses logged
- [ ] Credential reveal/view/copy logged
- [ ] Site risk flag changes logged
- [ ] Approval decisions logged

### 29. Notifications Setup
**File:** `routes/console.php` (already has jobs)
- [ ] Verify `SendEventReminderJob` runs every 5 minutes
- [ ] Verify `ChecklistDueJob` runs daily at 08:00
- [ ] Verify `InspectionDueJob` runs daily at 08:30
- [ ] Verify `HazardOverdueJob` runs daily at 09:00
- [ ] Test notifications are actually sent

---

## 🚀 DEPLOYMENT

### 30. Pre-Deployment
- [ ] All migrations tested on staging
- [ ] Seeders run successfully
- [ ] Feature flags configured (if using)
- [ ] Backups taken

### 31. Deployment
- [ ] Deploy code
- [ ] Run migrations
- [ ] Run seeders
- [ ] Clear cache
- [ ] Build frontend assets

### 32. Post-Deployment
- [ ] Verify all routes respond
- [ ] Check navigation renders
- [ ] Test critical path (onboarding)
- [ ] Monitor error logs

---

## 📋 REMAINING WORK SUMMARY

| Category | Count | Priority Order |
|----------|-------|----------------|
| **Critical** | 5 | Fix tabs → Fix onboarding → Global Calendar → Global Hazards → Checklist Run UI |
| **High** | 5 | Reporting → Assets onboarding → Room mgmt → Resource mgmt → Zone mgmt |
| **Medium** | 4 | Template mgmt → Inspection UI → Site assets → Shifts tab |
| **Low** | 3 | Fleet → Doc tags → RRULE builder |
| **Bug Fixes** | 5 | Settings helper → Hazard settings → Nav updates → Collapsible → Photos |
| **Data** | 2 | Seeders → Backfill |
| **Testing** | 3 | E2E → Permissions → Mobile |
| **Deployment** | 3 | Pre → Deploy → Post |
| **TOTAL** | **30** | Focus on Critical first! |

---

## 🎯 RECOMMENDED WEEK-BY-WEEK PLAN

### Week 1: Foundation Fixes
1. Fix tab display (Critical #1)
2. Fix onboarding resume (Critical #2)
3. Settings helper setup (Bug #18)
4. Make setup completeness collapsible (Bug #21)

### Week 2: Core Global Views
5. Global Calendar (Critical #3)
6. Global Hazards (Critical #4)
7. Checklist Run UI (Critical #5)

### Week 3: Type-Specific Management
8. Room Management (High #8)
9. Resource Management (High #9)
10. Zone Management (High #10)
11. Add Assets to onboarding (High #7)

### Week 4: Reporting & Templates
12. Site Reporting (High #6)
13. Checklist Template UI (Medium #11)
14. Inspection Scheduling (Medium #12)

### Week 5: Polish & Testing
15. Site Photos (Bug #22)
16. Site Asset Register (Medium #13)
17. Shifts Tab (Medium #14)
18. Full testing suite

---

*Check off items as they are completed. Focus on Critical items first for MVP.*
