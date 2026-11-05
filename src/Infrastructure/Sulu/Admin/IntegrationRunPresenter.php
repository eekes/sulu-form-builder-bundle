<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRun;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRunAttempt;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\RunStatus;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A run as the submission modal shows it.
 */
final readonly class IntegrationRunPresenter
{
    public function __construct(
        private IntegrationRegistry $integrationRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(IntegrationRun $run): array
    {
        $title = $run->integration;

        foreach ($this->integrationRegistry->enabled() as $metadata) {
            if ($metadata->key === $run->integration) {
                $title = $this->translator->trans($metadata->title, [], 'admin');
            }
        }

        $action = $this->integrationRegistry->action($run->integration, $run->action);

        return [
            'id' => $run->id,
            'flowName' => $run->flowName,
            'integration' => $title,
            'action' => $action !== null ? $this->translator->trans($action->title, [], 'admin') : $run->action,
            'status' => $run->status()->value,
            'attempts' => $run->attempts,
            'message' => $run->message,
            'values' => $run->values,
            'request' => $run->request,
            'response' => $run->response,
            'createdAt' => $run->createdAt->format(\DATE_ATOM),
            'finishedAt' => $run->finishedAt?->format(\DATE_ATOM),
            'nextAttemptAt' => $run->nextAttemptAt?->format(\DATE_ATOM),
            // "Try again" in the admin: for a run that ran, as long as its integration is there.
            'retryable' => $run->status() !== RunStatus::Skipped && $this->integrationRegistry->isEnabled($run->integration),
            'attemptLog' => array_map(static fn (IntegrationRunAttempt $attempt): array => [
                'number' => $attempt->number,
                'trigger' => $attempt->trigger,
                'triggeredBy' => $attempt->triggeredBy,
                'startedAt' => $attempt->startedAt->format(\DATE_ATOM),
                'durationMs' => $attempt->durationMs,
                'succeeded' => $attempt->succeeded,
                'retryable' => $attempt->retryable,
                'message' => $attempt->message,
                'request' => $attempt->request,
                'response' => $attempt->response,
            ], $run->attemptLog()),
        ];
    }
}
