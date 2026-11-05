<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Notification\NotificationSender;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\NotificationDelivery;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationDeliveryRepository;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\NotificationDeliveryPresenter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The e-mail log of a submission: an e-mail as it was sent, and sending it again. Guarded like
 * the submission itself; blocked submissions have none to show.
 */
final class NotificationDeliveryController extends AbstractAdminController
{
    public const string ROUTE = 'eekes_form_builder.get_notification_delivery';
    public const string RESOURCE_KEY = 'eekes_form_notification_deliveries';

    public function __construct(
        private readonly NotificationDeliveryRepository $deliveryRepository,
        private readonly NotificationDeliveryPresenter $presenter,
        private readonly NotificationSender $notificationSender,
    ) {
    }

    #[Route(path: '/form-notification-deliveries/{id}', name: self::ROUTE, requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id): Response
    {
        return $this->json($this->presenter->toArray($this->load($id), true));
    }

    /**
     * "resend": the same e-mail again, logged as a new delivery.
     */
    #[Route(path: '/form-notification-deliveries/{id}', name: 'eekes_form_builder.post_notification_delivery_trigger', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function postTrigger(int $id, Request $request): Response
    {
        if ($request->query->getString('action') !== 'resend') {
            throw new BadRequestHttpException(\sprintf('Unknown action "%s".', $request->query->getString('action')));
        }

        $original = $this->load($id);

        if ($original->htmlBody === null) {
            throw new BadRequestHttpException('This e-mail was never built, so there is nothing to send again.');
        }

        return $this->json($this->presenter->toArray($this->notificationSender->resend($original, $this->getUser()?->getUserIdentifier())));
    }

    private function load(int $id): NotificationDelivery
    {
        $delivery = $this->deliveryRepository->findById($id);

        return $delivery !== null && !$delivery->submission->isSpam() ? $delivery : throw self::notFound('e-mail', $id);
    }
}
