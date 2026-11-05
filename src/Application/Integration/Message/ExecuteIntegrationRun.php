<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration\Message;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRunAttempt;

/**
 * Asks a worker to execute a run. Route it to an async transport to keep remote services out of
 * the visitor's request:
 *
 * ```yaml
 * framework:
 *     messenger:
 *         routing:
 *             Eekes\Sulu\FormBuilderBundle\Application\Integration\Message\ExecuteIntegrationRun: async
 * ```
 *
 * Without routing, Messenger handles it right away, in the same request - automatic retries then
 * happen right away too, because only a transport can delay a message.
 */
final readonly class ExecuteIntegrationRun
{
    public function __construct(
        public int $runId,
        /** One of the IntegrationRunAttempt::TRIGGER_* constants: why this attempt happens. */
        public string $trigger = IntegrationRunAttempt::TRIGGER_SUBMISSION,
    ) {
    }
}
