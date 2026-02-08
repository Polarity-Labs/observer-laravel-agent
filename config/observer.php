<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control where the agent sends collected metrics.
    | The endpoint should be the full URL to your metrics ingestion API.
    | The API key is used for authentication.
    |
    */

    'api_endpoint' => env('OBSERVER_API_ENDPOINT', 'https://ingest.observer.dev/metrics'),
    'api_key' => env('OBSERVER_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Server Identification
    |--------------------------------------------------------------------------
    |
    | A unique name to identify this server in your monitoring dashboard.
    | Defaults to the machine's hostname so each server is distinguishable
    | when multiple servers run the same app with the same .env file.
    |
    | The server_fingerprint uniquely identifies the physical machine. It's
    | auto-generated from hardware identifiers (/etc/machine-id on Linux,
    | IOPlatformUUID on macOS). Override only if you need a custom identifier.
    |
    */

    'server_name' => env('OBSERVER_SERVER_NAME', gethostname()),
    'server_fingerprint' => env('OBSERVER_SERVER_FINGERPRINT'),

    /*
    |--------------------------------------------------------------------------
    | Collection Intervals
    |--------------------------------------------------------------------------
    |
    | How often (in seconds) each collector should run. Lower values give
    | more granular data but increase overhead slightly.
    |
    | Collectors with the same interval are grouped together automatically.
    | You can tune each collector independently based on how often the
    | underlying metric actually changes.
    |
    */

    'intervals' => [
        // System metrics - fast-changing metrics benefit from frequent collection
        'cpu' => env('OBSERVER_INTERVAL_CPU', 15),
        'memory' => env('OBSERVER_INTERVAL_MEMORY', 15),
        'network' => env('OBSERVER_INTERVAL_NETWORK', 15),
        'disk' => env('OBSERVER_INTERVAL_DISK', 300),              // 5 minutes - disk changes slowly
        'process' => env('OBSERVER_INTERVAL_PROCESS', 60),
        'top_processes' => env('OBSERVER_INTERVAL_TOP_PROCESSES', 300), // 5 minutes - aggregated snapshot

        // Laravel metrics
        'queue' => env('OBSERVER_INTERVAL_QUEUE', 60),
        'horizon' => env('OBSERVER_INTERVAL_HORIZON', 60),
        'scheduler' => env('OBSERVER_INTERVAL_SCHEDULER', 15),
        'logs' => env('OBSERVER_INTERVAL_LOGS', 60),

        // Health check - fast feedback on outages
        'health' => env('OBSERVER_INTERVAL_HEALTH', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Collectors
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific metric collectors.
    | Disabling unused collectors reduces overhead slightly.
    |
    */

    'collectors' => [
        // System metrics
        'cpu' => env('OBSERVER_COLLECT_CPU', true),
        'memory' => env('OBSERVER_COLLECT_MEMORY', true),
        'disk' => env('OBSERVER_COLLECT_DISK', true),
        'network' => env('OBSERVER_COLLECT_NETWORK', true),
        'process' => env('OBSERVER_COLLECT_PROCESS', true),
        'top_processes' => env('OBSERVER_COLLECT_TOP_PROCESSES', true),
        'spike_events' => env('OBSERVER_COLLECT_SPIKE_EVENTS', true),

        // Laravel-specific metrics
        'queue' => env('OBSERVER_COLLECT_QUEUE', true),
        'logs' => env('OBSERVER_COLLECT_LOGS', true),
        'health' => env('OBSERVER_COLLECT_HEALTH', true),

        'horizon' => env('OBSERVER_COLLECT_HORIZON', true),
        'scheduler' => env('OBSERVER_COLLECT_SCHEDULER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Endpoints
    |--------------------------------------------------------------------------
    |
    | The endpoints to ping for health checks. Each endpoint can have a URL,
    | name, and optional custom headers for authentication.
    |
    | Format:
    |   [
    |       ['url' => 'https://myapp.com/up', 'name' => 'app'],
    |       ['url' => 'https://api.myapp.com/health', 'name' => 'api', 'headers' => ['Authorization' => 'Bearer token']],
    |   ]
    |
    | The default configuration checks the standard Laravel health endpoint.
    |
    */

    'health_endpoints' => [
        ['url' => env('APP_URL').'/up', 'name' => 'app'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Monitoring
    |--------------------------------------------------------------------------
    |
    | Path to the Laravel log file or log directory to monitor for errors.
    | The agent watches this file and reports error counts and recent messages.
    | When set to a directory, the agent automatically finds the most recent
    | laravel*.log file (supports daily log channels).
    |
    */

    'log_path' => env('OBSERVER_LOG_PATH', storage_path('logs')),

    /*
    |--------------------------------------------------------------------------
    | Disk Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the agent monitors disk usage.
    |
    | disk_auto_discover: When enabled and disk_paths is empty, the agent
    |     automatically discovers mounted filesystems. Virtual filesystems
    |     (tmpfs, proc, etc.) and network filesystems (nfs, cifs, etc.)
    |     are automatically excluded.
    |
    | disk_paths: Explicit list of mount points to monitor. When non-empty,
    |     auto-discovery is skipped and only these paths are monitored.
    |     Leave empty to use auto-discovery.
    |
    | disk_exclude_types: Additional filesystem types to exclude during
    |     auto-discovery. The agent already excludes common virtual and
    |     network filesystems by default.
    |
    */

    'disk_auto_discover' => env('OBSERVER_DISK_AUTO_DISCOVER', true),

    'disk_paths' => array_filter(
        explode(',', env('OBSERVER_DISK_PATHS', '')),
    ),

    'disk_exclude_types' => array_filter(
        explode(',', env('OBSERVER_DISK_EXCLUDE_TYPES', '')),
    ),

    /*
    |--------------------------------------------------------------------------
    | Queue Names
    |--------------------------------------------------------------------------
    |
    | The queue names to monitor for job counts. The agent will check the
    | size of each listed queue. Comma-separated via environment variable.
    |
    */

    'queue_names' => array_filter(
        explode(',', env('OBSERVER_QUEUE_NAMES', 'default')),
    ),

    /*
    |--------------------------------------------------------------------------
    | Top Processes Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the agent tracks top processes by CPU and memory.
    |
    | top_processes_sample_interval: How often (in seconds) to sample process
    |     data internally. The agent aggregates these samples over the main
    |     collection interval before sending.
    |
    | top_processes_count: Number of top processes to report (by CPU and
    |     by memory, reported separately).
    |
    */

    'top_processes_sample_interval' => env('OBSERVER_TOP_PROCESSES_SAMPLE_INTERVAL', 30),
    'top_processes_count' => env('OBSERVER_TOP_PROCESSES_COUNT', 10),

    /*
    |--------------------------------------------------------------------------
    | Spike Detection Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the agent detects CPU and memory spikes. When a spike is
    | detected (usage exceeds threshold), the agent captures the top processes
    | to help identify the culprit.
    |
    | spike_cpu_threshold: CPU percentage that triggers a spike event (0-100).
    |
    | spike_memory_threshold: Memory percentage that triggers a spike event (0-100).
    |
    | spike_cooldown: Minimum seconds between spike events of the same type.
    |     Prevents alert storms during sustained high usage.
    |     CPU and memory have independent cooldowns.
    |
    | spike_top_n: Number of top processes to capture when a spike is detected.
    |
    */

    'spike_cpu_threshold' => env('OBSERVER_SPIKE_CPU_THRESHOLD', 50.0),
    'spike_memory_threshold' => env('OBSERVER_SPIKE_MEMORY_THRESHOLD', 50.0),
    'spike_cooldown' => env('OBSERVER_SPIKE_COOLDOWN', 60),
    'spike_top_n' => env('OBSERVER_SPIKE_TOP_N', 5),

    /*
    |--------------------------------------------------------------------------
    | System Metrics Mode (Multi-Agent Coordination)
    |--------------------------------------------------------------------------
    |
    | When multiple Laravel apps share a server, each runs its own observer
    | agent. System metrics (CPU, memory, disk, network, process) only need
    | to be sent once per server. This setting controls which agent sends them.
    |
    | Supported values:
    |   "auto"     - File-lock-based leader election. One agent wins the lock
    |                and sends system metrics; others send only app metrics.
    |                If the leader dies, another agent takes over automatically.
    |                This is the recommended default — works transparently for
    |                both single-agent and multi-agent setups.
    |   "enabled"  - Always send system metrics (skip election).
    |   "disabled" - Never send system metrics (app metrics only).
    |
    */

    'system_metrics' => env('OBSERVER_SYSTEM_METRICS', 'auto'),

];
