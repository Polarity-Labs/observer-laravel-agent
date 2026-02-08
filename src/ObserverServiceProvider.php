<?php

namespace PolarityLabs\ObserverAgent;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use PolarityLabs\ObserverAgent\Commands\ObserverMetricsCommand;
use PolarityLabs\ObserverAgent\Commands\ObserverRestartCommand;
use PolarityLabs\ObserverAgent\Commands\ObserverStartCommand;
use PolarityLabs\ObserverAgent\Commands\ObserverStatusCommand;
use PolarityLabs\ObserverAgent\Listeners\SchedulerEventSubscriber;

class ObserverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/observer.php',
            'observer'
        );

        $this->app->singleton(Observer::class);
    }

    public function boot(): void
    {
        if (config('observer.collectors.scheduler')) {
            Event::subscribe(SchedulerEventSubscriber::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/observer.php' => config_path('observer.php'),
            ], 'observer-config');

            $this->commands([
                ObserverMetricsCommand::class,
                ObserverRestartCommand::class,
                ObserverStartCommand::class,
                ObserverStatusCommand::class,
            ]);
        }
    }
}
