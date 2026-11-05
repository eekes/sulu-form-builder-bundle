<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Intl\Countries;

#[AsFormFieldType(key: 'country', title: 'eekes_form_builder.field_type.country', icon: 'su-globe', group: 'choice', position: 40)]
final class CountryFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return CountryType::class;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        $preferred = array_values(array_filter(
            array_map('trim', explode(',', strtoupper($field->stringOption('preferredCountries') ?? ''))),
            static fn (string $country): bool => Countries::exists($country),
        ));

        return [
            'placeholder' => $field->placeholder ?? '',
            'preferred_choices' => $preferred,
            'attr' => [],
        ];
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        if (!\is_string($value) || !Countries::exists($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        return Countries::getName($value, $locale);
    }
}
