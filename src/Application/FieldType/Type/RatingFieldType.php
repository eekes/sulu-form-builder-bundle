<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\RatingType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;

/**
 * A score: stars (1 to 10), or a scale of numbered buttons with an optional text at both ends,
 * such as "0 = not likely, 10 = very likely". Stored as an integer.
 */
#[AsFormFieldType(key: 'rating', title: 'eekes_form_builder.field_type.rating', icon: 'su-trophy', group: 'choice', position: 50)]
final class RatingFieldType extends AbstractFieldType
{
    public const int MAX = 10;

    public function getFormType(FieldDefinition $field): string
    {
        return RatingType::class;
    }

    public function getLocalizedOptions(): array
    {
        return ['lowLabel', 'highLabel'];
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        $scores = range($this->min($field), $this->max($field));

        return [
            'choices' => array_combine(array_map('strval', $scores), $scores),
            'rating_display' => $this->display($field),
            'low_label' => $this->text($field, 'lowLabel'),
            'high_label' => $this->text($field, 'highLabel'),
            'attr' => [],
        ];
    }

    public function normalizeValue(mixed $data, FieldDefinition $field): mixed
    {
        return is_numeric($data) ? (int) $data : null;
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        return is_numeric($value) ? \sprintf('%d / %d', (int) $value, $this->max($field)) : '';
    }

    public function min(FieldDefinition $field): int
    {
        // Stars start at one: zero stars is not an answer.
        if ($this->display($field) === RatingType::DISPLAY_STARS) {
            return 1;
        }

        return $field->intOption('min', 1) === 0 ? 0 : 1;
    }

    public function max(FieldDefinition $field): int
    {
        return max(2, min(self::MAX, $field->intOption('max', 5) ?? 5));
    }

    private function display(FieldDefinition $field): string
    {
        return $field->stringOption('display') === RatingType::DISPLAY_SCALE ? RatingType::DISPLAY_SCALE : RatingType::DISPLAY_STARS;
    }

    private function text(FieldDefinition $field, string $name): ?string
    {
        $text = trim($field->stringOption($name) ?? '');

        return $text !== '' ? $text : null;
    }
}
