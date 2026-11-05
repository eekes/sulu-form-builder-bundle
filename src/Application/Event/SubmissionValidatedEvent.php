<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Event;

use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamVerdict;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;

/**
 * Dispatched after the last step passed validation, before anything is stored or sent. The place
 * for a spam check, or for changing the answers.
 *
 * A submission marked as spam is answered exactly like a real one - the visitor sees the success
 * message or the redirect - so a bot never learns it was caught. It is not sent, no integration
 * sees it, and it is kept apart in the "Spam" tab of the form (unless spam_protection.log is off),
 * where an editor can release it. A spam check of the form that caught it has already marked it
 * when this event is dispatched.
 */
final class SubmissionValidatedEvent
{
    private ?SpamVerdict $spam = null;
    private bool $storagePrevented = false;
    private bool $notificationsPrevented = false;
    private bool $integrationsPrevented = false;

    public function __construct(
        public readonly FormDefinition $definition,
        public readonly Submission $submission,
        public readonly SubmissionContext $context,
    ) {
    }

    /**
     * @param string $reason shown to editors in the "Spam" tab
     * @param string $check who caught it, shown next to the reason
     */
    public function markAsSpam(string $reason = 'Marked as spam by a listener.', string $check = 'listener', ?float $score = null): void
    {
        $this->spam = new SpamVerdict($check, $reason, $score);
    }

    public function isSpam(): bool
    {
        return $this->spam !== null;
    }

    /**
     * Which check caught it and why; null when it is not spam.
     */
    public function getSpamVerdict(): ?SpamVerdict
    {
        return $this->spam;
    }

    /**
     * Keeps this submission out of the database, even when the form stores submissions.
     */
    public function preventStorage(): void
    {
        $this->storagePrevented = true;
    }

    public function isStoragePrevented(): bool
    {
        return $this->spam !== null || $this->storagePrevented;
    }

    public function preventNotifications(): void
    {
        $this->notificationsPrevented = true;
    }

    public function areNotificationsPrevented(): bool
    {
        return $this->spam !== null || $this->notificationsPrevented;
    }

    /**
     * Keeps this submission away from every integration flow of the form.
     */
    public function preventIntegrations(): void
    {
        $this->integrationsPrevented = true;
    }

    public function areIntegrationsPrevented(): bool
    {
        return $this->spam !== null || $this->integrationsPrevented;
    }
}
