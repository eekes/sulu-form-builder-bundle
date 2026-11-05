<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\EventListener;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\NotificationDelivery;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationDeliveryRepository;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Follows Symfony Mailer: when an e-mail of a notification is sent, or fails, the log of its
 * submission says so. Because the mailer dispatches these events wherever it sends - in the
 * request, or in a Messenger worker when e-mails are sent asynchronously - the status is right
 * either way.
 */
final readonly class NotificationDeliveryListener
{
    public function __construct(
        private NotificationDeliveryRepository $deliveryRepository,
    ) {
    }

    public function onSent(SentMessageEvent $event): void
    {
        $sent = $event->getMessage();
        $delivery = $this->delivery($sent->getOriginalMessage());

        if ($delivery !== null) {
            $delivery->markSent($sent->getMessageId());
            $this->deliveryRepository->save($delivery);
        }
    }

    public function onFailed(FailedMessageEvent $event): void
    {
        $delivery = $this->delivery($event->getMessage());

        if ($delivery !== null) {
            $delivery->markFailed($event->getError()->getMessage());
            $this->deliveryRepository->save($delivery);
        }
    }

    private function delivery(RawMessage $message): ?NotificationDelivery
    {
        if (!$message instanceof Message) {
            return null;
        }

        $id = $message->getHeaders()->get(NotificationDelivery::HEADER)?->getBodyAsString();

        return $id !== null && ctype_digit($id) ? $this->deliveryRepository->findById((int) $id) : null;
    }
}
