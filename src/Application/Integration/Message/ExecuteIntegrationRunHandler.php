<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration\Message;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRunner;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\IntegrationRunRepository;

final readonly class ExecuteIntegrationRunHandler
{
    public function __construct(
        private IntegrationRunRepository $integrationRunRepository,
        private IntegrationRunner $integrationRunner,
    ) {
    }

    public function __invoke(ExecuteIntegrationRun $message): void
    {
        $run = $this->integrationRunRepository->findById($message->runId);

        // Deleted together with its submission while it was waiting in the queue.
        if ($run !== null) {
            $this->integrationRunner->execute($run, $message->trigger);
        }
    }
}
