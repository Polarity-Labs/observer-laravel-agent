<?php

namespace PolarityLabs\ObserverAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PolarityLabs\ObserverAgent\Concerns\ExtractsSchedulerTaskInfo;

class ObserverMetricsCommand extends Command
{
    use ExtractsSchedulerTaskInfo;

    protected $signature = 'observer:metrics';

    protected $description = 'Output Laravel metrics as JSON (used by Observer agent)';

    protected $hidden = true;

    public function handle(Schedule $schedule): int
    {
        $collectors = [
            'queue' => fn () => $this->collectQueueMetrics(),
            'horizon' => fn () => $this->collectHorizonMetrics(),
            'scheduler' => fn () => $this->collectSchedulerMetrics($schedule),
        ];

        $data = [];

        foreach ($collectors as $name => $collector) {
            if (config("observer.collectors.{$name}")) {
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
     * @return array<int, array{connection: string, queue: string, size: int, failed: int, jobs_per_minute: float, wait_time_seconds: float}>
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

            if ($connection === 'database') {
                $row = DB::table('jobs')
                    ->where('queue', $queueName)
                    ->selectRaw('count(*) as size, min(available_at) as oldest_available_at')
                    ->first();

                $size = (int) ($row->size ?? 0);

                if ($row->oldest_available_at ?? null) {
                    $waitTimeSeconds = (float) max(0, now()->timestamp - (int) $row->oldest_available_at);
                }
            } else {
                try {
                    $size = Queue::size($queueName);
                } catch (\Throwable) {
                    // Queue driver may not support size()
                }
            }

            $result[] = [
                'connection' => $connection,
                'queue' => $queueName,
                'size' => $size,
                'failed' => $failedSinceLastCollection,
                'jobs_per_minute' => 0.0, // Not tracked
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
            $status = empty($masters) ? 'inactive' : 'running';

            $totalProcesses = collect($masters)->filter(fn ($master) => $master->pid)->count();

            $workloadData = $workload->get();
            $pendingJobs = 0;
            $maxWaitTime = 0.0;

            foreach ($workloadData as $entry) {
                $pendingJobs += $entry['length'] ?? 0;
                $maxWaitTime = max($maxWaitTime, (float) ($entry['wait'] ?? 0));
            }

            $completedJobs = 0;
            $recentJobs = 0;
            $jobsPerMinute = 0.0;

            try {
                $throughput = $metrics->throughputForQueue('*');
                if (is_numeric($throughput)) {
                    $completedJobs = (int) $throughput;
                }
                // Jobs per minute from recent throughput
                $jobsPerMinute = (float) ($metrics->jobsProcessedPerMinute() ?? 0);
                $recentJobs = (int) ($metrics->recentlyFailed() ?? 0) + $completedJobs;
            } catch (\Throwable) {
                // Metrics may not be available
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
