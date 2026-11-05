<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * How often, and how long apart, a run that failed in a way that may pass later is tried again
 * automatically: a growing delay (60 s, 120 s, 240 s, ...) up to a ceiling, per integration if a
 * developer wants a CRM with a flaky API treated differently. Editors never set this.
 */
final readonly class RetryPolicy
{
    /**
     * @param int $maxAttempts every attempt counts, the first included: 1 never retries
     * @param int $delay seconds before the first automatic retry
     * @param array<string, array{max_attempts?: int, delay?: int, multiplier?: float, max_delay?: int}> $integrations overrides by integration key
     */
    public function __construct(
        private int $maxAttempts = 3,
        private int $delay = 60,
        private float $multiplier = 2.0,
        private int $maxDelay = 3600,
        private array $integrations = [],
    ) {
    }

    public function maxAttempts(string $integration): int
    {
        return max(1, $this->integrations[$integration]['max_attempts'] ?? $this->maxAttempts);
    }

    /**
     * Seconds to wait after the given attempt failed.
     */
    public function delay(string $integration, int $failedAttempt): int
    {
        $delay = $this->integrations[$integration]['delay'] ?? $this->delay;
        $multiplier = $this->integrations[$integration]['multiplier'] ?? $this->multiplier;
        $maxDelay = $this->integrations[$integration]['max_delay'] ?? $this->maxDelay;

        return (int) min($maxDelay, round($delay * $multiplier ** max(0, $failedAttempt - 1)));
    }

    public function allowsAnotherAttempt(string $integration, int $attempts): bool
    {
        return $attempts < $this->maxAttempts($integration);
    }
}
