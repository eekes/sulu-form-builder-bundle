<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * A remote service refused or failed. Carries what was sent and received, so the run log shows
 * it next to the message, and whether the same request may pass later (see IntegrationResult).
 */
class IntegrationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly mixed $request = null,
        public readonly mixed $response = null,
        ?\Throwable $previous = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
