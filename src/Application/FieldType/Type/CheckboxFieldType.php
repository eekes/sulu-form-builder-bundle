<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A single checkbox, typically consent. The label may hold a link (to a privacy policy), so the
 * inline tags of the rich text editor are kept and everything else is stripped.
 */
#[AsFormFieldType(key: 'checkbox', title: 'eekes_form_builder.field_type.checkbox', icon: 'su-check-circle', group: 'choice', position: 30)]
final class CheckboxFieldType extends AbstractFieldType
{
    private const string ALLOWED_TAGS = '<a><strong><b><em><i><u><br>';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFormType(FieldDefinition $field): string
    {
        return CheckboxType::class;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        return [
            'label' => $field->label !== '' ? trim(strip_tags($field->label, self::ALLOWED_TAGS)) : false,
            'label_html' => true,
            'attr' => [],
        ];
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        return $field->required ? [new IsTrue()] : [];
    }

    public function normalizeValue(mixed $data, FieldDefinition $field): mixed
    {
        return (bool) $data;
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        return $this->translator->trans($value ? 'eekes_form_builder.yes' : 'eekes_form_builder.no', [], 'eekes_form_builder', $locale);
    }
}
