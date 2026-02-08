<?php

namespace PolarityLabs\ObserverAgent;

final class HealthCheckResult
{
    private function __construct(
        private bool $isHealthy,
        private ?int $responseTimeMs = null,
        private ?string $errorMessage = null,
    ) {}

    public static function healthy(): self
    {
        return new self(isHealthy: true);
    }

    public static function unhealthy(): self
    {
        return new self(isHealthy: false);
    }

    public static function make(bool $isHealthy, ?int $responseTimeMs = null, ?string $errorMessage = null): self
    {
        return new self(
            isHealthy: $isHealthy,
            responseTimeMs: $responseTimeMs,
            errorMessage: $errorMessage,
        );
    }

    public function responseTime(int $ms): self
    {
        $this->responseTimeMs = $ms;

        return $this;
    }

    public function errorMessage(string $message): self
    {
        $this->errorMessage = $message;

        return $this;
    }

    public function isHealthy(): bool
    {
        return $this->isHealthy;
    }

    public function getResponseTimeMs(): ?int
    {
        return $this->responseTimeMs;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array{is_healthy: bool, response_time_ms: ?int, error_message: ?string}
     */
    public function toArray(): array
    {
        return [
            'is_healthy' => $this->isHealthy,
            'response_time_ms' => $this->responseTimeMs,
            'error_message' => $this->errorMessage,
        ];
    }
}
