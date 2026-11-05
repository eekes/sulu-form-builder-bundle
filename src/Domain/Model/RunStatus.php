<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

enum RunStatus: string
{
    /**
     * Waiting for a worker, when runs go through Messenger.
     */
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Failed in a way that may pass later (a timeout, a 5xx); the next attempt is scheduled.
     */
    case Retrying = 'retrying';

    /**
     * Never executed, with the reason as its message: the flow is disabled, its conditions did not
     * match, its integration is not enabled, or the submission was spam or kept from integrations.
     */
    case Skipped = 'skipped';
}
