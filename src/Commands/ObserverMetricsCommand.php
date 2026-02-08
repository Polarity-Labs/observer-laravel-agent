<?php

namespace PolarityLabs\ObserverAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PolarityLabs\ObserverAgent\Concerns\ExtractsSchedulerTaskInfo;
use PolarityLabs\ObserverAgent\HealthCheckResult;
use PolarityLabs\ObserverAgent\Observer;

class ObserverMetricsCommand extends Command
{
    use ExtractsSchedulerTaskInfo;

    protected $signature = 'observer:metrics {--role= : Agent role (leader or follower) for filtering health checks}';

    protected $description = 'Output Laravel metrics as JSON (used by Observer agent)';

    protected $hidden = true;

    public function handle(Schedule $schedule): int
    {
        $collectors = [
            'queue' => fn () => $this->collectQueueMetrics(),
            'horizon' => fn () => $this->collectHorizonMetrics(),
            'scheduler' => fn () => $this->collectSchedulerMetrics($schedule),
            'custom_health' => fn () => $this->collectCustomHealthMetrics(),
        ];

        $data = [];

        // Collectors that always run when they have data (no config toggle needed)
        $alwaysRun = ['custom_health'];

        foreach ($collectors as $name => $collector) {
            if (in_array($name, $alwaysRun, true) || config("observer.collectors.{$name}")) {
                $result = $collector();
                if (! empty($result)) {
                    $data[$name] = $result;
                }
            }
        }

        $this->output->write(json_encode($data));

        return Command::SUCCESS;
    }

    /**
     * Returns array of per-queue metrics in the format expected by ClickHouseService.
     *
     * @return array<int, array{connection: string, queue: string, size: int, failed: int, wait_time_seconds: float}>
     */
    protected function collectQueueMetrics(): array
    {
        $connection = config('queue.default');
        $queueNames = config('observer.queue_names', ['default']);

        // Get failed jobs since last collection (not cumulative total)
        $failedSinceLastCollection = 0;
        try {
            $cacheKey = 'observer:queue:last_failed_check';
            $lastCheck = Cache::get($cacheKey, now()->toDateTimeString());

            $failedSinceLastCollection = DB::table('failed_jobs')
                ->where('failed_at', '>=', $lastCheck)
                ->count();

            Cache::forever($cacheKey, now()->toDateTimeString());
        } catch (\Throwable) {
            // failed_jobs table may not exist
        }

        $result = [];

        foreach ($queueNames as $queueName) {
            $size = 0;
            $waitTimeSeconds = 0.0;

            try {
                $queue = Queue::connection($connection);
                $size = $queue->size($queueName);

                $oldestTimestamp = $queue->creationTimeOfOldestPendingJob($queueName);
                if ($oldestTimestamp !== null) {
                    $waitTimeSeconds = (float) max(0, now()->timestamp - $oldestTimestamp);
                }
            } catch (\Throwable) {
                // Queue driver may not support these methods
            }

            $result[] = [
                'connection' => $connection,
                'queue' => $queueName,
                'size' => $size,
                'failed' => $failedSinceLastCollection,
                'wait_time_seconds' => $waitTimeSeconds,
            ];
        }

        return $result;
    }

    /**
     * Returns Horizon metrics in the format expected by ClickHouseService.
     *
     * @return array{status: string, processes: int, jobs_per_minute: float, recent_jobs: int, failed_jobs: int, pending_jobs: int, completed_jobs: int, wait_time_seconds: float}|null
     */
    protected function collectHorizonMetrics(): ?array
    {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            return null;
        }

        try {
            $masterSupervisor = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class);
            $workload = app(\Laravel\Horizon\Contracts\WorkloadRepository::class);
            $metrics = app(\Laravel\Horizon\Contracts\MetricsRepository::class);

            $masters = $masterSupervisor->all();

            if (empty($masters)) {
                $status = 'inactive';
            } else {
                $status = collect($masters)->every(fn ($master) => $master->status === 'paused')
                    ? 'paused'
                    : 'running';
            }

            $totalProcesses = 0;

            $workloadData = $workload->get();
            $pendingJobs = 0;
            $maxWaitTime = 0.0;

            foreach ($workloadData as $entry) {
                $pendingJobs += $entry['length'] ?? 0;
                $maxWaitTime = max($maxWaitTime, (float) ($entry['wait'] ?? 0));
                $totalProcesses += $entry['processes'] ?? 0;
            }

            $completedJobs = 0;
            $recentJobs = 0;
            $jobsPerMinute = 0.0;

            try {
                $jobs = app(\Laravel\Horizon\Contracts\JobRepository::class);
                $completedJobs = (int) $jobs->countCompleted();
                $recentJobs = (int) $jobs->countRecent();
                $jobsPerMinute = (float) ($metrics->jobsProcessedPerMinute() ?? 0);
            } catch (\Throwable) {
                // JobRepository or metrics may not be available
            }

            $failedJobs = 0;
            try {
                $cacheKey = 'observer:horizon:last_failed_check';
                $lastCheck = Cache::get($cacheKey, now()->toDateTimeString());

                $failedJobs = DB::table('failed_jobs')
                    ->where('failed_at', '>=', $lastCheck)
                    ->count();

                Cache::forever($cacheKey, now()->toDateTimeString());
            } catch (\Throwable) {
                // Table may not exist
            }

            return [
                'status' => $status,
                'processes' => $totalProcesses,
                'jobs_per_minute' => $jobsPerMinute,
                'recent_jobs' => $recentJobs,
                'failed_jobs' => $failedJobs,
                'pending_jobs' => $pendingJobs,
                'completed_jobs' => $completedJobs,
                'wait_time_seconds' => $maxWaitTime,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns custom health check results from the Observer registry.
     *
     * @return array<int, array{name: string, is_healthy: bool, response_time_ms: int, status_code: int, error_message: ?string}>|null
     */
    protected function collectCustomHealthMetrics(): ?array
    {
        $observer = app(Observer::class);
        $checks = $observer->getRegisteredHealthChecks();

        if (empty($checks)) {
            return null;
        }

        $role = $this->option('role');

        $results = [];

        foreach ($checks as $check) {
            if ($role === 'follower' && $check['leader_only']) {
                continue;
            }

            $startTime = hrtime(true);
            $result = $observer->runHealthCheck($check['name']);
            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            if ($result === null) {
                continue;
            }

            $responseTimeMs = $result->getResponseTimeMs() ?? $elapsedMs;

            $results[] = [
                'name' => $check['name'],
                'is_healthy' => $result->isHealthy(),
                'response_time_ms' => $responseTimeMs,
                'status_code' => 0,
                'error_message' => $result->getErrorMessage(),
            ];
        }

        return ! empty($results) ? $results : null;
    }

    /**
     * Returns scheduler metrics in the format expected by ClickHouseService.
     * Returns array of scheduled tasks with their details.
     *
     * @return array<int, array{command: string, description: ?string, expression: string, next_run_at: string, last_run_at: ?string, last_duration_ms: ?int, last_exit_code: ?int, is_running: bool}>|null
     */
    protected function collectSchedulerMetrics(Schedule $schedule): ?array
    {
        try {
            $events = $schedule->events();

            if (empty($events)) {
                return null;
            }

            $result = [];
            $now = now();
            $cachePrefix = 'observer:scheduler:';

            foreach ($events as $event) {
                $command = $this->cleanCommandName($event->command ?? $event->description ?? 'closure');
                $taskKey = $this->getTaskKey($event);

                $cron = new \Cron\CronExpression($event->expression);
                $nextRun = $cron->getNextRunDate($now)->format('Y-m-d H:i:s');

                $lastRunAt = Cache::get($cachePrefix.$taskKey.':last_run_at');
                $isRunning = Cache::get($cachePrefix.$taskKey.':running', false);

                $result[] = [
                    'command' => $command,
                    'description' => $event->description,
                    'expression' => $event->expression,
                    'next_run_at' => $nextRun,
                    'last_run_at' => $lastRunAt ? date('Y-m-d H:i:s', $lastRunAt) : null,
                    'last_duration_ms' => Cache::get($cachePrefix.$taskKey.':last_duration_ms'),
                    'last_exit_code' => Cache::get($cachePrefix.$taskKey.':last_exit_code'),
                    'is_running' => (bool) $isRunning,
                ];
            }

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }
}
