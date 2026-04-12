# Developer Onboarding Guide

Welcome to the Oblivion Findings development team! This guide will help you get up and running quickly.

## Prerequisites

- PHP 8.2+
- Node.js 20+
- MySQL 8.0+ or PostgreSQL 14+
- Redis (for queues and caching)
- Composer
- npm

## Quick Start

### 1. Clone and Install

```bash
git clone <repository-url>
cd oblivionfindings
composer install
npm install
```

### 2. Environment Setup

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=oblivionfindings
DB_USERNAME=root
DB_PASSWORD=your_password

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### 3. Database Setup

```bash
php artisan migrate
php artisan db:seed
# or, from the frontend toolchain:
npm run seed
```

### 4. Start Development Server

```bash
composer run dev
```

This starts:
- Laravel development server (http://localhost:8000)
- Queue worker
- Vite dev server for HMR

## Project Structure

```
app/
├── Console/Commands/     # Artisan commands
├── Events/               # Laravel events
├── Http/
│   ├── Controllers/      # Request handlers
│   ├── Middleware/       # Request middleware
│   └── Requests/         # Form request validation
├── Models/               # Eloquent models
├── Observers/            # Model observers
├── Policies/             # Authorization policies
├── Services/             # Business logic services
└── Providers/            # Service providers

resources/js/
├── components/
│   ├── ui/              # shadcn/ui components
│   └── ...              # Custom components
├── hooks/               # React hooks
├── layouts/             # Page layouts
├── pages/               # Inertia pages
└── lib/                 # Utilities

routes/
├── web.php              # Main web routes
├── clients.php          # Client management routes
├── shifts.php           # Shift scheduling routes
└── ...                  # Domain-specific route files
```

## Key Concepts

### Permission System

We use a custom RBAC system. Check permissions with:

```php
$user->canDo('shifts.create');
$user->canDo('clients.viewAny');
```

Available permissions are defined in the `permissions` table and assigned via roles.

### Service Context Resolution

All shifts and clients belong to a "service context". Use the `ServiceContextResolver`:

```php
$contextId = app(ServiceContextResolver::class)
    ->resolveForClient($clientId, $providedContextId);
```

### Frontend Filtering

Use the `useFilters` hook for consistent filter behavior:

```typescript
const { filters, updateFilter, isPending } = useFilters({
    route: '/shifts',
    initial: { status: null, client_id: null },
    debounceMs: 300,
});
```

### Notification Handling

Notifications are wrapped in try-catch to prevent delivery failures from breaking requests:

```php
try {
    app(NotificationService::class)->notifyCrud(...);
} catch (\Throwable $e) {
    Log::warning('Notification failed', [...]);
}
```

## Configuration

### Dashboard Config (`config/dashboard.php`)

Control dashboard widget limits and date ranges:
- `max_upcoming_events`
- `max_upcoming_shifts`
- `history_days`

### UI Config (`config/ui.php`)

Configure UI behavior:
- Sidebar cookie settings
- Pagination defaults

## Testing

```bash
# Run PHPUnit tests
php artisan test

# Run Pest tests
./vendor/bin/pest

# Type checking
npm run types

# Linting
npm run lint
```

## Code Style

- PHP: Follows Laravel Pint standards (`./vendor/bin/pint`)
- TypeScript: ESLint + Prettier (`npm run format`)

## Common Tasks

### Adding a New Permission

1. Add to `database/seeders/PermissionSeeder.php`
2. Run `php artisan db:seed --class=PermissionSeeder`
3. Add to `HandleInertiaRequests.php` for frontend access
4. Add policy method if needed

### Creating a New Page

1. Create controller method
2. Add route to appropriate routes file
3. Create Inertia page component in `resources/js/pages/`
4. Add navigation link in `app-sidebar.tsx` (if needed)

### Database Migrations

Always use transactions for data integrity:

```php
DB::transaction(function () {
    // Your operations
});
```

## Troubleshooting

### Queue not processing jobs
```bash
php artisan queue:restart
```

### Vite HMR not working
```bash
rm -rf node_modules/.vite
npm run dev
```

### Permission denied errors
```bash
php artisan cache:clear
php artisan config:clear
```

## Resources

- [Laravel Docs](https://laravel.com/docs/12.x)
- [Inertia.js Docs](https://inertiajs.com/)
- [shadcn/ui Docs](https://ui.shadcn.com/)
- [Tailwind CSS Docs](https://tailwindcss.com/docs)

## Support

- Backend questions: Check `app/Services/` for examples
- Frontend questions: Check `resources/js/components/` for patterns
- Architecture questions: See `docs/ARCHITECTURE.md`
