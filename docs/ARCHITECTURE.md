# Architecture Overview

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         CLIENT BROWSER                          │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │  React 19 + TypeScript + Inertia.js                     │  │
│  │  - shadcn/ui components                                 │  │
│  │  - Tailwind CSS 4                                       │  │
│  │  - Recharts for analytics                               │  │
│  └─────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      LARAVEL 12 APPLICATION                     │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │  Web Routes (routes/*.php)                              │  │
│  │  - Domain-organized route files                         │  │
│  │  - Permission middleware                                │  │
│  └─────────────────────────────────────────────────────────┘  │
│                              │                                  │
│                              ▼                                  │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │  Controllers                                            │  │
│  │  - Handle HTTP requests                                 │  │
│  │  - Validate input                                       │  │
│  │  - Return Inertia responses                             │  │
│  └─────────────────────────────────────────────────────────┘  │
│                              │                                  │
│                              ▼                                  │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │  Services (app/Services/)                               │  │
│  │  - ServiceContextResolver                               │  │
│  │  - WorkstreamService                                    │  │
│  │  - NotificationService                                  │  │
│  │  - Business logic encapsulation                         │  │
│  └─────────────────────────────────────────────────────────┘  │
│                              │                                  │
│                              ▼                                  │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │  Models (app/Models/)                                   │  │
│  │  - Eloquent ORM                                         │  │
│  │  - Relationships                                        │  │
│  │  - AuditableChanges trait                               │  │
│  └─────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      DATA STORAGE                               │
│  ┌──────────────┐  ┌──────────────┐  ┌─────────────────────┐  │
│  │  MySQL/PSQL  │  │    Redis     │  │   File Storage      │  │
│  │  Primary DB  │  │  Queues/Cache│  │  (uploads/exports)  │  │
│  └──────────────┘  └──────────────┘  └─────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

## Domain Modules

### 1. Client Management
**Files:** `routes/clients.php`, `ClientController`, `Client` model

- Client profiles with medical records
- Document management
- Portal user management (family/NOK access)
- Risk assessments
- Support plans

### 2. Shift Scheduling
**Files:** `routes/shifts.php`, `ShiftController`, `Shift` model

- Shift creation and assignment
- Recurring shift series
- Shift lifecycle (scheduled → in_progress → completed)
- Task management within shifts
- Conflict detection

### 3. Timesheet Workflow
**Files:** `TimesheetController`, `Timesheet` model

- Draft → Submitted → Approved/Rejected
- Bulk approval operations
- Integration with shifts (auto-create on shift completion)

### 4. Incident Management
**Files:** `routes/incidents.php`, `IncidentController`

- Incident reporting
- Severity classification
- Follow-up task tracking
- Template system
- Review workflow

### 5. Medication Administration
**Files:** `ClientMedicalController`, `ClientMedication` model

- MAR (Medication Administration Record)
- Controlled drug register
- Break-glass emergency access
- Stock tracking

### 6. Asset Management
**Files:** `routes/assets.php`, `AssetController`

- Asset tracking with QR codes
- Telemetry ingestion
- Geofence alerts
- Maintenance logs

### 7. Fleet Management
**Files:** `routes/fleet.php`, `FleetVehicleController`

- Vehicle tracking
- Trip management
- Fuel logging
- Driver sessions

### 8. Control Room
**Files:** `routes/control-room.php`, `ControlRoomDashboardController`

- Signal processing
- Alert queues
- Playbook automation
- SLA monitoring

### 9. Respite Care
**Files:** `routes/respite.php`, `RespiteBookingController`

- Booking requests
- Stay management
- Procedure templates
- Calendar projection

### 10. Compliance
**Files:** Various compliance controllers

- Safeguarding concerns
- GDPR/data privacy
- Consent management
- Audit logging

## Data Flow Patterns

### Standard CRUD Flow
```
Request → Route → Middleware → Controller → Service → Model → DB
                                              ↓
                                        Inertia Response → React Page
```

### Filtered List Flow
```
Request with filters → Controller applies filters → Query with eager loading
                                                          ↓
                                              Paginated JSON + Filter state
                                                          ↓
                                              React page with useFilters hook
```

### Workflow State Transition
```
Action (start/complete/approve) → Validation → Transaction → State update
                                                       ↓
                                              Timeline event created
                                                       ↓
                                              Notification dispatched
```

## Security Model

### Authentication
- Laravel Fortify (session-based)
- Two-factor authentication support
- OAuth (Google, Microsoft) via Socialite

### Authorization
Custom RBAC implementation:
- `roles` table defines roles
- `permissions` table defines granular permissions
- `role_user` pivot assigns roles to users
- `permission_user` allows individual permission overrides

Check permissions:
```php
$user->canDo('permission.key');
```

### Middleware
- `auth` - Authenticated users only
- `permission:name` - Specific permission required
- `verified` - Email verified

## Performance Optimizations

### Database
- Eager loading on all list queries
- Select specific columns where possible
- Pagination on all large lists
- Performance indexes on critical tables

### Caching
- `once()` helper for per-request caching
- Config caching in production
- Route caching

### Frontend
- Vite for fast HMR and optimized builds
- Inertia.js for SPA-like experience without API calls
- Lazy loading for heavy components

## Configuration Architecture

### Environment-based Config
```
config/
├── dashboard.php      # Dashboard widget limits
├── ui.php            # UI behavior settings
├── labels.php        # Terminology customization
└── ...               # Standard Laravel configs
```

### Database Settings
`AppSetting` model for runtime configuration:
- Theme tokens
- Branding assets
- Feature flags
- Terminology overrides

## Key Services

### ServiceContextResolver
Resolves service context for clients/shifts with fallback chain:
1. Explicitly provided
2. Client's context
3. Organization default
4. First active context

### WorkstreamService
Builds unified "My Day" list combining:
- Upcoming shifts
- Open follow-ups
- Overdue items

### NotificationService
Centralized notification dispatch:
- Database notifications
- Escalation rules
- Target user filtering

## Event System

### Model Observers
- `ShiftObserver` - Creates timeline events on shift changes
- `ClientNoteObserver` - Propagates notes to timeline

### Custom Events
- `FleetSignalEmitted` - Triggers audit logging

## Queue System

Jobs run via Redis queues:
- `GenerateSummaryJob` - AI summaries
- `ProcessControlRoomSignals` - Signal processing
- `CheckControlRoomSlaBreaches` - SLA monitoring

Run worker:
```bash
php artisan queue:work
```

## Testing Strategy

### Backend
- Feature tests for controllers
- Unit tests for services
- Policy tests for authorization

### Frontend
- Type checking with TypeScript
- ESLint for code quality
- Component testing (to be expanded)

## Deployment Considerations

### Required Services
- Web server (Nginx/Apache)
- PHP-FPM
- MySQL/PostgreSQL
- Redis
- Queue workers (supervisor)
- Scheduler (cron)

### Build Process
```bash
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm ci
npm run build
```

## Monitoring & Logging

### Structured Logging
All notifications failures logged with context:
```php
Log::warning('Notification failed', [
    'shift_id' => $shift->id,
    'error' => $e->getMessage(),
]);
```

### Audit Trail
`AuditLogger` service tracks:
- CRUD operations
- Permission changes
- Sensitive data access

### Performance
- Query logging in debug mode
- Slow query monitoring
- Dashboard analytics cached

## Extension Points

### Adding a New Domain
1. Create routes file in `routes/`
2. Create controller(s) in `app/Http/Controllers/`
3. Create models in `app/Models/`
4. Create migration(s)
5. Add navigation in `app-sidebar.tsx`
6. Add permissions to seeder

### Custom Telemetry Adapter
Implement `TelemetryAdapterInterface`:
```php
class CustomAdapter implements TelemetryAdapterInterface
{
    public function parse(array $payload): array;
    public function normalize(array $data): TelemetryDTO;
}
```

Register in `AdapterRegistry`.

## Anti-Patterns to Avoid

1. **N+1 Queries** - Always eager load relationships
2. **Large Transactions** - Keep transactions focused
3. **Direct DB access in views** - Use controller eager loading
4. **String interpolation in queries** - Use parameter binding
5. **Ignoring notification failures** - Always wrap in try-catch

## See Also

- `DEVELOPER_ONBOARDING.md` - Getting started guide
- `COMPLIANCE_ROADMAP.md` - Compliance feature tracking
- `SENTRY_SETUP.md` - Error monitoring setup
- `REDIS_SETUP.md` - Queue and cache configuration
