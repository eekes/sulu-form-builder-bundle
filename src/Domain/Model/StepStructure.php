<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * One step as it is stored. A form always has at least one: a single-page form is a flow with one
 * step, so there is only one code path for rendering and submitting.
 */
final readonly class StepStructure
{
    /**
     * @param array<string, string> $titles per locale
     * @param list<FieldStructure> $fields in the order the editor put them in the grid
     */
    public function __construct(
        public string $key,
        public array $titles = [],
        public array $fields = [],
        public ?ConditionSet $visibleIf = null,
    ) {
        if (!FieldStructure::isValidKey($key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid step key.', $key));
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $key = $data['key'] ?? null;

        if (!\is_string($key) || !FieldStructure::isValidKey($key)) {
            return null;
        }

        $titles = [];

        foreach (\is_array($data['titles'] ?? null) ? $data['titles'] : [] as $locale => $title) {
            if (\is_string($locale) && \is_string($title)) {
                $titles[$locale] = $title;
            }
        }

        $fields = [];

        foreach (\is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
            $field = \is_array($field) ? FieldStructure::fromArray($field) : null;

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return new self($key, $titles, $fields, ConditionSet::fromArray($data['visibleIf'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'titles' => $this->titles,
            'fields' => array_map(static fn (FieldStructure $field): array => $field->toArray(), $this->fields),
            'visibleIf' => $this->visibleIf?->toArray(),
        ];
    }
}
