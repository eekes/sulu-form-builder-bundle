<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A place in the form that only shows HTML: a heading, a paragraph. It is never mapped, so it
 * cannot add anything to the data of a submission.
 *
 * @extends AbstractType<mixed>
 */
final class StaticContentType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['html'] = $options['html'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->define('html')->default('')->allowedTypes('string');
        $resolver->setDefaults([
            'mapped' => false,
            'required' => false,
            'label' => false,
            'compound' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'eekes_form_builder_static_content';
    }
}
