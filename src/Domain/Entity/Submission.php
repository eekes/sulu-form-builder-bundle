<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\SubmissionRepository;

/**
 * Everything a visitor sent, as a snapshot.
 *
 * The answers are stored with the label and type they had at the time (see SubmittedValue), so
 * the form can change without making old submissions unreadable. A submission also exists when
 * the form does not store submissions - it is then simply never persisted, but listeners and
 * notifications still get the same object.
 */
#[ORM\Entity(repositoryClass: SubmissionRepository::class)]
#[ORM\Table(name: 'eekes_form_submission')]
#[ORM\Index(name: 'idx_eekes_form_submission_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_eekes_form_submission_spam', columns: ['is_spam', 'created_at'])]
class Submission
{
    final public const string RESOURCE_KEY = 'eekes_form_submissions';
    final public const string LIST_KEY = 'eekes_form_submissions';

    /**
     * Blocked submissions are their own resource in the admin, with their own permissions: they
     * hold what a bot - or a person caught by mistake - sent, and the request it came with.
     */
    final public const string SPAM_RESOURCE_KEY = 'eekes_form_spam';
    final public const string SPAM_LIST_KEY = 'eekes_form_spam';
    final public const string SPAM_SECURITY_CONTEXT = 'sulu.form_builder.spam';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Form $form;

    #[ORM\Column(length: 16)]
    public string $locale;

    /**
     * @var list<array<string, mixed>> see SubmittedValue
     */
    #[ORM\Column(name: 'answers', type: Types::JSON)]
    private array $values = [];

    /**
     * Every answer as one lowercase line, so the list in the admin can be searched without
     * querying into JSON - which every database does differently.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $searchText = '';

    /**
     * The page the form was sent from.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $url = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $webspaceKey = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    /**
     * Caught by a spam check: kept apart from the real submissions, in the "Spam" tab of the form,
     * until it is released or pruned.
     */
    #[ORM\Column(name: 'is_spam', options: ['default' => false])]
    private bool $spam = false;

    /**
     * The key of the spam check that caught it, or "listener" for a SubmissionValidatedEvent listener.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $spamCheck = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $spamReason = null;

    #[ORM\Column(nullable: true)]
    private ?float $spamScore = null;

    /**
     * Only kept for blocked submissions, to recognise a bot across submissions.
     */
    #[ORM\Column(length: 45, nullable: true)]
    public ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $userAgent = null;

    /**
     * @param list<SubmittedValue> $values
     */
    public function __construct(Form $form, string $locale, array $values)
    {
        $this->form = $form;
        $this->locale = $locale;
        $this->createdAt = new \DateTimeImmutable();
        $this->setValues($values);
    }

    /**
     * @return list<SubmittedValue>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->values as $data) {
            $value = SubmittedValue::fromArray($data);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<SubmittedValue> $values
     */
    public function setValues(array $values): void
    {
        $this->values = array_map(static fn (SubmittedValue $value): array => $value->toArray(), $values);
        $this->searchText = mb_strtolower(implode(' ', array_map(static fn (SubmittedValue $value): string => $value->display, $values)));
    }

    /**
     * Follows renamed field keys, so an older submission stays in the column of its field.
     *
     * @param array<string, string> $renamed new key by old key
     */
    public function renameKeys(array $renamed): void
    {
        $this->setValues(array_map(
            static fn (SubmittedValue $value): SubmittedValue => isset($renamed[$value->key]) ? $value->withKey($renamed[$value->key]) : $value,
            $this->values(),
        ));
    }

    public function get(string $key): ?SubmittedValue
    {
        foreach ($this->values() as $value) {
            if ($value->key === $key) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The raw value of an answer: what a listener usually wants, e.g. $submission->value('email').
     */
    public function value(string $key): mixed
    {
        return $this->get($key)?->value;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Raw values by key, the shape conditions and placeholders are evaluated against.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->values() as $value) {
            $result[$value->key] = $value->value;
        }

        return $result;
    }

    /**
     * @return list<StoredFile>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->values() as $value) {
            array_push($files, ...$value->files);
        }

        return $files;
    }

    public function markAsSpam(string $check, string $reason, ?float $score = null): void
    {
        $this->spam = true;
        $this->spamCheck = $check;
        $this->spamReason = $reason;
        $this->spamScore = $score;
    }

    /**
     * "Not spam": from now on it is a real submission. Why it was caught stays readable.
     */
    public function release(): void
    {
        $this->spam = false;
        $this->ipAddress = null;
        $this->userAgent = null;
    }

    public function isSpam(): bool
    {
        return $this->spam;
    }

    public function spamCheck(): ?string
    {
        return $this->spamCheck;
    }

    public function spamReason(): ?string
    {
        return $this->spamReason;
    }

    public function spamScore(): ?float
    {
        return $this->spamScore;
    }

    /**
     * Keeps the names of uploaded files but lets go of the files, which a blocked submission never
     * keeps: nobody needs a bot's attachment on the server.
     */
    public function dropFiles(): void
    {
        $this->setValues(array_map(static fn (SubmittedValue $value): SubmittedValue => $value->withoutFiles(), $this->values()));
    }

    public function isPersisted(): bool
    {
        return isset($this->id);
    }

    public function searchText(): string
    {
        return $this->searchText;
    }
}
