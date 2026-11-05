<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionProvider;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationSender;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationTemplates;
use Eekes\Sulu\FormBuilderBundle\Application\Notification\SampleSubmission;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The e-mails of a form, listed in its "Notifications" tab and edited in a modal.
 *
 * The id of the form arrives as the formId query parameter: FormAdmin passes the id router
 * attribute along to both the list and the form requests.
 */
final class NotificationController extends AbstractAdminController
{
    public const string LIST_ROUTE = 'eekes_form_builder.get_notifications';
    public const string DETAIL_ROUTE = 'eekes_form_builder.get_notification';

    public const string PREVIEW_ROUTE = 'eekes_form_builder.post_notification_preview';

    public function __construct(
        private readonly FormRepository $formRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly ?NotificationSender $notificationSender = null,
        private readonly ?FormDefinitionProvider $formDefinitionProvider = null,
        private readonly ?SampleSubmission $sampleSubmission = null,
        private readonly ?NotificationTemplates $notificationTemplates = null,
    ) {
    }

    /**
     * The e-mail of a notification as it is being edited - not saved yet - with sample answers
     * for every field of the form. With "?action=send", it goes to one address as a test.
     */
    #[Route(path: '/form-notifications/preview', name: self::PREVIEW_ROUTE, methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $locale = self::locale($request);
        $form = $this->form($request);
        $sender = $this->notificationSender ?? throw new \LogicException('The notification sender is not available.');
        $definition = $this->formDefinitionProvider?->getById($form->id, $locale) ?? throw self::notFound('form', $form->id);

        $payload = self::payload($request);
        $notification = new Notification($form, 'Preview');
        $this->apply($notification, $payload, $locale);
        $submission = ($this->sampleSubmission ?? throw new \LogicException('Sample submissions are not available.'))->create($definition, $form);

        if ($request->query->getString('action') === 'send') {
            $recipient = \is_string($payload['testRecipient'] ?? null) ? trim($payload['testRecipient']) : '';

            if (filter_var($recipient, \FILTER_VALIDATE_EMAIL) === false) {
                return $this->json(['title' => 'Enter a valid e-mail address to send the test to.', 'detail' => 'Enter a valid e-mail address to send the test to.'], Response::HTTP_BAD_REQUEST);
            }

            try {
                $sender->sendTest($definition, $notification, $submission, $recipient);
            } catch (\Throwable $exception) {
                return $this->json(['title' => $exception->getMessage(), 'detail' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            return $this->json(['sent' => true, 'recipient' => $recipient]);
        }

        // Only the preview is about one address: the real recipients may be placeholders.
        $notification->to = 'preview@example.com';
        $email = $sender->createEmail($definition, $notification, $submission);

        if ($email === null) {
            return $this->json(['html' => null, 'problem' => 'No sender: set one on the notification, or configure eekes_sulu_form_builder.sender.email.']);
        }

        return $this->json([
            'html' => $sender->renderHtml($email),
            'subject' => $email->getSubject(),
            'from' => ($email->getFrom()[0] ?? null)?->toString(),
            'problem' => null,
        ]);
    }

    #[Route(path: '/form-notifications', name: self::LIST_ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $notifications = $this->notificationRepository->findByForm($this->form($request));
        $rows = array_map(static fn (Notification $notification): array => [
            'id' => $notification->id,
            'name' => $notification->name,
            'to' => $notification->to,
            'enabled' => $notification->enabled,
            'conditional' => $notification->conditions() !== null,
        ], $notifications);

        return $this->listResponse(Notification::RESOURCE_KEY, $rows, 1, max(1, \count($rows)), \count($rows));
    }

    #[Route(path: '/form-notifications/{id}', name: self::DETAIL_ROUTE, requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id, Request $request): Response
    {
        return $this->json($this->toArray($this->load($id), self::locale($request)));
    }

    #[Route(path: '/form-notifications', name: 'eekes_form_builder.post_notification', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $locale = self::locale($request);
        $form = $this->form($request);
        $notification = new Notification($form, '');
        $notification->position = \count($this->notificationRepository->findByForm($form));

        $this->apply($notification, self::payload($request), $locale);
        $form->touch();
        $this->notificationRepository->save($notification);

        return $this->json($this->toArray($notification, $locale), Response::HTTP_CREATED);
    }

    #[Route(path: '/form-notifications/{id}', name: 'eekes_form_builder.put_notification', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function put(int $id, Request $request): Response
    {
        $locale = self::locale($request);
        $notification = $this->load($id);

        $this->apply($notification, self::payload($request), $locale);
        $notification->form->touch();
        $this->notificationRepository->save($notification);

        return $this->json($this->toArray($notification, $locale));
    }

    #[Route(path: '/form-notifications/{id}', name: 'eekes_form_builder.delete_notification', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->notificationRepository->remove($this->load($id));

        return $this->noContent();
    }

    #[Route(path: '/form-notifications', name: 'eekes_form_builder.delete_notifications', methods: ['DELETE'])]
    public function deleteSelection(Request $request): Response
    {
        foreach (self::ids($request) as $id) {
            $notification = $this->notificationRepository->findById($id);

            if ($notification !== null) {
                $this->notificationRepository->remove($notification);
            }
        }

        return $this->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Notification $notification, string $locale): array
    {
        $texts = $notification->ownTexts($locale);

        return [
            'id' => $notification->id,
            'formId' => $notification->form->id,
            'name' => $notification->name,
            'enabled' => $notification->enabled,
            'to' => $notification->to,
            'cc' => $notification->cc,
            'bcc' => $notification->bcc,
            'replyTo' => $notification->replyTo,
            'fromEmail' => $notification->fromEmail,
            'fromName' => $notification->fromName,
            'includeValues' => $notification->includeValues,
            'attachUploads' => $notification->attachUploads,
            'template' => $notification->template ?? $this->notificationTemplates?->defaultKey(),
            'conditions' => $notification->conditions()?->toArray(),
            'subject' => $texts['subject'],
            'body' => $texts['body'],
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private function apply(Notification $notification, array $data, string $locale): void
    {
        $name = \is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $notification->name = $name !== '' ? $name : ($notification->name !== '' ? $notification->name : 'Notification');

        foreach (['to', 'cc', 'bcc', 'replyTo', 'fromEmail', 'fromName'] as $property) {
            if (!\array_key_exists($property, $data)) {
                continue;
            }

            $value = \is_string($data[$property]) ? trim($data[$property]) : '';

            if ($property === 'to') {
                $notification->to = $value;
            } else {
                $notification->{$property} = $value === '' ? null : $value;
            }
        }

        if (\array_key_exists('template', $data)) {
            $notification->template = \is_string($data['template']) && $data['template'] !== '' ? $data['template'] : null;
        }

        foreach (['enabled', 'includeValues', 'attachUploads'] as $property) {
            if (\array_key_exists($property, $data)) {
                $notification->{$property} = (bool) $data[$property];
            }
        }

        if (\array_key_exists('conditions', $data)) {
            $notification->setConditions(ConditionSet::fromArray($data['conditions']));
        }

        if (\array_key_exists('subject', $data)) {
            $notification->setSubject($locale, \is_string($data['subject']) ? trim($data['subject']) : null);
        }

        if (\array_key_exists('body', $data)) {
            $notification->setBody($locale, \is_string($data['body']) ? $data['body'] : null);
        }
    }

    private function form(Request $request): Form
    {
        $formId = $request->query->getInt('formId');

        if ($formId === 0) {
            throw new BadRequestHttpException('The "formId" query parameter is required.');
        }

        return $this->formRepository->findById($formId) ?? throw self::notFound('form', $formId);
    }

    private function load(int $id): Notification
    {
        return $this->notificationRepository->findById($id) ?? throw self::notFound('notification', $id);
    }
}
