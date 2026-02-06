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

That's it! The agent reads everything else from your existing Laravel configuration.

### Optional Configuration

```env
# Server identification (defaults to machine hostname)
OBSERVER_SERVER_NAME=web-1

# Collection intervals in seconds
OBSERVER_INTERVAL_SYSTEM=30    # CPU, memory, disk, network (default: 30)
OBSERVER_INTERVAL_LARAVEL=60   # Queue, Horizon, logs (default: 60)
OBSERVER_INTERVAL_HEALTH=15    # HTTP health checks (default: 15)

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
OBSERVER_COLLECT_HORIZON=false       # Enable if using Laravel Horizon
OBSERVER_COLLECT_SCHEDULER=false     # Enable to monitor scheduler health

# Top processes configuration
OBSERVER_INTERVAL_TOP_PROCESSES=300        # Emit interval in seconds (default: 300)
OBSERVER_TOP_PROCESSES_SAMPLE_INTERVAL=30  # Internal sampling rate (default: 30)
OBSERVER_TOP_PROCESSES_COUNT=10            # Number of top processes to track (default: 10)

# Spike detection configuration
OBSERVER_COLLECT_SPIKE_EVENTS=true       # Enable spike detection (default: true)
OBSERVER_SPIKE_CPU_THRESHOLD=90.0        # CPU % that triggers a spike (default: 90)
OBSERVER_SPIKE_MEMORY_THRESHOLD=90.0     # Memory % that triggers a spike (default: 90)
OBSERVER_SPIKE_COOLDOWN=60               # Seconds between spike events of the same type (default: 60)
OBSERVER_SPIKE_TOP_N=5                   # Number of top processes to capture on spike (default: 5)

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

### Test mode (single collection)

```bash
php artisan observer:start --once
```

Collects metrics once and exits. Useful for testing your configuration.

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

## Metrics Collected

### System Metrics

| Metric | Description |
|--------|-------------|
| `cpu.usage_percent` | Current CPU usage (0-100%) |
| `cpu.load_1m` | 1-minute load average |
| `cpu.load_5m` | 5-minute load average |
| `cpu.load_15m` | 15-minute load average |
| `memory.total_mb` | Total system memory |
| `memory.used_mb` | Memory in use |
| `memory.available_mb` | Memory available |
| `memory.usage_percent` | Memory usage percentage |
| `disk./.total_gb` | Total disk space |
| `disk./.used_gb` | Disk space used |
| `disk./.usage_percent` | Disk usage percentage |
| `network.bytes_in` | Total bytes received |
| `network.bytes_out` | Total bytes sent |
| `network.bytes_in_per_sec` | Receive rate (bytes/sec) |
| `network.bytes_out_per_sec` | Send rate (bytes/sec) |

### Top Processes Metrics

Aggregated over a 5-minute window with internal sampling every 30 seconds.

| Metric | Description |
|--------|-------------|
| `top_processes.by_cpu[]` | Top 10 processes ranked by CPU usage |
| `top_processes.by_memory[]` | Top 10 processes ranked by memory usage |
| `top_processes.window_seconds` | Aggregation window duration |
| `top_processes.samples_taken` | Number of samples in the window |

Each process entry includes:
- `pid` — Process ID
- `name` — Process name (truncated to 50 chars)
- `command` — Full command line (truncated to 200 chars)
- `user` — User/UID running the process
- `cpu_percent` — Average CPU usage percentage
- `mem_percent` — Average memory usage percentage
- `mem_bytes` — Average memory usage in bytes

### Spike Events

Spike events are captured when CPU or memory usage exceeds configured thresholds. This helps diagnose sudden resource issues (OOM kills, runaway processes) by capturing the top processes at the moment of the spike.

| Metric | Description |
|--------|-------------|
| `spike_events.events[]` | Array of spike events since last collection |
| `spike_events.events[].timestamp` | When the spike was detected |
| `spike_events.events[].spike_type` | "cpu" or "memory" |
| `spike_events.events[].trigger_value` | The CPU/memory % that triggered the spike |
| `spike_events.events[].threshold` | The configured threshold that was exceeded |
| `spike_events.events[].top_processes[]` | Top N processes at spike time |

Each spike's `top_processes` includes:
- `pid` — Process ID
- `name` — Process name
- `command` — Full command line
- `user` — User running the process
- `cpu_percent` — CPU usage at spike time
- `mem_percent` — Memory usage at spike time
- `mem_bytes` — Memory bytes at spike time

**Cooldown:** To prevent alert storms, spikes of the same type (CPU or memory) are rate-limited by a configurable cooldown period (default: 60 seconds). CPU and memory cooldowns are independent.

### Laravel Metrics

| Metric | Description |
|--------|-------------|
| `health.status` | "healthy" or "unhealthy" |
| `health.response_time_ms` | Response time in milliseconds |
| `logs.errors_1h` | Error count in last hour |
| `logs.errors_24h` | Error count in last 24 hours |
| `logs.last_error` | Most recent error message |
| `queue.total_jobs` | Total pending jobs across queues |
| `queue.failed_jobs` | Total failed jobs |
| `queue.failed_recent_1h` | Failed jobs in last hour |
| `horizon.status` | Horizon status (running/inactive) |
| `horizon.pending_jobs` | Pending jobs across all queues |
| `horizon.completed_jobs_1h` | Completed jobs in last hour |

## API Payload Format

Metrics are sent as JSON POST requests:

```json
{
    "server": "web-1",
    "timestamp": "2024-01-25T14:30:00Z",
    "metrics": {
        "cpu": {
            "usage_percent": 23.5,
            "load_1m": 0.45,
            "load_5m": 0.38,
            "load_15m": 0.32,
            "num_cores": 4
        },
        "memory": {
            "total_mb": 8192,
            "used_mb": 3421,
            "available_mb": 4771,
            "usage_percent": 41.76
        },
        "disk": {
            "/": {
                "total_gb": 100,
                "used_gb": 45.5,
                "available_gb": 54.5,
                "usage_percent": 45.5
            }
        },
        "network": {
            "bytes_in": 123456789,
            "bytes_out": 987654321,
            "bytes_in_per_sec": 15234.5,
            "bytes_out_per_sec": 8921.3,
            "packets_in": 234567,
            "packets_out": 198765,
            "packets_in_per_sec": 120.5,
            "packets_out_per_sec": 95.2
        },
        "health": {
            "status": "healthy",
            "response_time_ms": 145,
            "status_code": 200
        },
        "logs": {
            "errors_1h": 0,
            "errors_24h": 3,
            "file_size_mb": 12.5
        },
        "queue": {
            "connection": "redis",
            "total_jobs": 12,
            "queues": {
                "default": {"size": 10},
                "emails": {"size": 2}
            },
            "failed_jobs": 3,
            "failed_recent_1h": 1
        },
        "top_processes": {
            "by_cpu": [
                {"pid": 1234, "name": "nginx", "command": "nginx: worker process", "user": "www-data", "cpu_percent": 15.5, "mem_percent": 2.3, "mem_bytes": 48234496},
                {"pid": 5678, "name": "php-fpm", "command": "php-fpm: pool www", "user": "www-data", "cpu_percent": 8.2, "mem_percent": 5.1, "mem_bytes": 106954752}
            ],
            "by_memory": [
                {"pid": 9999, "name": "mysql", "command": "/usr/sbin/mysqld", "user": "mysql", "cpu_percent": 3.2, "mem_percent": 45.5, "mem_bytes": 954204160},
                {"pid": 1234, "name": "nginx", "command": "nginx: worker process", "user": "www-data", "cpu_percent": 15.5, "mem_percent": 2.3, "mem_bytes": 48234496}
            ],
            "window_seconds": 300,
            "samples_taken": 10
        },
        "spike_events": {
            "events": [
                {
                    "timestamp": "2024-01-25T14:29:55Z",
                    "spike_type": "cpu",
                    "trigger_value": 95.5,
                    "threshold": 90.0,
                    "top_processes": [
                        {"pid": 8888, "name": "stress", "command": "stress --cpu 8", "user": "root", "cpu_percent": 85.2, "mem_percent": 0.1, "mem_bytes": 2097152},
                        {"pid": 5678, "name": "php-fpm", "command": "php-fpm: pool www", "user": "www-data", "cpu_percent": 8.5, "mem_percent": 5.1, "mem_bytes": 106954752}
                    ]
                }
            ]
        }
    }
}
```

## Building from Source

The agent is written in Go. To build:

```bash
cd agent
make build-all VERSION=1.0.0
```

This creates binaries for all supported platforms in the `bin/` directory.

## Testing

The Go agent includes a comprehensive test suite using only the standard library (`testing` and `net/http/httptest`). No external test dependencies are required.

```bash
cd agent

# Run all tests
make test

# Run tests with verbose output
go test -v ./...

# Run tests with coverage
go test -cover ./...

# Run tests for a specific package
go test -v ./internal/config/
go test -v ./internal/collector/
go test -v ./internal/transport/
go test -v ./cmd/
```

Platform-specific tests (e.g. macOS CPU parsing, swap field parsing) use Go build tags and will only run on the matching platform.

## License

MIT License. See [LICENSE](LICENSE) for details.
