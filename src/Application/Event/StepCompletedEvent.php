<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Event;

use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;

/**
 * Dispatched when a visitor finished a step that is not the last one, e.g. to keep a lead even
 * when the rest of the form is never sent.
 */
final readonly class StepCompletedEvent
{
    /**
     * @param array<string, mixed> $values every answer so far, by field key
     */
    public function __construct(
        public FormDefinition $definition,
        public StepDefinition $step,
        public array $values,
        public SubmissionContext $context,
    ) {
    }
}
