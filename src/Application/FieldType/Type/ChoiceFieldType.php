<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Validator\Constraints\Count;

/**
 * A dropdown, radio buttons or checkboxes, depending on the "display" option.
 *
 * The choices are stored per language, but only their labels differ: the values, their order and
 * the set of choices are shared (see ChoiceList), so conditions and integration flows on a choice
 * work in every language.
 */
#[AsFormFieldType(key: 'choice', title: 'eekes_form_builder.field_type.choice', icon: 'su-list-ul', group: 'choice', position: 10)]
final class ChoiceFieldType extends AbstractFieldType
{
    public const string DISPLAY_SELECT = 'select';
    public const string DISPLAY_RADIO = 'radio';
    public const string DISPLAY_CHECKBOXES = 'checkboxes';

    public function getFormType(FieldDefinition $field): string
    {
        return ChoiceType::class;
    }

    public function getLocalizedOptions(): array
    {
        return ['choices'];
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        $display = $this->display($field);
        $multiple = $this->isMultiple($field);
        $options = [
            'choices' => $this->choices($field),
            'expanded' => $display !== self::DISPLAY_SELECT,
            'multiple' => $multiple,
            'attr' => [],
        ];

        if ($display === self::DISPLAY_SELECT && !$multiple) {
            $options['placeholder'] = $field->placeholder ?? '';
        }

        if ($display !== self::DISPLAY_SELECT) {
            // Radio buttons without an empty choice: "none of the above" is the editor's call.
            $options['placeholder'] = false;
        }

        return $options;
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        if ($field->required && $this->isMultiple($field)) {
            return [new Count(min: 1)];
        }

        return parent::getConstraints($field);
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        $labels = array_flip($this->choices($field));
        $values = \is_array($value) ? $value : [$value];
        $display = [];

        foreach ($values as $item) {
            if (!is_scalar($item) || $item === '') {
                continue;
            }

            $display[] = $labels[(string) $item] ?? (string) $item;
        }

        return implode(', ', $display);
    }

    /**
     * Label by value, the shape ChoiceType wants. A choice without a value uses its label.
     *
     * @return array<string, string>
     */
    public function choices(FieldDefinition $field): array
    {
        $choices = [];

        foreach (\is_array($field->option('choices')) ? $field->option('choices') : [] as $choice) {
            if (!\is_array($choice)) {
                continue;
            }

            $label = \is_scalar($choice['label'] ?? null) ? trim((string) $choice['label']) : '';
            $value = \is_scalar($choice['value'] ?? null) ? trim((string) $choice['value']) : '';

            if ($label === '' && $value === '') {
                continue;
            }

            $choices[$label !== '' ? $label : $value] = $value !== '' ? $value : $label;
        }

        return $choices;
    }

    private function display(FieldDefinition $field): string
    {
        $display = $field->stringOption('display', self::DISPLAY_SELECT);

        return \in_array($display, [self::DISPLAY_SELECT, self::DISPLAY_RADIO, self::DISPLAY_CHECKBOXES], true) ? $display : self::DISPLAY_SELECT;
    }

    private function isMultiple(FieldDefinition $field): bool
    {
        $display = $this->display($field);

        return $display === self::DISPLAY_CHECKBOXES || ($display === self::DISPLAY_SELECT && $field->boolOption('multiple'));
    }
}
