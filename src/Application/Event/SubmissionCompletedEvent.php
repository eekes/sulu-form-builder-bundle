<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Event;

use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Symfony\Component\HttpFoundation\Response;

/**
 * The last event of a submission: everything was stored and sent. Set a response to answer the
 * visitor differently than the form configured, e.g. a redirect to a payment page.
 */
final class SubmissionCompletedEvent
{
    private ?Response $response = null;

    public function __construct(
        public readonly FormDefinition $definition,
        public readonly Submission $submission,
        public readonly SubmissionContext $context,
        public readonly bool $spam = false,
    ) {
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }
}
