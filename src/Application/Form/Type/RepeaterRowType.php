<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Form\ColumnClassResolverInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of a repeater: its sub-fields as native children, built by their own field types and
 * laid out on the same grid as the fields of a step.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class RepeaterRowType extends AbstractType
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypeRegistry,
        private readonly ColumnClassResolverInterface $columnClassResolver,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fields = $options['fields'];
        \assert(\is_array($fields));

        foreach ($fields as $field) {
            \assert($field instanceof FieldDefinition);
            $fieldType = $this->fieldTypeRegistry->get($field->type);
            $formOptions = $fieldType->getFormOptions($field);

            $rowAttr = \is_array($formOptions['row_attr'] ?? null) ? $formOptions['row_attr'] : [];
            $rowAttr['class'] = trim($this->columnClassResolver->resolve($field->width).' '.(\is_string($rowAttr['class'] ?? null) ? $rowAttr['class'] : ''));
            $formOptions['row_attr'] = $rowAttr;

            $builder->add($field->key, $fieldType->getFormType($field), $formOptions);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->define('fields')->default([])->allowedTypes(FieldDefinition::class.'[]');
        $resolver->setDefaults([
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'eekes_form_builder_repeater_row';
    }
}
