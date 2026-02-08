<?php

namespace PolarityLabs\ObserverAgent;

final class Observer
{
    const AGENT_VERSION = '0.0.7';

    const DIST_BASE_URL = 'https://dist.observer.dev';

    /**
     * @var array<string, array{callback: callable, leader_only: bool}>
     */
    private array $healthChecks = [];

    /**
     * Register a custom health check.
     */
    public function healthCheck(string $name, callable $callback, bool $leaderOnly = true): void
    {
        $this->healthChecks[$name] = [
            'callback' => $callback,
            'leader_only' => $leaderOnly,
        ];
    }

    /**
     * Run a registered health check by name, wrapping exceptions as unhealthy results.
     */
    public function runHealthCheck(string $name): ?HealthCheckResult
    {
        if (! isset($this->healthChecks[$name])) {
            return null;
        }

        try {
            $result = ($this->healthChecks[$name]['callback'])();

            if (! $result instanceof HealthCheckResult) {
                return HealthCheckResult::unhealthy()->errorMessage('Callback must return a HealthCheckResult');
            }

            return $result;
        } catch (\Throwable $e) {
            return HealthCheckResult::unhealthy()->errorMessage($e->getMessage());
        }
    }

    /**
     * Get all registered health check metadata.
     *
     * @return array<int, array{name: string, leader_only: bool}>
     */
    public function getRegisteredHealthChecks(): array
    {
        $checks = [];

        foreach ($this->healthChecks as $name => $check) {
            $checks[] = [
                'name' => $name,
                'leader_only' => $check['leader_only'],
            ];
        }

        return $checks;
    }

    /**
     * Clear all registered health checks (for testing).
     */
    public function clearHealthChecks(): void
    {
        $this->healthChecks = [];
    }
}
