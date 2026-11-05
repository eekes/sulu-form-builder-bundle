<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form\Type;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The rows of a repeater: a native collection of RepeaterRowType, with a prototype the website
 * script copies for "add another". Without the script the visitor still gets the minimum number of
 * rows (at least one).
 *
 * @extends AbstractType<mixed>
 */
final class RepeaterType extends AbstractType
{
    public const string PROTOTYPE_NAME = '__row__';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $min = $options['min'];
        \assert(\is_int($min));

        // Before the collection builds its rows from the data: an empty repeater starts with rows.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($min): void {
            $data = $event->getData();

            if (!\is_array($data) || $data === []) {
                $event->setData(array_fill(0, max(1, $min), []));
            }
        }, 10);

        // Before the rows are submitted: a row left empty is not an answer, so it is dropped
        // instead of failing on its required sub-fields - except as many as the minimum asks for,
        // so the visitor sees which fields to fill in, in rows that are still there.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($min): void {
            $data = $event->getData();

            if (!\is_array($data)) {
                return;
            }

            $blanksToKeep = max(1, $min) - \count(array_filter($data, self::isFilled(...)));
            $kept = [];

            foreach ($data as $index => $row) {
                if (self::isFilled($row) || $blanksToKeep-- > 0) {
                    $kept[$index] = $row;
                }
            }

            $event->setData($kept);
        }, 10);

        // After the rows were submitted, before validation: a row without any answer does not
        // count towards the minimum.
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            $event->setData(array_values(array_filter(\is_array($data) ? $data : [], self::isFilled(...))));
        }, -10);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['required'] = $options['marked_required'];
        $view->vars['min'] = $options['min'];
        $view->vars['max'] = $options['max'];
        $view->vars['add_label'] = $options['add_label'];
        $view->vars['row_label'] = $options['row_label'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->define('fields')->default([])->allowedTypes(FieldDefinition::class.'[]');
        $resolver->define('min')->default(0)->allowedTypes('int');
        $resolver->define('max')->default(null)->allowedTypes('int', 'null');
        $resolver->define('add_label')->default(null)->allowedTypes('string', 'null');
        $resolver->define('row_label')->default(null)->allowedTypes('string', 'null');
        $resolver->define('marked_required')->default(false)->allowedTypes('bool');
        $resolver->setDefaults([
            'entry_type' => RepeaterRowType::class,
            'entry_options' => static fn (Options $options): array => ['fields' => $options['fields'], 'label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'prototype' => true,
            'prototype_name' => self::PROTOTYPE_NAME,
            'error_bubbling' => false,
        ]);
    }

    public function getParent(): string
    {
        return CollectionType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'eekes_form_builder_repeater';
    }

    /**
     * Whether a row holds an answer, as submitted (strings, an unticked checkbox missing) or as
     * data (null and false for nothing).
     */
    private static function isFilled(mixed $row): bool
    {
        if (!\is_array($row)) {
            return false;
        }

        foreach ($row as $value) {
            if (\is_array($value) ? self::isFilled($value) : ($value !== null && $value !== false && trim(is_scalar($value) ? (string) $value : '') !== '')) {
                return true;
            }
        }

        return false;
    }
}
