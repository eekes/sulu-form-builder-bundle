<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\AfterSubmit;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;

/**
 * A form an editor built in the administration interface.
 *
 * The steps and fields are one JSON document rather than a table per level: they are always read
 * and written as a whole, by the builder in the admin and by the website, and a structure that is
 * the same in every language with the texts next to it is exactly what a document is good at.
 * The texts of the form itself (success message, button labels) are stored per locale the same
 * way.
 */
#[ORM\Entity(repositoryClass: FormRepository::class)]
#[ORM\Table(name: 'eekes_form')]
#[ORM\UniqueConstraint(name: 'uniq_eekes_form_key', columns: ['form_key'])]
class Form
{
    final public const string RESOURCE_KEY = 'eekes_forms';
    final public const string LIST_KEY = 'eekes_forms';
    final public const string SECURITY_CONTEXT = 'sulu.form_builder.forms';

    /**
     * The texts a form has per locale, besides the ones of its steps and fields.
     */
    final public const array TEXTS = ['successMessage', 'submitLabel', 'nextLabel', 'previousLabel'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    /**
     * Stable machine name, so a template can render the form without knowing its database id.
     * "key" is a reserved word in MySQL, hence the column name.
     */
    #[ORM\Column(name: 'form_key', length: 64)]
    public string $key;

    /**
     * Internal name, only shown in the admin.
     */
    #[ORM\Column(length: 255)]
    public string $title;

    /**
     * @var array<mixed> see FormStructure
     */
    #[ORM\Column(type: Types::JSON)]
    private array $structure = [];

    /**
     * @var array<string, array<string, null|string>> locale => text name => text
     */
    #[ORM\Column(type: Types::JSON)]
    private array $translations = [];

    #[ORM\Column(length: 16)]
    private string $afterSubmit = AfterSubmit::Message->value;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $redirectPageUuid = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $redirectUrl = null;

    /**
     * Off for forms whose answers only need to reach a mailbox, so personal data is not kept
     * around longer than necessary.
     */
    #[ORM\Column]
    public bool $storeSubmissions = true;

    /**
     * The keys of the spam checks this form uses; null until an editor chose, which means the
     * checks that are on by default (see AsSpamCheck::$enabledByDefault).
     *
     * @var null|list<string>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $spamChecks = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $changedAt;

    public function __construct(string $key, string $title)
    {
        $this->setKey($key);
        $this->title = $title;
        $this->structure = FormStructure::empty()->toArray();
        $this->createdAt = new \DateTimeImmutable();
        $this->changedAt = $this->createdAt;
    }

    /**
     * @return null|list<string> null when the editor never chose
     */
    public function spamChecks(): ?array
    {
        return $this->spamChecks === null ? null : array_values(array_filter($this->spamChecks, 'is_string'));
    }

    /**
     * @param null|list<string> $keys null to go back to the defaults
     */
    public function setSpamChecks(?array $keys): void
    {
        $this->spamChecks = $keys === null ? null : array_values(array_unique($keys));
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key) === 1;
    }

    public function setKey(string $key): void
    {
        if (!self::isValidKey($key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid form key: use lowercase letters, digits, "-" and "_".', $key));
        }

        $this->key = $key;
    }

    public function structure(): FormStructure
    {
        return FormStructure::fromArray($this->structure);
    }

    public function setStructure(FormStructure $structure): void
    {
        $this->structure = $structure->toArray();
    }

    public function field(string $key): ?FieldStructure
    {
        return $this->structure()->field($key);
    }

    public function afterSubmit(): AfterSubmit
    {
        return AfterSubmit::tryFrom($this->afterSubmit) ?? AfterSubmit::Message;
    }

    public function setAfterSubmit(AfterSubmit $afterSubmit): void
    {
        $this->afterSubmit = $afterSubmit->value;
    }

    public function text(string $locale, string $name): ?string
    {
        $text = $this->translations[$locale][$name] ?? null;

        return $text === '' ? null : $text;
    }

    public function setText(string $locale, string $name, ?string $text): void
    {
        if (!\in_array($name, self::TEXTS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a text of a form. Known texts are "%s".', $name, implode('", "', self::TEXTS)));
        }

        $this->translations[$locale][$name] = $text === '' ? null : $text;
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return array_keys($this->translations);
    }

    public function touch(): void
    {
        $this->changedAt = new \DateTimeImmutable();
    }

    public function __clone()
    {
        unset($this->id);
        $this->createdAt = new \DateTimeImmutable();
        $this->changedAt = $this->createdAt;
    }
}
