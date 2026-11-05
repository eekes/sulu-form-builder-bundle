<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\RepeaterType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ColumnWidths;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldStructure;
use Symfony\Component\Validator\Constraints\Count;

/**
 * A group of fields the visitor fills in as often as needed: "add another person".
 *
 * The sub-fields are edited in the repeater's own modal and stored as the localized option
 * "fields", a list of {type, key, label, required, width}; only the labels differ per language
 * (see ChoiceList). Every sub-field is an ordinary field type, so it validates and renders exactly
 * like the same field outside a repeater. File uploads, hidden fields and repeaters cannot be
 * nested.
 *
 * The answer is stored as a list of rows, each row an object of sub-field key => answer.
 */
#[AsFormFieldType(key: 'repeater', title: 'eekes_form_builder.field_type.repeater', icon: 'su-th-list', group: 'input', position: 100)]
final class RepeaterFieldType extends AbstractFieldType
{
    /**
     * Types that cannot be a sub-field: uploads travel outside the form data, hidden fields have
     * nothing to repeat, and a repeater in a repeater is not supported.
     */
    public const array NOT_NESTABLE = ['repeater', 'file', 'hidden'];

    /**
     * The widths a sub-field can take, in grid columns.
     */
    public const array WIDTHS = [3, 4, 6, 12];

    private const array SUB_FIELD_PROPERTIES = ['type', 'key', 'label', 'required', 'width'];

    public function __construct(
        private readonly FieldTypeRegistry $fieldTypeRegistry,
    ) {
    }

    public function getFormType(FieldDefinition $field): string
    {
        return RepeaterType::class;
    }

    public function getLocalizedOptions(): array
    {
        return ['fields', 'addLabel', 'rowLabel'];
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        return [
            'fields' => $this->subFields($field),
            'min' => $this->min($field),
            'max' => $this->max($field),
            'add_label' => $this->text($field, 'addLabel'),
            'row_label' => $this->text($field, 'rowLabel'),
            // Symfony only marks a child required when its parents are: keep the form required so
            // required sub-fields are, and mark the repeater itself as the editor set it.
            'required' => true,
            'marked_required' => $field->required,
            'attr' => [],
        ];
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        $min = $this->min($field);
        $max = $this->max($field);

        if ($min === 0 && $max === null) {
            return [];
        }

        return [new Count(
            min: $min > 0 ? $min : null,
            max: $max,
            minMessage: 'eekes_form_builder.repeater.too_few',
            maxMessage: 'eekes_form_builder.repeater.too_many',
        )];
    }

    /**
     * The sub-fields as fields of their own. Anything that is not a usable field is left out, as
     * everywhere a stored form is read: a broken sub-field never takes the page down.
     *
     * @return list<FieldDefinition>
     */
    public function subFields(FieldDefinition $field): array
    {
        $fields = [];
        $keys = [];

        foreach (\is_array($field->option('fields')) ? $field->option('fields') : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $type = \is_string($item['type'] ?? null) ? $item['type'] : '';
            $key = \is_string($item['key'] ?? null) ? trim($item['key']) : '';

            if (!FieldStructure::isValidKey($key) || \in_array($key, $keys, true) || \in_array($type, self::NOT_NESTABLE, true)) {
                continue;
            }

            if (!$this->fieldTypeRegistry->has($type) || !$this->fieldTypeRegistry->get($type)->isInput()) {
                continue;
            }

            $keys[] = $key;
            $width = is_numeric($item['width'] ?? null) && \in_array((int) $item['width'], self::WIDTHS, true) ? (int) $item['width'] : ColumnWidths::COLUMNS;

            $fields[] = new FieldDefinition(
                key: $key,
                type: $type,
                label: \is_string($item['label'] ?? null) ? $item['label'] : '',
                required: (bool) ($item['required'] ?? false),
                width: ColumnWidths::fromArray(['desktop' => $width]),
                options: array_filter($item, static fn (string|int $name): bool => \is_string($name) && !\in_array($name, self::SUB_FIELD_PROPERTIES, true), \ARRAY_FILTER_USE_KEY),
                stepKey: $field->stepKey,
            );
        }

        return $fields;
    }

    /**
     * Each row as sub-field key => stored answer, without the rows the visitor left empty.
     */
    public function normalizeValue(mixed $data, FieldDefinition $field): mixed
    {
        $rows = [];

        foreach (\is_array($data) ? $data : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $normalized = [];

            foreach ($this->subFields($field) as $subField) {
                $normalized[$subField->key] = $this->fieldTypeRegistry->get($subField->type)->normalizeValue($row[$subField->key] ?? null, $subField);
            }

            if (array_filter($normalized, self::isAnswered(...)) !== []) {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    /**
     * One line per row: "1. First name: Jane, E-mail: jane@example.com".
     */
    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        $lines = [];
        $subFields = $this->subFields($field);

        foreach (\is_array($value) ? array_values($value) : [] as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }

            $parts = [];

            foreach ($subFields as $subField) {
                $display = $this->fieldTypeRegistry->get($subField->type)->displayValue($row[$subField->key] ?? null, $subField, $locale);

                if ($display !== '' && self::isAnswered($row[$subField->key] ?? null)) {
                    $parts[] = $subField->displayLabel().': '.$display;
                }
            }

            if ($parts !== []) {
                $lines[] = ($index + 1).'. '.implode(', ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The fewest rows the visitor fills in: at least one for a required repeater.
     *
     * @return int<0, max>
     */
    public function min(FieldDefinition $field): int
    {
        $min = max(0, $field->intOption('min', 0) ?? 0);
        $max = $this->max($field);

        if ($field->required) {
            $min = max(1, $min);
        }

        return $max !== null ? min($min, $max) : $min;
    }

    /**
     * @return null|positive-int
     */
    public function max(FieldDefinition $field): ?int
    {
        $max = $field->intOption('max');

        return $max !== null && $max > 0 ? $max : null;
    }

    private static function isAnswered(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [] && $value !== false;
    }

    private function text(FieldDefinition $field, string $name): ?string
    {
        $text = trim($field->stringOption($name) ?? '');

        return $text !== '' ? $text : null;
    }
}
