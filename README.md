# Observer

Lightweight server and Laravel application monitoring.

Observer is a monitoring agent that collects system metrics (CPU, memory, disk, network) and Laravel-specific metrics (queue health, Horizon, log errors, HTTP health checks) and sends them to a central dashboard.

## Features

- **Minimal overhead** — The Go-based agent uses ~10MB RAM and near-zero CPU
- **Zero configuration** — Reads your existing `.env` and Laravel config automatically
- **Laravel native** — Install via Composer, run via Artisan
- **System metrics** — CPU usage, memory, disk space, network I/O, load averages
- **Process monitoring** — Top 10 processes by CPU and memory with 5-minute aggregated snapshots
- **Spike detection** — Captures CPU/memory spikes with culprit process identification for diagnosing OOM/resource issues
- **Laravel metrics** — Queue health, Horizon status, log error monitoring, HTTP health checks
- **Custom health checks** — Register arbitrary PHP callbacks (DB pings, Redis checks, etc.) alongside URL checks
- **External health checks** — Have Observer SaaS ping your endpoints from outside your network
- **Per-check leader gating** — Control whether each health check runs on the leader agent only or on all agents
- **Multi-agent aware** — Automatic leader election prevents duplicate system metrics when multiple apps share a server
- **Reliable delivery** — Buffers metrics locally if the API is unreachable

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Linux or macOS server

## Installation

```bash
composer require polarity-labs/observer-agent
```

Publish the configuration file (optional):

```bash
php artisan vendor:publish --tag=observer-config
```

## Configuration

Add your API key to your `.env` file:

```env
OBSERVER_API_KEY=your-secret-api-key
```

That's it! The agent reads everything else from your existing Laravel configuration. The Go binary is downloaded automatically on first run.

### Optional Configuration

```env
# Server identification (defaults to machine hostname)
OBSERVER_SERVER_NAME=web-1

# Collection intervals in seconds (per-collector)
OBSERVER_INTERVAL_CPU=15
OBSERVER_INTERVAL_MEMORY=15
OBSERVER_INTERVAL_DISK=300
OBSERVER_INTERVAL_NETWORK=15
OBSERVER_INTERVAL_QUEUE=60
OBSERVER_INTERVAL_HEALTH=15

# Enable/disable specific collectors
OBSERVER_COLLECT_CPU=true
OBSERVER_COLLECT_MEMORY=true
OBSERVER_COLLECT_DISK=true
OBSERVER_COLLECT_NETWORK=true
OBSERVER_COLLECT_QUEUE=true
OBSERVER_COLLECT_LOGS=true
OBSERVER_COLLECT_HEALTH=true
OBSERVER_COLLECT_PROCESS=true
OBSERVER_COLLECT_TOP_PROCESSES=true  # Top 10 processes by CPU/memory
OBSERVER_COLLECT_SPIKE_EVENTS=true   # CPU/memory spike detection with culprit processes
OBSERVER_COLLECT_HORIZON=true        # Disable if not using Laravel Horizon
OBSERVER_COLLECT_SCHEDULER=true      # Disable if not monitoring scheduler health

# Top processes configuration
OBSERVER_INTERVAL_TOP_PROCESSES=300        # Emit interval in seconds (default: 300)
OBSERVER_TOP_PROCESSES_SAMPLE_INTERVAL=30  # Internal sampling rate (default: 30)
OBSERVER_TOP_PROCESSES_COUNT=10            # Number of top processes to track (default: 10)

# Spike detection configuration
OBSERVER_SPIKE_CPU_THRESHOLD=90.0        # CPU % that triggers a spike (default: 90)
OBSERVER_SPIKE_MEMORY_THRESHOLD=90.0     # Memory % that triggers a spike (default: 90)
OBSERVER_SPIKE_COOLDOWN=60               # Seconds between spike events of the same type (default: 60)
OBSERVER_SPIKE_TOP_N=5                   # Number of top processes to capture on spike (default: 5)

# Multi-agent coordination (default: "auto")
# auto = file-lock election, one agent sends system metrics per server
# enabled = always send system metrics (skip election)
# disabled = never send system metrics (app metrics only)
OBSERVER_SYSTEM_METRICS=auto

# Queue names to monitor (comma-separated, default: "default")
OBSERVER_QUEUE_NAMES=default,emails,notifications
```

## Usage

### Check configuration

```bash
php artisan observer:status
```

This shows your current configuration and validates everything is set up correctly.

### Start monitoring

```bash
php artisan observer:start
```

The agent will run continuously, collecting and sending metrics. Press `Ctrl+C` to stop.

### Restart after deployment

```bash
php artisan observer:restart
```

This signals the running agent to exit cleanly. When running under Supervisor with `autorestart=true`, the agent restarts automatically and picks up the new binary.

Add this to your deploy script after `composer install`.

### Test mode (single collection)

```bash
php artisan observer:start --once
```

Collects metrics once and exits. Useful for testing your configuration.

## Health Checks

Observer supports three types of health checks:

### URL Health Checks (Agent)

Configured in `config/observer.php`. The Go agent pings these URLs from the server:

```php
'health_endpoints' => [
    ['url' => env('APP_URL').'/up', 'name' => 'app'],
    ['url' => 'https://api.myapp.com/health', 'name' => 'api', 'headers' => ['Authorization' => 'Bearer token']],
],
```

### URL Health Checks (External)

Same config format, but checked by Observer SaaS from outside your network:

```php
'health_endpoints' => [
    ['url' => 'https://myapp.com', 'name' => 'public-site', 'check_from' => 'external'],
],
```

The agent sends the endpoint configuration to Observer SaaS, which pings the URL every minute from its own infrastructure. This verifies your app is reachable from the outside world.

### Custom Health Checks

Register arbitrary PHP callbacks to check anything — databases, Redis, external APIs, file systems, etc. Custom checks run inside your Laravel process (via the `observer:metrics` artisan command) rather than from the Go binary.

Register checks in a service provider's `boot()` method:

```php
use PolarityLabs\ObserverAgent\Observer;
use PolarityLabs\ObserverAgent\HealthCheckResult;

public function boot(): void
{
    $observer = app(Observer::class);

    $observer->healthCheck('redis', function () {
        try {
            Redis::ping();
            return HealthCheckResult::healthy()->responseTime(1);
        } catch (\Exception $e) {
            return HealthCheckResult::unhealthy()->errorMessage($e->getMessage());
        }
    });

    $observer->healthCheck('database', function () {
        $start = hrtime(true);
        DB::select('SELECT 1');
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);
        return HealthCheckResult::healthy()->responseTime($ms);
    });
}
```

#### HealthCheckResult API

```php
// Static constructors
HealthCheckResult::healthy();
HealthCheckResult::unhealthy();
HealthCheckResult::make(isHealthy: true, responseTimeMs: 42, errorMessage: null);

// Chainable methods
HealthCheckResult::healthy()->responseTime(42);
HealthCheckResult::unhealthy()->responseTime(5000)->errorMessage('Connection refused');
```

If a callback throws an exception, Observer catches it and reports the check as unhealthy with the exception message.

### Per-Check Leader Gating

By default, all health checks (URL and custom) run only on the leader agent to avoid duplicate checks. Override this per-check:

```php
// Config-based (URL checks)
['url' => 'http://localhost/up', 'name' => 'local', 'leader_only' => false],

// Code-based (custom checks)
$observer->healthCheck('redis', $callback, leaderOnly: false);
```

Set `leader_only: false` when you want every agent instance to run the check (e.g., checking a local service on each server).

## Multiple Apps on One Server

When multiple Laravel apps share a server, each runs its own Observer agent. System metrics (CPU, memory, disk, network) only need to be sent once per server, while app metrics (queues, logs, health checks) are unique to each app.

Observer handles this automatically via file-based leader election. The first agent to start acquires a lock and becomes the **leader** (sends system + app metrics). Other agents become **followers** (send app metrics only). If the leader stops, a follower is promoted within ~10 seconds.

No configuration is needed — the default `OBSERVER_SYSTEM_METRICS=auto` works transparently for both single-app and multi-app setups.

To override the automatic behavior:

```env
# Force this agent to always send system metrics
OBSERVER_SYSTEM_METRICS=enabled

# Force this agent to never send system metrics
OBSERVER_SYSTEM_METRICS=disabled
```

## Running in Production

For production, run the agent under a process manager like Supervisor:

```ini
# /etc/supervisor/conf.d/observer.conf
[program:observer]
command=php /var/www/yourapp/artisan observer:start
directory=/var/www/yourapp
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/observer.log
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start observer
```

## Testing

```bash
# PHP tests
composer install
vendor/bin/pest

# Go agent tests (see agent/README.md)
cd agent && make test
```

## License

MIT License. See [LICENSE](LICENSE) for details.
