<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationDeliveryRepository;

/**
 * One e-mail of a notification for a submission - or one that was not sent, and why. It keeps the
 * e-mail as it was sent, so the admin shows exactly what the recipient got and can send it again.
 *
 * Deleted with its submission: it holds the same personal data.
 */
#[ORM\Entity(repositoryClass: NotificationDeliveryRepository::class)]
#[ORM\Table(name: 'eekes_form_notification_delivery')]
class NotificationDelivery
{
    /**
     * Handed to the mailer. With an async mailer it is sent by a worker, which updates the status.
     */
    final public const string QUEUED = 'queued';
    final public const string SENT = 'sent';
    final public const string FAILED = 'failed';

    /**
     * Not sent on purpose, with the reason: its conditions did not match, it is switched off, it
     * has no valid recipient or sender, a listener cancelled it, or the submission was spam.
     */
    final public const string SKIPPED = 'skipped';

    final public const string TRIGGER_SUBMISSION = 'submission';
    final public const string TRIGGER_RESEND = 'resend';
    final public const string TRIGGER_RELEASE = 'release';

    /**
     * The header that ties an e-mail to its delivery, so the status follows the mailer - also in
     * a worker.
     */
    final public const string HEADER = 'X-Eekes-Delivery';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Submission::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Submission $submission;

    /**
     * Not a relation: the log outlives the notification.
     */
    #[ORM\Column(nullable: true)]
    public ?int $notificationId = null;

    #[ORM\Column(length: 255)]
    public string $notificationName;

    #[ORM\Column(length: 16)]
    public string $status;

    /**
     * Why it was not sent, or the error of the mailer.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $message = null;

    /**
     * "trigger" is a reserved word in MySQL, hence the column name.
     */
    #[ORM\Column(name: 'delivery_trigger', length: 16)]
    public string $trigger = self::TRIGGER_SUBMISSION;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $triggeredBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $fromAddress = null;

    /**
     * @var array{to?: list<string>, cc?: list<string>, bcc?: list<string>, replyTo?: list<string>}
     */
    #[ORM\Column(type: Types::JSON)]
    public array $recipients = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $subject = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $htmlBody = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $textBody = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $template = null;

    /**
     * The names of the uploads that were attached; a resend attaches them again.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $attachments = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $sentAt = null;

    /**
     * The Message-ID the mail server gave it.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $messageId = null;

    public function __construct(Submission $submission, ?Notification $notification, string $notificationName, string $status)
    {
        $this->submission = $submission;
        $this->notificationId = $notification !== null && isset($notification->id) ? $notification->id : null;
        $this->notificationName = $notificationName;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markSent(?string $messageId): void
    {
        $this->status = self::SENT;
        $this->sentAt = new \DateTimeImmutable();
        $this->messageId = $messageId;
        $this->message = null;
    }

    public function markFailed(string $error): void
    {
        $this->status = self::FAILED;
        $this->message = $error;
    }
}
