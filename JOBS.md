# Backend Background Jobs & Scheduling Guide

## Overview

SMSGang backend uses Laravel's task scheduling combined with queue workers running inside Docker containers. All jobs are managed automatically without external cron servers.

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│           Docker Container (Supervisor)                  │
├─────────────────────────────────────────────────────────┤
│                                                           │
│  ┌─────────────────┐  ┌─────────────────┐              │
│  │   Scheduler     │  │  Queue Workers  │              │
│  │  (runs tasks    │  │  (process jobs) │              │
│  │   every minute) │  │  (4 processes)  │              │
│  └─────────────────┘  └─────────────────┘              │
│         ↓                        ↓                       │
│  ┌─────────────────────────────────────┐               │
│  │     Laravel Task Scheduler          │               │
│  │     (app/Console/Kernel.php)        │               │
│  └─────────────────────────────────────┘               │
│                      ↓                                   │
│  ┌─────────────────────────────────────┐               │
│  │          Job Queue (Redis)           │               │
│  │  ┌──────────────┐  ┌──────────────┐ │               │
│  │  │SyncPricing  │  │CheckAllSMS   │ │               │
│  │  │ExpireActive │  │Others...     │ │               │
│  │  └──────────────┘  └──────────────┘ │               │
│  └─────────────────────────────────────┘               │
│                                                           │
└─────────────────────────────────────────────────────────┘
```

## Jobs Overview

### 1. **SyncAllPricingJob**
- **Schedule**: Every hour at `:05` (5 minutes past the hour)
- **Purpose**: Sync pricing data from 5sim and update exchange rates from RapidAPI
- **Duration**: ~2-5 minutes
- **Failure Impact**: Pricing becomes stale, but doesn't affect live activations
- **Location**: `app/Jobs/SyncAllPricingJob.php`

### 2. **CheckAllActiveSmsJob**
- **Schedule**: Every 5 minutes
- **Purpose**: Check SMS codes for all pending activations from 5sim
- **Duration**: ~30 seconds to 1 minute
- **Failure Impact**: Users may not see SMS updates until next check
- **Location**: `app/Jobs/CheckAllActiveSmsJob.php`

### 3. **ExpireActivationsJob**
- **Schedule**: Every 30 minutes
- **Purpose**: Mark old or expired activations as expired
- **Duration**: ~1-2 minutes
- **Failure Impact**: Old activations might not be marked expired
- **Location**: `app/Jobs/ExpireActivationsJob.php`

### 4. **CheckSmsJob**
- **Schedule**: On-demand (triggered manually or by other code)
- **Purpose**: Check a single activation's SMS status
- **Duration**: < 1 second
- **Location**: `app/Jobs/CheckSmsJob.php`

## Monitoring Jobs

### View Running Processes

```bash
# Check all supervisor processes
make shell
supervisorctl status

# Output should show:
# laravel-scheduler    RUNNING
# laravel-worker:0     RUNNING
# laravel-worker:1     RUNNING
# laravel-worker:2     RUNNING
# laravel-worker:3     RUNNING
# nginx                RUNNING
# php-fpm              RUNNING
```

### View Job Execution Logs

```bash
# Watch scheduler
make logs | grep scheduler

# Watch workers
make logs | grep worker

# Watch specific job type
docker-compose logs app | grep "SyncAllPricingJob"
```

### Check Failed Jobs

```bash
# List failed jobs
make queue-failed

# View details of a specific failed job
docker-compose exec app php artisan queue:failed --id=1

# Retry a single failed job
docker-compose exec app php artisan queue:retry 1

# Retry all failed jobs
make queue-retry-all

# Forget a failed job
docker-compose exec app php artisan queue:forget 1
```

### Clear Job Queue

```bash
# Flush all queued jobs
docker-compose exec app php artisan queue:flush

# Flush failed jobs
docker-compose exec app php artisan queue:failed-flush
```

## Queue Configuration

In `config/queue.php`:

```php
'default' => env('QUEUE_CONNECTION', 'redis'),

'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,  // Retry failed jobs after 90 seconds
        'block_for' => null,
    ],
]
```

## Scheduling Configuration

In **Laravel 12**, the scheduler is defined in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SyncAllPricingJob())
    ->hourlyAt(5)                    // Every hour at :05
    ->withoutOverlapping(10)         // 10-min lock to prevent overlap
    ->onSuccess(function () {        // Log on success
        Log::channel('activity')->info('✅ Pricing sync completed');
    })
    ->onFailure(function () {        // Log on failure
        Log::channel('activity')->error('❌ Pricing sync failed');
    });
```

No `Kernel.php` file is needed in Laravel 12.

## Adding New Jobs

### Step 1: Create the Job

```bash
docker-compose exec app php artisan make:job MyNewJob
```

### Step 2: Implement the Job Logic

Edit `app/Jobs/MyNewJob.php`:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MyNewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        // Job constructor - data needed for the job
    }

    public function handle(): void
    {
        // Job logic here
    }
}
```

### Step 3: Schedule or Dispatch the Job

**For scheduled jobs**, add to `routes/console.php`:

```php
Schedule::job(new MyNewJob())
    ->everyMinute()
    ->withoutOverlapping(5);
```

**For manual dispatch**:

```php
MyNewJob::dispatch();

// Or with delay
MyNewJob::dispatch()->delay(now()->addSeconds(30));
```

## Troubleshooting

### Jobs Not Running

```bash
# Check if scheduler process is running
make shell
supervisorctl status laravel-scheduler

# Check scheduler logs
docker-compose logs app | grep scheduler

# Manually test the scheduler
docker-compose exec app php artisan schedule:run

# Run a specific job manually
docker-compose exec app php artisan queue:work
```

### Job Timeout

If a job takes too long:

```bash
# In supervisor.conf, increase timeout
process_name=%(program_name)s
command=php /var/www/html/artisan queue:work redis --sleep=3 --tries=1 --timeout=0
```

`--timeout=0` means no timeout (infinite), good for long-running jobs.

### Out of Memory

If workers crash:

```bash
# In supervisor.conf, limit number of workers
numprocs=2  # Reduce from 4 to 2

# Or increase PHP memory limit in docker/php.ini
memory_limit = 1024M
```

### Job Hanging/Stuck

```bash
# Restart all workers
make shell
supervisorctl restart all

# Or restart individual worker
supervisorctl restart laravel-worker:0
```

## Performance Tips

1. **Use batches for bulk operations**:
   ```php
   Bus::batch([
       new MyJob(1),
       new MyJob(2),
       new MyJob(3),
   ])->dispatch();
   ```

2. **Add job middleware for cleanup**:
   ```php
   public function middleware(): array
   {
       return [new WithoutOverlapping];
   }
   ```

3. **Monitor queue depth**:
   ```bash
   docker-compose exec redis redis-cli LLEN queues:default
   ```

4. **Use async filesystem for better performance**:
   ```php
   Storage::disk('local')->putFileAs('path', $file, $name, 'public');
   ```

5. **Batch database operations**:
   ```php
   Model::insert($chunks); // Bulk insert instead of individual saves
   ```

## Production Considerations

### 1. Monitor Job Failures

```bash
# Set up monitoring/alerts for failed jobs
docker-compose exec app php artisan queue:failed | wc -l
```

### 2. Database Connection Timeout

For long-running jobs, add to job:

```php
public function handle()
{
    DB::reconnect();
    // ... job logic
}
```

### 3. Memory Leaks

Monitor memory usage:

```bash
docker stats
```

### 4. Graceful Shutdown

Docker will send SIGTERM to the container. Supervisor handles graceful shutdown automatically with `stopwaitsecs=3600`.

### 5. Job Visibility

Add persistent logging:

```php
Log::channel('activity')->info('Job processing', [
    'job' => $this->job,
    'attempt' => $this->attempts(),
    'data' => $this->data,
]);
```

## Monitoring & Alerts

### Check Health of All Services

```bash
# All containers healthy?
docker-compose ps

# Output shows Health
# app    Up    (healthy)
```

### Log Aggregation

Collect logs from all sources:

```bash
docker-compose logs --tail=100 --follow
```

### Alert Triggers

Consider setting up alerts for:
1. Failed jobs count > 10
2. Queue depth > 1000
3. MySQL connection errors
4. Redis connection errors
5. Scheduler failed to run

---

**Last Updated**: March 14, 2026
**Version**: 1.0
