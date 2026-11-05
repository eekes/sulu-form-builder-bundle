<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;

/**
 * Everything an integration gets to execute one action for one submission.
 */
final readonly class IntegrationRequest
{
    /**
     * @param array<string, mixed> $values the mapped values, by target field key
     * @param array<string, mixed> $settings what the editor configured
     */
    public function __construct(
        public string $action,
        public array $values,
        public array $settings,
        public Submission $submission,
        public string $flowName,
    ) {
    }

    public function value(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * A value as text: lists are joined, booleans become "1" or "".
     */
    public function string(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        if (\is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function stringSetting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    public function boolSetting(string $key, bool $default = false): bool
    {
        $value = $this->settings[$key] ?? null;

        return $value === null ? $default : (bool) $value;
    }
}
