<?php

namespace PolarityLabs\ObserverAgent\Concerns;

use Illuminate\Console\Scheduling\Event;

trait ExtractsSchedulerTaskInfo
{
    /**
     * Generate a unique cache key for a scheduled task.
     */
    protected function getTaskKey(Event $task): string
    {
        $command = $this->cleanCommandName($task->command ?? $task->description ?? 'closure');

        return md5($command.$task->expression);
    }

    /**
     * Extract just the artisan command name from a full command string.
     *
     * Converts: '/path/to/php' 'artisan' alpha:command --option
     * To: alpha:command --option
     */
    protected function cleanCommandName(string $command): string
    {
        // Match pattern: '...' 'artisan' <actual command>
        if (preg_match("/['\"]artisan['\"]\s+(.+)$/", $command, $matches)) {
            return trim($matches[1]);
        }

        // Match pattern: artisan <actual command> (without quotes)
        if (preg_match('/artisan\s+(.+)$/', $command, $matches)) {
            return trim($matches[1]);
        }

        return $command;
    }
}
