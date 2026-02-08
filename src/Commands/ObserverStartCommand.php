<?php

namespace PolarityLabs\ObserverAgent\Commands;

use Illuminate\Console\Command;
use PolarityLabs\ObserverAgent\Observer;
use Symfony\Component\Process\Process;

use function collect;

class ObserverStartCommand extends Command
{
    protected $signature = 'observer:start
                            {--once : Run collectors once and exit instead of running continuously}';

    protected $description = 'Start the Observer monitoring agent';

    public function handle(): int
    {
        if (! $this->validateConfig()) {
            return Command::FAILURE;
        }

        $binaryPath = $this->getBinaryPath();

        if (! $binaryPath) {
            $this->error('No compatible binary found for this system.');
            $this->error('OS: '.PHP_OS_FAMILY.', Architecture: '.php_uname('m'));

            return Command::FAILURE;
        }

        if (! is_executable($binaryPath)) {
            chmod($binaryPath, 0755);
        }

        $this->info('Starting Observer monitoring agent...');
        $this->info('Binary: '.$binaryPath);
        $this->info('Server: '.config('observer.server_name'));
        $this->newLine();

        $process = new Process(
            command: [$binaryPath],
            cwd: base_path(),
            env: array_merge(getenv(), $this->buildEnvironment()),
            timeout: null,
        );

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? Command::SUCCESS;
    }

    protected function validateConfig(): bool
    {
        $valid = true;

        if (empty(config('observer.api_key'))) {
            $this->error('OBSERVER_API_KEY is not configured.');
            $this->error('Add it to your .env file: OBSERVER_API_KEY=your-secret-key');
            $valid = false;
        }

        return $valid;
    }

    protected function getBinaryPath(): ?string
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            default => null,
        };

        $arch = match (php_uname('m')) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };

        if ($os === null || $arch === null) {
            return null;
        }

        $binaryName = "observer-{$os}-{$arch}";
        $binaryDir = dirname(__DIR__, 2).'/bin';
        $binaryPath = "{$binaryDir}/{$binaryName}";

        if (file_exists($binaryPath)) {
            return $binaryPath;
        }

        if (app()->runningUnitTests()) {
            return null;
        }

        return $this->downloadBinary($binaryName, $binaryDir, $binaryPath);
    }

    protected function resolveGoVersion(): ?string
    {
        $manifestUrl = Observer::DIST_BASE_URL.'/manifest.json';

        $context = stream_context_create(['http' => [
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);

        $json = @file_get_contents($manifestUrl, false, $context);

        if ($json === false) {
            return null;
        }

        $manifest = json_decode($json, true);

        if (! is_array($manifest)) {
            return null;
        }

        return $this->matchGoVersion($manifest, Observer::AGENT_VERSION);
    }

    protected function matchGoVersion(array $manifest, string $phpVersion): ?string
    {
        if ($phpVersion !== 'dev' && ! empty($manifest['compatibility'])) {
            $bestMatch = null;

            foreach ($manifest['compatibility'] as $entry) {
                if (
                    version_compare($phpVersion, $entry['php_min'], '>=') &&
                    version_compare($phpVersion, $entry['php_max'], '<=')
                ) {
                    if ($bestMatch === null || version_compare($entry['php_min'], $bestMatch['php_min'], '>')) {
                        $bestMatch = $entry;
                    }
                }
            }

            if ($bestMatch !== null) {
                return $bestMatch['go_version'];
            }
        }

        return $manifest['latest_go_version'] ?? null;
    }

    protected function downloadBinary(string $binaryName, string $binaryDir, string $binaryPath): ?string
    {
        $goVersion = $this->resolveGoVersion();

        if ($goVersion === null) {
            $this->warn('Could not resolve agent binary version from manifest.');

            return null;
        }

        $url = Observer::DIST_BASE_URL."/binaries/{$goVersion}/{$binaryName}";

        $this->info("Downloading agent binary ({$goVersion})...");

        $context = stream_context_create(['http' => [
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);

        $source = @fopen($url, 'r', false, $context);

        if ($source === false) {
            $this->warn("Failed to download binary from {$url}");

            return null;
        }

        if (! is_dir($binaryDir)) {
            mkdir($binaryDir, 0755, true);
        }

        $tmpPath = "{$binaryPath}.tmp.".getmypid();
        $destination = fopen($tmpPath, 'w');

        if (stream_copy_to_stream($source, $destination) === 0) {
            fclose($source);
            fclose($destination);
            @unlink($tmpPath);

            return null;
        }

        fclose($source);
        fclose($destination);

        chmod($tmpPath, 0755);

        if (! rename($tmpPath, $binaryPath)) {
            @unlink($tmpPath);

            return null;
        }

        $this->info('Binary downloaded successfully.');

        return $binaryPath;
    }

    /**
     * Build the merged health endpoints array from config and custom checks.
     *
     * @return array<int, array{type: string, name: string, url?: string, headers?: array, check_from?: string, leader_only: bool}>
     */
    protected function buildHealthEndpoints(): array
    {
        $urlEndpoints = collect(config('observer.health_endpoints', []))->map(fn (array $ep) => [
            'type' => 'url',
            'name' => $ep['name'] ?? '',
            'url' => $ep['url'] ?? '',
            'headers' => (object) ($ep['headers'] ?? []),
            'check_from' => $ep['check_from'] ?? 'agent',
            'leader_only' => $ep['leader_only'] ?? true,
        ])->toArray();

        $customEndpoints = collect(app(Observer::class)->getRegisteredHealthChecks())
            ->map(fn (array $check) => [
                'type' => 'custom',
                'name' => $check['name'],
                'leader_only' => $check['leader_only'],
            ])->toArray();

        return array_merge($urlEndpoints, $customEndpoints);
    }

    /**
     * @return array<string, string>
     */
    protected function buildEnvironment(): array
    {
        return array_filter([
            'OBSERVER_API_ENDPOINT' => config('observer.api_endpoint'),
            'OBSERVER_API_KEY' => config('observer.api_key'),

            'OBSERVER_SERVER_NAME' => config('observer.server_name'),
            'OBSERVER_SERVER_FINGERPRINT' => config('observer.server_fingerprint'),
            'OBSERVER_ENVIRONMENT' => config('app.env'),

            'OBSERVER_APP_PATH' => base_path(),
            'OBSERVER_LOG_PATH' => config('observer.log_path'),
            'OBSERVER_HEALTH_ENDPOINTS' => json_encode($this->buildHealthEndpoints()),

            'OBSERVER_INTERVAL_CPU' => (string) config('observer.intervals.cpu'),
            'OBSERVER_INTERVAL_MEMORY' => (string) config('observer.intervals.memory'),
            'OBSERVER_INTERVAL_NETWORK' => (string) config('observer.intervals.network'),
            'OBSERVER_INTERVAL_DISK' => (string) config('observer.intervals.disk'),
            'OBSERVER_INTERVAL_PROCESS' => (string) config('observer.intervals.process'),
            'OBSERVER_INTERVAL_TOP_PROCESSES' => (string) config('observer.intervals.top_processes'),
            'OBSERVER_INTERVAL_QUEUE' => (string) config('observer.intervals.queue'),
            'OBSERVER_INTERVAL_HORIZON' => (string) config('observer.intervals.horizon'),
            'OBSERVER_INTERVAL_SCHEDULER' => (string) config('observer.intervals.scheduler'),
            'OBSERVER_INTERVAL_LOGS' => (string) config('observer.intervals.logs'),
            'OBSERVER_INTERVAL_HEALTH' => (string) config('observer.intervals.health'),

            'OBSERVER_COLLECT_CPU' => config('observer.collectors.cpu') ? '1' : '0',
            'OBSERVER_COLLECT_MEMORY' => config('observer.collectors.memory') ? '1' : '0',
            'OBSERVER_COLLECT_DISK' => config('observer.collectors.disk') ? '1' : '0',
            'OBSERVER_COLLECT_NETWORK' => config('observer.collectors.network') ? '1' : '0',
            'OBSERVER_COLLECT_PROCESS' => config('observer.collectors.process') ? '1' : '0',
            'OBSERVER_COLLECT_TOP_PROCESSES' => config('observer.collectors.top_processes') ? '1' : '0',
            'OBSERVER_COLLECT_SPIKE_EVENTS' => config('observer.collectors.spike_events') ? '1' : '0',
            'OBSERVER_COLLECT_QUEUE' => config('observer.collectors.queue') ? '1' : '0',
            'OBSERVER_COLLECT_LOGS' => config('observer.collectors.logs') ? '1' : '0',
            'OBSERVER_COLLECT_HEALTH' => config('observer.collectors.health') ? '1' : '0',
            'OBSERVER_COLLECT_HORIZON' => config('observer.collectors.horizon') ? '1' : '0',
            'OBSERVER_COLLECT_SCHEDULER' => config('observer.collectors.scheduler') ? '1' : '0',

            'OBSERVER_TOP_PROCESSES_SAMPLE_INTERVAL' => (string) config('observer.top_processes_sample_interval'),
            'OBSERVER_TOP_PROCESSES_COUNT' => (string) config('observer.top_processes_count'),

            'OBSERVER_SPIKE_CPU_THRESHOLD' => (string) config('observer.spike_cpu_threshold'),
            'OBSERVER_SPIKE_MEMORY_THRESHOLD' => (string) config('observer.spike_memory_threshold'),
            'OBSERVER_SPIKE_COOLDOWN' => (string) config('observer.spike_cooldown'),
            'OBSERVER_SPIKE_TOP_N' => (string) config('observer.spike_top_n'),

            'OBSERVER_DISK_PATHS' => implode(',', config('observer.disk_paths', [])),
            'OBSERVER_DISK_AUTO_DISCOVER' => config('observer.disk_auto_discover', true) ? '1' : '0',
            'OBSERVER_DISK_EXCLUDE_TYPES' => implode(',', config('observer.disk_exclude_types', [])),

            'OBSERVER_QUEUE_CONNECTION' => config('queue.default'),
            'OBSERVER_QUEUE_NAMES' => implode(',', config('observer.queue_names', ['default'])),

            'OBSERVER_PHP_BINARY' => PHP_BINARY,

            'OBSERVER_SYSTEM_METRICS' => config('observer.system_metrics', 'auto'),

            'OBSERVER_RUN_ONCE' => $this->option('once') ? '1' : '0',
        ], fn ($value) => $value !== null && $value !== '');
    }
}
