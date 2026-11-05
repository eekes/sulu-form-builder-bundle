<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Event;

use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Dispatched for every e-mail just before it is handed to the mailer. Change the e-mail - add a
 * recipient, an attachment, a header - or cancel it.
 */
final class NotificationSendingEvent
{
    private bool $cancelled = false;

    public function __construct(
        public readonly FormDefinition $definition,
        public readonly Notification $notification,
        public readonly TemplatedEmail $email,
        public readonly Submission $submission,
        public readonly SubmissionContext $context,
    ) {
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
