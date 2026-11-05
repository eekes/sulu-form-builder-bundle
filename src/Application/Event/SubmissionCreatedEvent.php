<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Event;

use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;

/**
 * Dispatched once a submission has been stored - or would have been, for a form that does not
 * store submissions; $submission->isPersisted() tells which. The natural place to push the
 * answers to a CRM, a newsletter tool or a webhook.
 */
final readonly class SubmissionCreatedEvent
{
    public function __construct(
        public FormDefinition $definition,
        public Submission $submission,
        public SubmissionContext $context,
    ) {
    }
}
