<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Notification;

use Eekes\Sulu\FormBuilderBundle\Application\Condition\ConditionEvaluator;
use Eekes\Sulu\FormBuilderBundle\Application\Event\NotificationSendingEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Submission\SubmissionContext;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\NotificationDelivery;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationDeliveryRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends the e-mails of a form for one submission, and keeps a log of them.
 *
 * A failing mail server must never cost the visitor their submission: it is already stored by
 * the time this runs, so a transport error is logged and the next notification is tried. For a
 * stored submission every notification gets a NotificationDelivery: the e-mail as it was sent,
 * or why it was not sent - so the admin can show it and send it again.
 */
final readonly class NotificationSender
{
    /**
     * @param array{name?: null|string, logo_url?: null|string, accent_color?: null|string, footer?: null|string} $branding
     */
    public function __construct(
        private NotificationRepository $notificationRepository,
        private ConditionEvaluator $conditionEvaluator,
        private PlaceholderRenderer $placeholderRenderer,
        private UploadStorageInterface $uploadStorage,
        private MailerInterface $mailer,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private NotificationTemplates $templates = new NotificationTemplates(),
        private ?string $defaultSenderEmail = null,
        private ?string $defaultSenderName = null,
        private array $branding = [],
        private string $adminPath = '/admin',
        private ?BodyRendererInterface $bodyRenderer = null,
        private ?NotificationDeliveryRepository $deliveryRepository = null,
    ) {
    }

    /**
     * @param string $trigger NotificationDelivery::TRIGGER_SUBMISSION, or TRIGGER_RELEASE for a submission released from spam
     *
     * @return int how many e-mails were handed to the mailer
     */
    public function send(FormDefinition $definition, Submission $submission, SubmissionContext $context, string $trigger = NotificationDelivery::TRIGGER_SUBMISSION): int
    {
        $values = $submission->toArray();
        $sent = 0;

        foreach ($this->notificationRepository->findByForm($definition->id) as $notification) {
            if (!$notification->enabled) {
                $this->skipped($submission, $notification, 'The notification is switched off.', $trigger);

                continue;
            }

            if (!$this->conditionEvaluator->isSetMet($notification->conditions(), $values)) {
                $this->skipped($submission, $notification, 'The conditions of the notification did not match this submission.', $trigger);

                continue;
            }

            [$email, $reason] = $this->build($definition, $notification, $submission);

            if ($email === null) {
                $this->skipped($submission, $notification, $reason, $trigger);

                continue;
            }

            $event = new NotificationSendingEvent($definition, $notification, $email, $submission, $context);
            $this->eventDispatcher->dispatch($event);

            if ($event->isCancelled()) {
                $this->skipped($submission, $notification, 'A listener cancelled the e-mail.', $trigger);

                continue;
            }

            if ($this->deliver($email, $submission, $notification, $notification->name, $trigger, null)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * Logs every notification of the form as not sent, for a submission that was blocked as spam
     * or that a listener kept from notifications.
     */
    public function skip(FormDefinition $definition, Submission $submission, string $reason): void
    {
        foreach ($this->notificationRepository->findByForm($definition->id) as $notification) {
            $this->skipped($submission, $notification, $reason, NotificationDelivery::TRIGGER_SUBMISSION);
        }
    }

    /**
     * Sends an e-mail of the log again, exactly as it was: same recipients, subject and text.
     */
    public function resend(NotificationDelivery $original, ?string $triggeredBy = null): NotificationDelivery
    {
        if ($original->htmlBody === null || ($original->recipients['to'] ?? []) === []) {
            throw new \InvalidArgumentException('Only an e-mail that was sent, or failed, can be sent again.');
        }

        $email = (new Email())
            ->from(Address::create($original->fromAddress ?? ''))
            ->to(...array_map(Address::create(...), $original->recipients['to']))
            ->subject($original->subject ?? '')
            ->html($original->htmlBody);

        if ($original->textBody !== null) {
            $email->text($original->textBody);
        }

        foreach (['cc', 'bcc', 'replyTo'] as $kind) {
            $addresses = array_map(Address::create(...), $original->recipients[$kind] ?? []);

            if ($addresses !== []) {
                match ($kind) {
                    'cc' => $email->cc(...$addresses),
                    'bcc' => $email->bcc(...$addresses),
                    default => $email->replyTo(...$addresses),
                };
            }
        }

        foreach ($original->submission->files() as $file) {
            if (\in_array($file->originalName, $original->attachments, true)) {
                $email->attachFromPath($this->uploadStorage->absolutePath($file), $file->originalName, $file->mimeType);
            }
        }

        $delivery = $this->newDelivery($original->submission, null, $original->notificationName, NotificationDelivery::TRIGGER_RESEND, $triggeredBy)
            ?? throw new \LogicException('E-mails can only be sent again when the e-mail log is available.');
        $delivery->notificationId = $original->notificationId;
        $delivery->template = $original->template;
        $this->snapshot($delivery, $email);

        $this->dispatch($email, $delivery);

        return $delivery;
    }

    /**
     * A test of a notification, to one address and marked as a test, not logged: what the
     * notification modal sends with sample answers.
     */
    public function sendTest(FormDefinition $definition, Notification $notification, Submission $submission, string $recipient): void
    {
        [$email, $reason] = $this->build($definition, $notification, $submission, $recipient);

        if ($email === null) {
            throw new \InvalidArgumentException($reason);
        }

        $email->subject('[Test] '.$email->getSubject());
        $this->mailer->send($email);
    }

    /**
     * The e-mail of a notification for a submission, or null when it cannot be sent (no valid
     * recipient, no sender).
     */
    public function createEmail(FormDefinition $definition, Notification $notification, Submission $submission): ?TemplatedEmail
    {
        return $this->build($definition, $notification, $submission)[0];
    }

    /**
     * The HTML of an e-mail as it will be sent, e.g. for a preview.
     */
    public function renderHtml(TemplatedEmail $email): string
    {
        $this->bodyRenderer?->render($email);
        $html = $email->getHtmlBody();

        return \is_string($html) ? $html : '';
    }

    /**
     * @param null|string $onlyTo send to this address instead of the configured recipients (a test)
     *
     * @return array{0: null|TemplatedEmail, 1: string}
     */
    private function build(FormDefinition $definition, Notification $notification, Submission $submission, ?string $onlyTo = null): array
    {
        $to = $onlyTo !== null ? [new Address($onlyTo)] : $this->addresses($notification->to, $submission);

        if ($to === []) {
            $this->logger->warning('The notification "{notification}" of the form "{form}" has no valid recipient; it is not sent.', [
                'notification' => $notification->name,
                'form' => $definition->key,
            ]);

            return [null, \sprintf('No valid recipient: "%s" did not give a valid e-mail address for this submission.', $notification->to)];
        }

        $fromEmail = $notification->fromEmail ?: $this->defaultSenderEmail;

        if ($fromEmail === null || $fromEmail === '') {
            $this->logger->error('The notification "{notification}" of the form "{form}" has no sender; set one on the notification or configure eekes_sulu_form_builder.sender.', [
                'notification' => $notification->name,
                'form' => $definition->key,
            ]);

            return [null, 'No sender: set one on the notification, or configure eekes_sulu_form_builder.sender.email.'];
        }

        $locale = $definition->locale;
        $subject = $notification->subject($locale) ?? $definition->title;
        $body = $this->placeholderRenderer->render($notification->body($locale) ?? '', $submission, true);
        $template = $this->templates->get($notification->template);

        $email = (new TemplatedEmail())
            ->from(new Address($fromEmail, $notification->fromName ?: ($this->defaultSenderName ?? '')))
            ->to(...$to)
            ->subject(trim(str_replace(["\r", "\n"], ' ', $this->placeholderRenderer->render($subject, $submission, false))))
            ->htmlTemplate($template->template)
            ->locale($locale)
            ->context([
                'body' => $body,
                'submission' => $submission,
                'values' => array_values(array_filter($submission->values(), static fn ($value): bool => !$value->isEmpty())),
                'include_values' => $notification->includeValues,
                'definition' => $definition,
                'notification' => $notification,
                'branding' => $this->branding,
                'preheader' => mb_substr(trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body)))), 0, 140),
                'admin_url' => $this->adminUrl($definition, $submission),
                'template_key' => $template->key,
            ]);

        if ($onlyTo === null) {
            if ($notification->cc !== null) {
                $email->cc(...$this->addresses($notification->cc, $submission));
            }

            if ($notification->bcc !== null) {
                $email->bcc(...$this->addresses($notification->bcc, $submission));
            }
        }

        if ($notification->replyTo !== null) {
            $email->replyTo(...$this->addresses($notification->replyTo, $submission));
        }

        if ($notification->attachUploads) {
            foreach ($submission->files() as $file) {
                $email->attachFromPath($this->uploadStorage->absolutePath($file), $file->originalName, $file->mimeType);
            }
        }

        return [$email, ''];
    }

    /**
     * Hands an e-mail to the mailer, logged when the submission is stored.
     */
    private function deliver(TemplatedEmail $email, Submission $submission, Notification $notification, string $name, string $trigger, ?string $triggeredBy): bool
    {
        $delivery = $this->newDelivery($submission, $notification, $name, $trigger, $triggeredBy);

        if ($delivery !== null) {
            $delivery->template = $this->templates->get($notification->template)->key;
            // Rendered once, here, so the log holds exactly what is sent.
            $this->bodyRenderer?->render($email);
            $this->snapshot($delivery, $email);
            $delivery->attachments = array_map(static fn ($file): string => $file->originalName, $notification->attachUploads ? $submission->files() : []);
        }

        return $this->dispatch($email, $delivery, $notification->name, $submission);
    }

    private function dispatch(Email $email, ?NotificationDelivery $delivery, ?string $notificationName = null, ?Submission $submission = null): bool
    {
        if ($delivery !== null) {
            $this->deliveryRepository?->save($delivery);
            $email->getHeaders()->addTextHeader(NotificationDelivery::HEADER, (string) $delivery->id);
        }

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('The notification "{notification}" could not be sent: {message}', [
                'notification' => $notificationName ?? $delivery?->notificationName,
                'form' => $submission?->form->key,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            if ($delivery !== null) {
                $delivery->markFailed($exception->getMessage());
                $this->deliveryRepository?->save($delivery);
            }

            return false;
        }
    }

    private function newDelivery(Submission $submission, ?Notification $notification, string $name, string $trigger, ?string $triggeredBy): ?NotificationDelivery
    {
        if ($this->deliveryRepository === null || !$submission->isPersisted()) {
            return null;
        }

        $delivery = new NotificationDelivery($submission, $notification, $name, NotificationDelivery::QUEUED);
        $delivery->trigger = $trigger;
        $delivery->triggeredBy = $triggeredBy;

        return $delivery;
    }

    private function skipped(Submission $submission, Notification $notification, string $reason, string $trigger): void
    {
        $delivery = $this->newDelivery($submission, $notification, $notification->name, $trigger, null);

        if ($delivery === null) {
            $this->logger->info('The notification "{notification}" was not sent: {reason}', ['notification' => $notification->name, 'reason' => $reason]);

            return;
        }

        $delivery->status = NotificationDelivery::SKIPPED;
        $delivery->message = $reason;
        $this->deliveryRepository?->save($delivery);
    }

    private function snapshot(NotificationDelivery $delivery, Email $email): void
    {
        $delivery->fromAddress = ($email->getFrom()[0] ?? null)?->toString();
        $recipients = [];

        foreach (['to' => $email->getTo(), 'cc' => $email->getCc(), 'bcc' => $email->getBcc(), 'replyTo' => $email->getReplyTo()] as $kind => $addresses) {
            $list = [];

            foreach ($addresses as $address) {
                $list[] = $address->toString();
            }

            if ($list !== []) {
                $recipients[$kind] = $list;
            }
        }

        $delivery->recipients = $recipients;
        $delivery->subject = $email->getSubject();
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $delivery->htmlBody = \is_string($html) ? $html : null;
        $delivery->textBody = \is_string($text) ? $text : null;
    }

    /**
     * The submissions of the form in the admin, on the host the form was sent from.
     */
    private function adminUrl(FormDefinition $definition, Submission $submission): ?string
    {
        $parts = parse_url($submission->url ?? '');

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return \sprintf(
            '%s://%s%s%s/#/forms/%s/%d/submissions',
            $parts['scheme'],
            $parts['host'],
            isset($parts['port']) ? ':'.$parts['port'] : '',
            rtrim($this->adminPath, '/'),
            $definition->locale,
            $definition->id,
        );
    }

    /**
     * Placeholders first, then split: "{{ email }}, team@example.com" is two recipients. An
     * address a visitor mistyped is dropped instead of failing the whole e-mail.
     *
     * @return list<Address>
     */
    private function addresses(string $addresses, Submission $submission): array
    {
        $rendered = $this->placeholderRenderer->render($addresses, $submission, false);
        $result = [];

        foreach (preg_split('/[\s,;]+/', $rendered) ?: [] as $address) {
            if ($address !== '' && filter_var($address, \FILTER_VALIDATE_EMAIL) !== false) {
                $result[] = new Address($address);
            }
        }

        return $result;
    }
}
