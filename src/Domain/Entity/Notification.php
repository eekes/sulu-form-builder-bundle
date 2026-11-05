<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\NotificationRepository;

/**
 * An e-mail sent when a form is submitted.
 *
 * A form can have any number of them: typically one to the team and a confirmation to the
 * visitor. Conditions make a notification apply to some submissions only, which is how "send
 * questions about sales to sales@" is configured - one notification per recipient, each with its
 * own condition.
 *
 * Address fields accept placeholders like {{ email }}, so a confirmation can go to whatever the
 * visitor filled in.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'eekes_form_notification')]
class Notification
{
    final public const string RESOURCE_KEY = 'eekes_form_notifications';
    final public const string LIST_KEY = 'eekes_form_notifications';
    final public const string FORM_KEY = 'eekes_form_notification_details';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Form $form;

    /**
     * Internal name, only shown in the admin.
     */
    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column]
    public bool $enabled = true;

    /**
     * Comma separated addresses, placeholders allowed.
     */
    #[ORM\Column(name: 'to_addresses', type: Types::TEXT)]
    public string $to = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $cc = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $bcc = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $replyTo = null;

    /**
     * Empty means the sender configured for the bundle.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $fromEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $fromName = null;

    /**
     * Appends a table with every answer below the text.
     */
    #[ORM\Column]
    public bool $includeValues = true;

    #[ORM\Column]
    public bool $attachUploads = false;

    /**
     * The key of the e-mail template (see NotificationTemplates); null is the default one.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $template = null;

    /**
     * @var array<mixed> see ConditionSet
     */
    #[ORM\Column(type: Types::JSON)]
    private array $conditions = [];

    /**
     * @var array<string, array{subject?: null|string, body?: null|string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $translations = [];

    #[ORM\Column]
    public int $position = 0;

    public function __construct(Form $form, string $name)
    {
        $this->form = $form;
        $this->name = $name;
    }

    /**
     * When the notification is sent; null means always.
     */
    public function conditions(): ?ConditionSet
    {
        return ConditionSet::fromArray($this->conditions);
    }

    public function setConditions(?ConditionSet $conditions): void
    {
        $this->conditions = $conditions?->toArray() ?? [];
    }

    /**
     * Falls back to the first locale that has a subject, so a submission in a language nobody
     * wrote an e-mail for still reaches the team.
     */
    public function subject(string $locale): ?string
    {
        return $this->text($locale, 'subject');
    }

    public function body(string $locale): ?string
    {
        return $this->text($locale, 'body');
    }

    public function setSubject(string $locale, ?string $subject): void
    {
        $this->translations[$locale]['subject'] = $subject === '' ? null : $subject;
    }

    public function setBody(string $locale, ?string $body): void
    {
        $this->translations[$locale]['body'] = $body === '' ? null : $body;
    }

    /**
     * The texts exactly as stored for one locale, without the fallback, for the admin form.
     *
     * @return array{subject: null|string, body: null|string}
     */
    public function ownTexts(string $locale): array
    {
        return [
            'subject' => $this->translations[$locale]['subject'] ?? null,
            'body' => $this->translations[$locale]['body'] ?? null,
        ];
    }

    /**
     * Rewrites every text of every locale - addresses, subject and body - for example to follow a
     * renamed field key.
     *
     * @param \Closure(string): string $rewrite
     */
    public function rewriteTexts(\Closure $rewrite): void
    {
        $this->to = $rewrite($this->to);

        $this->cc = $this->cc === null ? null : $rewrite($this->cc);
        $this->bcc = $this->bcc === null ? null : $rewrite($this->bcc);
        $this->replyTo = $this->replyTo === null ? null : $rewrite($this->replyTo);

        foreach ($this->translations as $locale => $texts) {
            foreach (['subject', 'body'] as $name) {
                if (\is_string($texts[$name] ?? null)) {
                    $this->translations[$locale][$name] = $rewrite($texts[$name]);
                }
            }
        }
    }

    private function text(string $locale, string $name): ?string
    {
        $text = $this->translations[$locale][$name] ?? null;

        if (\is_string($text) && $text !== '') {
            return $text;
        }

        foreach ($this->translations as $texts) {
            $text = $texts[$name] ?? null;

            if (\is_string($text) && $text !== '') {
                return $text;
            }
        }

        return null;
    }

    public function __clone()
    {
        unset($this->id);
    }
}
