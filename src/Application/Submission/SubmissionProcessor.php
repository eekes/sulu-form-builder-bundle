<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Submission;

use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCompletedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionCreatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Event\SubmissionValidatedEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRunner;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationSender;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamVerdict;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamVerdicts;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\IntegrationRunAttempt;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\NotificationDelivery;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * What happens once the last step of a form is valid, in this order:
 *
 * 1. the answers are collected into a Submission       (SubmissionValidatedEvent)
 * 2. it is stored, when the form stores submissions    (SubmissionCreatedEvent)
 * 3. the notifications whose conditions match are sent (NotificationSendingEvent, per e-mail)
 * 4. the integration flows whose conditions match run  (IntegrationRunFinishedEvent, per run)
 * 5. the visitor gets an answer                        (SubmissionCompletedEvent)
 *
 * Spam - caught by a spam check of the form, or marked by a listener - skips 2 to 4: it is kept
 * apart as a blocked submission instead, and the visitor gets the same answer as for a real one.
 *
 * Independent of HTTP: give it the data of a flow and a context, and it works the same from a
 * request listener, a Live Component, an API controller or a test.
 */
final readonly class SubmissionProcessor
{
    public function __construct(
        private ValueCollector $valueCollector,
        private FormRepository $formRepository,
        private SubmissionRepository $submissionRepository,
        private NotificationSender $notificationSender,
        private UploadStorageInterface $uploadStorage,
        private EventDispatcherInterface $eventDispatcher,
        private ?IntegrationRunner $integrationRunner = null,
        private ?SpamVerdicts $spamVerdicts = null,
        private LoggerInterface $logger = new NullLogger(),
        private bool $logSpam = true,
        private bool $storeSpamPayload = true,
        private bool $anonymizeIp = false,
    ) {
    }

    /**
     * @param array<mixed> $data the data of the finished flow
     */
    public function process(FormDefinition $definition, array $data, SubmissionContext $context): SubmissionResult
    {
        $form = $this->formRepository->findById($definition->id)
            ?? throw new \RuntimeException(\sprintf('The form "%s" no longer exists.', $definition->key));

        $submission = new Submission($form, $definition->locale, $this->valueCollector->collect($definition, $data));
        $submission->url = $context->url;
        $submission->webspaceKey = $context->webspaceKey;

        $validated = new SubmissionValidatedEvent($definition, $submission, $context);
        $verdict = $this->spamVerdicts?->take($definition->key);

        if ($verdict !== null) {
            $validated->markAsSpam($verdict->reason, $verdict->check, $verdict->score);
        }

        $this->eventDispatcher->dispatch($validated);

        $spam = $validated->getSpamVerdict();

        if ($spam !== null) {
            $this->block($definition, $submission, $spam, $context);
        } else {
            if ($definition->storeSubmissions && !$validated->isStoragePrevented()) {
                $this->submissionRepository->save($submission);
            }

            $this->eventDispatcher->dispatch(new SubmissionCreatedEvent($definition, $submission, $context));
        }

        if (!$validated->areNotificationsPrevented()) {
            $this->notificationSender->send($definition, $submission, $context);
        } else {
            $this->notificationSender->skip($definition, $submission, $spam !== null
                ? 'The submission was blocked as spam.'
                : 'A listener kept this submission from the notifications.');
        }

        if (!$validated->areIntegrationsPrevented()) {
            $this->integrationRunner?->start($definition, $submission);
        } else {
            $this->integrationRunner?->skip($definition, $submission, $spam !== null
                ? 'The submission was blocked as spam.'
                : 'A listener kept this submission from the integrations.');
        }

        // Files of a submission that is not kept were only needed for the e-mails.
        if (!$submission->isPersisted()) {
            foreach ($submission->files() as $file) {
                $this->uploadStorage->delete($file);
            }
        }

        $completed = new SubmissionCompletedEvent($definition, $submission, $context, $spam !== null);
        $this->eventDispatcher->dispatch($completed);

        return new SubmissionResult($submission, $spam !== null, $completed->getResponse());
    }

    /**
     * "Not spam": a blocked submission becomes a real one, and gets what it was denied - the
     * notifications and the integration flows of its form. A form that does not store
     * submissions does not keep it afterwards either.
     */
    public function release(FormDefinition $definition, Submission $submission): void
    {
        if (!$submission->isSpam()) {
            return;
        }

        $submission->release();
        $this->submissionRepository->save($submission);

        $context = new SubmissionContext(locale: $submission->locale, webspaceKey: $submission->webspaceKey, url: $submission->url);
        $this->eventDispatcher->dispatch(new SubmissionCreatedEvent($definition, $submission, $context));
        $this->notificationSender->send($definition, $submission, $context, NotificationDelivery::TRIGGER_RELEASE);
        $this->integrationRunner?->start($definition, $submission, IntegrationRunAttempt::TRIGGER_RELEASE);

        if (!$definition->storeSubmissions) {
            $this->submissionRepository->remove($submission);
        }
    }

    private function block(FormDefinition $definition, Submission $submission, SpamVerdict $verdict, SubmissionContext $context): void
    {
        $this->logger->info('A submission of the form "{form}" was blocked by the spam check "{check}": {reason}', [
            'form' => $definition->key,
            'check' => $verdict->check,
            'reason' => $verdict->reason,
            'score' => $verdict->score,
            'ip' => $context->request?->getClientIp(),
        ]);

        // A bot's attachments are never kept, not even for the log.
        foreach ($submission->files() as $file) {
            $this->uploadStorage->delete($file);
        }

        if (!$this->logSpam) {
            return;
        }

        $submission->dropFiles();
        $submission->markAsSpam($verdict->check, $verdict->reason, $verdict->score);

        if (!$this->storeSpamPayload) {
            $submission->setValues([]);
        }

        $ip = $context->request?->getClientIp();
        $submission->ipAddress = $ip !== null && $this->anonymizeIp ? IpUtils::anonymize($ip) : $ip;
        $submission->userAgent = $context->request?->headers->get('User-Agent');

        $this->submissionRepository->save($submission);
    }
}
