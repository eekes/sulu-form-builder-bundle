<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\NotificationDelivery;

/**
 * An e-mail of the log as the submission modal shows it.
 */
final readonly class NotificationDeliveryPresenter
{
    /**
     * @param bool $withBody include the e-mail itself, for viewing it
     *
     * @return array<string, mixed>
     */
    public function toArray(NotificationDelivery $delivery, bool $withBody = false): array
    {
        $data = [
            'id' => $delivery->id,
            'notificationName' => $delivery->notificationName,
            'status' => $delivery->status,
            'message' => $delivery->message,
            'trigger' => $delivery->trigger,
            'triggeredBy' => $delivery->triggeredBy,
            'from' => $delivery->fromAddress,
            'recipients' => $delivery->recipients,
            'subject' => $delivery->subject,
            'template' => $delivery->template,
            'attachments' => $delivery->attachments,
            'createdAt' => $delivery->createdAt->format(\DATE_ATOM),
            'sentAt' => $delivery->sentAt?->format(\DATE_ATOM),
            // Only an e-mail that was built can be shown or sent again.
            'resendable' => $delivery->htmlBody !== null,
        ];

        if ($withBody) {
            $data['html'] = $delivery->htmlBody;
            $data['text'] = $delivery->textBody;
        }

        return $data;
    }
}
