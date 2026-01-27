# Sentry Error Monitoring Setup Guide

This guide will help you set up Sentry for real-time error tracking and performance monitoring in your Laravel application.

## Why Sentry?

Sentry provides comprehensive error tracking and monitoring:

- **Real-time error alerts**: Get notified immediately when errors occur
- **Detailed stack traces**: See exactly where errors happened with full context
- **Performance monitoring**: Track slow database queries and API calls
- **User context**: Know which users are affected by issues
- **Release tracking**: Monitor error rates across deployments
- **Issue grouping**: Automatically group similar errors together

## Setup Steps

### 1. Create a Sentry Account

1. Go to [https://sentry.io/](https://sentry.io/)
2. Sign up for a free account (generous free tier available)
3. Create a new project:
   - Choose **Laravel** as the platform
   - Give it a name (e.g., "Oblivion Findings")
   - Note your **DSN** (Data Source Name) - you'll need this

### 2. Install Sentry Package

The `sentry/sentry-laravel` package is already added to composer.json. Install it:

```bash
composer install
```

### 3. Publish Sentry Configuration

Publish the Sentry config file:

```bash
php artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"
```

This creates `config/sentry.php`.

### 4. Configure Environment Variables

Update your `.env` file with your Sentry DSN:

```env
# Sentry Error Monitoring
SENTRY_LARAVEL_DSN=https://your-dsn@sentry.io/your-project-id
SENTRY_TRACES_SAMPLE_RATE=0.2
```

**Notes:**
- `SENTRY_LARAVEL_DSN`: Your unique DSN from Sentry dashboard
- `SENTRY_TRACES_SAMPLE_RATE`: Percentage of transactions to send (0.2 = 20%)
  - Start with 20% to avoid overwhelming your quota
  - Increase to 1.0 (100%) if you need more detailed performance data

### 5. Test Sentry Integration

Test that Sentry is working:

```bash
php artisan sentry:test
```

You should see a test error appear in your Sentry dashboard within seconds.

### 6. Configure User Context

Sentry is already configured to capture user context automatically. When errors occur, you'll see:
- User ID
- User email
- User name
- IP address
- Browser information

This is handled by the package automatically via Laravel's authentication system.

### 7. Set Up Release Tracking (Optional but Recommended)

To track errors across deployments, add this to your deployment script:

```bash
# Get current git commit
SENTRY_RELEASE=$(git log --format="%H" -n 1)

# Tell Sentry about this release
php artisan sentry:publish --release=$SENTRY_RELEASE
```

### 8. Configure Alert Rules

In your Sentry dashboard:

1. Go to **Settings** → **Alerts**
2. Create alert rules for:
   - New issues (send email/Slack immediately)
   - Spike in error rate (if errors increase by 200%)
   - Regression (if a resolved issue returns)

## Configuration Options

### Sampling Rates

Edit `config/sentry.php` to adjust sampling:

```php
// Percentage of errors to capture (default: 100%)
'sample_rate' => env('SENTRY_SAMPLE_RATE', 1.0),

// Percentage of performance transactions to capture
'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.2),
```

### Environments

Sentry automatically uses your `APP_ENV` value. To disable Sentry in development:

```env
# In .env.local
SENTRY_LARAVEL_DSN=
```

Leave it empty and Sentry won't send any errors.

### Before Send Hook

To filter sensitive data before sending to Sentry, edit `config/sentry.php`:

```php
'before_send' => function (\Sentry\Event $event): ?\Sentry\Event {
    // Remove sensitive data
    if ($event->getRequest()) {
        $request = $event->getRequest();
        $request['data'] = array_filter($request['data'] ?? [], function ($key) {
            return !in_array($key, ['password', 'password_confirmation', 'token']);
        }, ARRAY_FILTER_USE_KEY);
    }

    return $event;
},
```

## Usage

### Automatic Error Capture

Sentry automatically captures:
- Uncaught exceptions
- Fatal errors
- Database query errors
- HTTP client errors

### Manual Error Capture

Capture specific errors or messages:

```php
use Sentry\Laravel\Facade as Sentry;

// Capture an exception
try {
    $this->riskyOperation();
} catch (\Exception $e) {
    Sentry::captureException($e);
}

// Capture a message
Sentry::captureMessage('Something went wrong', \Sentry\Severity::warning());

// Add extra context
Sentry::configureScope(function (\Sentry\State\Scope $scope) use ($order): void {
    $scope->setContext('order', [
        'id' => $order->id,
        'total' => $order->total,
    ]);
});
```

### Breadcrumbs

Add breadcrumbs for debugging context:

```php
Sentry::addBreadcrumb(
    new \Sentry\Breadcrumb(
        \Sentry\Breadcrumb::LEVEL_INFO,
        \Sentry\Breadcrumb::TYPE_DEFAULT,
        'user-action',
        'User clicked export button',
        ['report_type' => 'medications']
    )
);
```

## Best Practices

### 1. Set Up Environments

Use different Sentry projects for staging and production:

```env
# .env.production
SENTRY_LARAVEL_DSN=https://production-dsn@sentry.io/prod-id

# .env.staging
SENTRY_LARAVEL_DSN=https://staging-dsn@sentry.io/staging-id
```

### 2. Add Context to Critical Operations

Add context before critical operations:

```php
public function processPayroll(Timesheet $timesheet)
{
    Sentry::configureScope(function (\Sentry\State\Scope $scope) use ($timesheet): void {
        $scope->setTag('operation', 'payroll');
        $scope->setContext('timesheet', [
            'id' => $timesheet->id,
            'user_id' => $timesheet->user_id,
            'status' => $timesheet->status,
        ]);
    });

    // Process payroll...
}
```

### 3. Monitor Performance

Track slow operations:

```php
$transaction = \Sentry\startTransaction([
    'op' => 'generate-report',
    'name' => 'Generate Medication Report',
]);

try {
    $report = $this->generateMedicationReport($client);
    $transaction->setStatus(\Sentry\Tracing\SpanStatus::ok());
} catch (\Exception $e) {
    $transaction->setStatus(\Sentry\Tracing\SpanStatus::internalError());
    throw $e;
} finally {
    $transaction->finish();
}
```

### 4. Filter Noise

Ignore common errors that aren't actionable:

```php
// In config/sentry.php
'ignore_exceptions' => [
    Illuminate\Auth\AuthenticationException::class,
    Illuminate\Validation\ValidationException::class,
],
```

### 5. Tag Issues

Use tags for filtering:

```php
Sentry::configureScope(function (\Sentry\State\Scope $scope): void {
    $scope->setTag('feature', 'medication-administration');
    $scope->setTag('severity', 'high');
});
```

## Monitoring Dashboard

### Key Metrics to Watch

In your Sentry dashboard, monitor:

1. **Error Rate**: Errors per minute/hour
2. **Affected Users**: How many users hit errors
3. **Most Common Issues**: What fails most often
4. **Performance**: Slowest transactions
5. **Release Health**: Error rate by deployment

### Issue Triage

When an issue appears:

1. **Check frequency**: Is it affecting many users?
2. **Review stack trace**: Where exactly did it fail?
3. **Check user context**: Who is affected?
4. **Look at breadcrumbs**: What led to the error?
5. **Assign & resolve**: Assign to a developer, fix, and mark resolved

## Cost Management

Sentry's free tier includes:
- 5,000 errors/month
- 10,000 performance transactions/month
- 1 user
- 30-day error retention

To stay within limits:
- Use `SENTRY_TRACES_SAMPLE_RATE=0.2` (20% sampling)
- Ignore noisy, non-critical errors
- Use separate projects for dev/staging (don't count against production quota)

## Troubleshooting

### Not Receiving Errors

1. Check DSN is correct in `.env`
2. Run `php artisan sentry:test`
3. Check Sentry dashboard → Settings → Projects → Client Keys
4. Verify `APP_ENV` is set correctly

### Too Many Errors

1. Lower `SENTRY_TRACES_SAMPLE_RATE`
2. Add more exceptions to `ignore_exceptions` in config
3. Set up error rate limits in Sentry dashboard

### Missing Context

Ensure you're adding context before errors occur:

```php
Sentry::configureScope(function (\Sentry\State\Scope $scope): void {
    $scope->setUser([
        'id' => auth()->id(),
        'email' => auth()->user()?->email,
    ]);
});
```

## Resources

- [Sentry Laravel Documentation](https://docs.sentry.io/platforms/php/guides/laravel/)
- [Sentry Dashboard](https://sentry.io/)
- [Best Practices](https://docs.sentry.io/product/sentry-basics/guides/getting-started/)
- [Performance Monitoring](https://docs.sentry.io/product/performance/)

## Next Steps

After Sentry is set up:

1. **Configure alerts** to notify your team
2. **Set up integrations** (Slack, email, PagerDuty)
3. **Review issues weekly** to identify patterns
4. **Track error rates** across deployments
5. **Use release tracking** to correlate errors with code changes
