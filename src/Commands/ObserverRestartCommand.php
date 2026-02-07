<?php

namespace PolarityLabs\ObserverAgent\Commands;

use Illuminate\Console\Command;

class ObserverRestartCommand extends Command
{
    protected $signature = 'observer:restart';

    protected $description = 'Signal the Observer agent to restart (for use in deploy scripts)';

    public function handle(): int
    {
        $signalPath = storage_path('framework/observer-restart');

        file_put_contents($signalPath, (string) time());
        touch($signalPath);

        $this->info('Restart signal sent. The agent will restart within a few seconds.');

        return Command::SUCCESS;
    }
}
