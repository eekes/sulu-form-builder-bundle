<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * The outcome of a run, as it is shown in the admin: the request and response are logged as
 * they are, so keep secrets (API keys, tokens) out of them.
 *
 * A failure is retryable when sending the same again later may succeed: a timeout, a 5xx, a 429.
 * A refusal of what was sent (a 4xx, a validation error) is not; retrying it only repeats it.
 */
final readonly class IntegrationResult
{
    private function __construct(
        public bool $succeeded,
        public ?string $message,
        public mixed $request,
        public mixed $response,
        public bool $retryable = false,
    ) {
    }

    public static function success(?string $message = null, mixed $request = null, mixed $response = null): self
    {
        return new self(true, $message, $request, $response);
    }

    public static function failure(string $message, mixed $request = null, mixed $response = null, bool $retryable = false): self
    {
        return new self(false, $message, $request, $response, $retryable);
    }
}
