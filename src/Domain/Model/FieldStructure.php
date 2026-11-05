<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * One field as it is stored: the shared settings plus the texts of every locale.
 *
 * The structure of a form is the same in every language - only the texts differ - so a field is
 * stored once, with its translations next to it. The read model for one language is
 * FieldDefinition.
 */
final readonly class FieldStructure
{
    /**
     * Child names of a Symfony form, placeholders in e-mails and conditions all refer to a field by
     * its key, so it is restricted to what is safe in all three.
     */
    public const string KEY_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/';

    /**
     * What identifies the field while its key changes, so a rename can be told apart from
     * removing one field and adding another. A field stored before ids existed uses its key; a
     * new id always holds a dash, which a key never does, so the two can never collide.
     */
    public string $id;

    /**
     * @param array<string, mixed> $options settings that are the same in every locale
     * @param array<string, array<string, mixed>> $translations per locale: label, placeholder, help
     *                                                          and the options a field type declares as localized
     */
    public function __construct(
        public string $key,
        public string $type,
        public ColumnWidths $width = new ColumnWidths(),
        public bool $required = false,
        public bool $showInList = false,
        public ?ConditionSet $visibleIf = null,
        public array $options = [],
        public array $translations = [],
        public ?ConditionSet $requiredIf = null,
        string $id = '',
    ) {
        if (!self::isValidKey($key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid field key.', $key));
        }

        $this->id = $id !== '' ? $id : $key;
    }

    public static function newId(): string
    {
        return implode('-', str_split(bin2hex(random_bytes(8)), 4));
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $key = $data['key'] ?? null;
        $type = $data['type'] ?? null;

        if (!\is_string($key) || !self::isValidKey($key) || !\is_string($type) || $type === '') {
            return null;
        }

        return new self(
            key: $key,
            type: $type,
            width: ColumnWidths::fromArray(\is_array($data['width'] ?? null) ? $data['width'] : []),
            required: (bool) ($data['required'] ?? false),
            showInList: (bool) ($data['showInList'] ?? false),
            visibleIf: ConditionSet::fromArray($data['visibleIf'] ?? null),
            requiredIf: ConditionSet::fromArray($data['requiredIf'] ?? null),
            options: self::stringKeyed($data['options'] ?? []),
            translations: self::translations($data['translations'] ?? []),
            id: \is_string($data['id'] ?? null) ? $data['id'] : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'type' => $this->type,
            'width' => $this->width->toArray(),
            'required' => $this->required,
            'showInList' => $this->showInList,
            'visibleIf' => $this->visibleIf?->toArray(),
            'requiredIf' => $this->requiredIf?->toArray(),
            'options' => $this->options,
            'translations' => $this->translations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function translation(string $locale): array
    {
        return $this->translations[$locale] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (\is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function translations(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $locale => $texts) {
            if (\is_string($locale) && \is_array($texts)) {
                $result[$locale] = self::stringKeyed($texts);
            }
        }

        return $result;
    }
}
