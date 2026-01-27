# Redis Setup Guide

This guide will help you set up Redis for caching and queue management in your Laravel application.

## Why Redis?

Redis provides significant performance improvements over database-backed cache and queues:

- **Faster cache operations**: In-memory storage is much faster than database queries
- **Better queue performance**: Efficient job processing with lower overhead
- **Reduced database load**: Offloads cache and queue operations from your database
- **Production-ready**: Industry standard for Laravel applications at scale

## Prerequisites

### Windows (Laravel Herd)

Laravel Herd includes Redis built-in. To start Redis:

1. Open your terminal
2. Check if Redis is running:
   ```bash
   redis-cli ping
   ```
   If it responds with `PONG`, Redis is running.

3. If Redis is not running, Herd should start it automatically when needed.

### Alternative: WSL2 (Windows Subsystem for Linux)

If you prefer to run Redis in WSL2:

```bash
# Update package list
sudo apt update

# Install Redis
sudo apt install redis-server

# Start Redis
sudo service redis-server start

# Verify it's running
redis-cli ping
```

### macOS (with Homebrew)

```bash
# Install Redis
brew install redis

# Start Redis
brew services start redis

# Verify it's running
redis-cli ping
```

## Setup Steps

### 1. Install Redis PHP Client

The `predis/predis` package is already added to composer.json. Install it:

```bash
composer install
```

### 2. Update Your Environment File

Update your `.env` file to use Redis for cache and queue:

```env
# Change from database to redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis

# Redis connection settings (these are usually correct by default)
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### 3. Clear Your Cache

After changing cache drivers:

```bash
php artisan cache:clear
php artisan config:clear
```

### 4. Test Redis Connection

Test that Laravel can connect to Redis:

```bash
php artisan tinker
```

Then in tinker:

```php
Cache::store('redis')->put('test', 'success', 60);
Cache::store('redis')->get('test'); // Should return 'success'
exit
```

### 5. Update Queue Worker (if using queues)

If you're running queue workers, restart them:

```bash
# Stop any existing workers first (Ctrl+C)
php artisan queue:restart

# Start the worker with Redis
php artisan queue:work redis
```

## Development vs Production

### Development (current setup)

For local development, you can keep using database drivers if Redis setup is inconvenient:

```env
CACHE_STORE=database
QUEUE_CONNECTION=database
```

### Production (recommended)

For production environments, **always use Redis**:

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

## Monitoring Redis

### Check Redis Info

```bash
redis-cli info
```

### Monitor Real-time Activity

```bash
redis-cli monitor
```

### Clear All Redis Data

**⚠️ Warning: This will delete all cached data**

```bash
redis-cli FLUSHALL
```

Or from Laravel:

```bash
php artisan cache:clear
```

## Troubleshooting

### Connection Refused

If you see "Connection refused" errors:

1. Verify Redis is running:
   ```bash
   redis-cli ping
   ```

2. Check the Redis host/port in your `.env` file

3. On Windows with Herd, ensure Herd is running

### Performance Not Improved

1. Verify you're actually using Redis:
   ```bash
   php artisan tinker
   Cache::getStore()->getStore()->getConnection(); // Should show Redis connection
   ```

2. Clear old cache:
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

### Sessions Issues

If you want to also use Redis for sessions (optional):

```env
SESSION_DRIVER=redis
```

Then clear sessions:

```bash
php artisan session:clear
```

## Next Steps

After Redis is set up, consider:

1. **Horizon** (for queue monitoring): `composer require laravel/horizon`
2. **Rate limiting improvements**: Redis-based rate limiting is already configured
3. **Cache optimization**: Add cache tags and TTL strategies
4. **Queue optimization**: Configure multiple queue workers for different priority jobs

## Resources

- [Laravel Cache Documentation](https://laravel.com/docs/cache)
- [Laravel Queue Documentation](https://laravel.com/docs/queues)
- [Redis Documentation](https://redis.io/documentation)
