<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Event;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRun;

/**
 * Dispatched after every attempt of a run, succeeded or failed - e.g. to alert someone when a
 * lead did not reach the CRM.
 */
final readonly class IntegrationRunFinishedEvent
{
    public function __construct(
        public IntegrationRun $run,
    ) {
    }
}
