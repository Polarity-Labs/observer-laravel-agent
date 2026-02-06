<?php

namespace PolarityLabs\ObserverAgent\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use PolarityLabs\ObserverAgent\Concerns\ExtractsSchedulerTaskInfo;

class SchedulerEventSubscriber
{
    use ExtractsSchedulerTaskInfo;

    private const CACHE_PREFIX = 'observer:scheduler:';

    private const CACHE_TTL = 300;

    public function handleTaskStarting(ScheduledTaskStarting $event): void
    {
        $key = $this->getTaskKey($event->task);
        Cache::put(self::CACHE_PREFIX.$key.':running', true, self::CACHE_TTL);
        Cache::put(self::CACHE_PREFIX.$key.':started_at', now()->timestamp, self::CACHE_TTL);
    }

    public function handleTaskFinished(ScheduledTaskFinished $event): void
    {
        $key = $this->getTaskKey($event->task);
        $startedAt = Cache::get(self::CACHE_PREFIX.$key.':started_at');
        $durationMs = $startedAt ? (now()->timestamp - $startedAt) * 1000 : null;

        Cache::forget(self::CACHE_PREFIX.$key.':running');
        Cache::put(self::CACHE_PREFIX.$key.':last_run_at', now()->timestamp, self::CACHE_TTL);
        Cache::put(self::CACHE_PREFIX.$key.':last_duration_ms', $durationMs, self::CACHE_TTL);
        Cache::put(self::CACHE_PREFIX.$key.':last_exit_code', $event->task->exitCode, self::CACHE_TTL);
    }

    public function handleTaskFailed(ScheduledTaskFailed $event): void
    {
        $key = $this->getTaskKey($event->task);
        Cache::forget(self::CACHE_PREFIX.$key.':running');
        Cache::put(self::CACHE_PREFIX.$key.':last_run_at', now()->timestamp, self::CACHE_TTL);
        Cache::put(self::CACHE_PREFIX.$key.':last_exit_code', 1, self::CACHE_TTL);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ScheduledTaskStarting::class => 'handleTaskStarting',
            ScheduledTaskFinished::class => 'handleTaskFinished',
            ScheduledTaskFailed::class => 'handleTaskFailed',
        ];
    }
}
