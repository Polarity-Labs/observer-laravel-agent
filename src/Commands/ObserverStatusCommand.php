<?php

namespace PolarityLabs\ObserverAgent\Commands;

use Illuminate\Console\Command;
use PolarityLabs\ObserverAgent\Observer;

class ObserverStatusCommand extends Command
{
    protected $signature = 'observer:status';

    protected $description = 'Display Observer configuration and status';

    public function handle(): int
    {
        $this->info('Observer Configuration');
        $this->newLine();

        $this->checkBinary();
        $this->newLine();

        $this->displaySection('API Configuration', [
            'Endpoint' => config('observer.api_endpoint') ?: '<error>NOT SET</error>',
            'API Key' => config('observer.api_key') ? '******** (set)' : '<error>NOT SET</error>',
        ]);

        $this->displaySection('Server', [
            'Name' => config('observer.server_name'),
            'Environment' => config('app.env'),
        ]);

        $this->displaySection('Collection Intervals', [
            'System metrics' => config('observer.intervals.system') . 's',
            'Laravel metrics' => config('observer.intervals.laravel') . 's',
            'Health checks' => config('observer.intervals.health') . 's',
        ]);

        $collectorStatus = collect(config('observer.collectors'))
            ->mapWithKeys(fn ($enabled, $name) => [
                ucfirst($name) => $enabled ? '<info>enabled</info>' : '<comment>disabled</comment>',
            ])
            ->all();
        $this->displaySection('Collectors', $collectorStatus);

        $logPath = config('observer.log_path');
        $logLabel = $logPath && is_dir($logPath) ? 'Log directory' : 'Log file';
        $this->displaySection('Paths', [
            'App root' => base_path(),
            $logLabel => $logPath,
            'Health URL' => config('observer.health_url'),
        ]);

        $this->displaySection('Queue', [
            'Connection' => config('queue.default'),
            'Queue names' => implode(', ', config('observer.queue_names', ['default'])),
            'Metrics endpoint' => config('observer.metrics_endpoint') ?: '<comment>not set</comment>',
            'Horizon installed' => class_exists(\Laravel\Horizon\Horizon::class) ? '<info>yes</info>' : '<comment>no</comment>',
        ]);

        $this->newLine();

        if ($this->validateConfig()) {
            $this->info('Configuration is valid. Run `php artisan observer:start` to begin monitoring.');
        }

        return Command::SUCCESS;
    }

    protected function checkBinary(): void
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            default => 'unknown',
        };

        $arch = match (php_uname('m')) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => 'unknown',
        };

        $binaryName = "observer-{$os}-{$arch}";
        $binaryPath = dirname(__DIR__, 2) . '/bin/' . $binaryName;

        $this->line("Agent version: <info>".Observer::AGENT_VERSION."</info>");
        $this->line("System: <info>{$os}-{$arch}</info>");
        
        if (file_exists($binaryPath)) {
            $this->line("Binary: <info>✓ Found</info> ({$binaryPath})");
        } else {
            $this->line("Binary: <error>✗ Not found</error> ({$binaryPath})");
        }
    }

    /**
     * @param array<string, string> $items
     */
    protected function displaySection(string $title, array $items): void
    {
        $this->line("<comment>{$title}</comment>");
        
        foreach ($items as $key => $value) {
            $this->line("  {$key}: {$value}");
        }
        
        $this->newLine();
    }

    protected function validateConfig(): bool
    {
        $errors = [];

        if (empty(config('observer.api_endpoint'))) {
            $errors[] = 'OBSERVER_API_ENDPOINT is not set in .env';
        }

        if (empty(config('observer.api_key'))) {
            $errors[] = 'OBSERVER_API_KEY is not set in .env';
        }

        $logPath = config('observer.log_path');
        if ($logPath && ! file_exists($logPath)) {
            $errors[] = "Log path does not exist: {$logPath}";
        }

        if (! empty($errors)) {
            $this->error('Configuration errors:');
            foreach ($errors as $error) {
                $this->line("  <error>✗</error> {$error}");
            }
            return false;
        }

        return true;
    }
}
