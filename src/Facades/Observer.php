<?php

namespace PolarityLabs\ObserverAgent\Facades;

use Illuminate\Support\Facades\Facade;
use PolarityLabs\ObserverAgent\Observer as ObserverInstance;

/**
 * @method static void healthCheck(string $name, callable $callback, bool $leaderOnly = true)
 * @method static \PolarityLabs\ObserverAgent\HealthCheckResult|null runHealthCheck(string $name)
 * @method static array getRegisteredHealthChecks()
 * @method static void clearHealthChecks()
 *
 * @see \PolarityLabs\ObserverAgent\Observer
 */
class Observer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ObserverInstance::class;
    }
}
