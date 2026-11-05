<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Radio buttons for a score, rendered as stars or as a row of numbered buttons. A native choice
 * underneath: only the rendering differs (block prefix "eekes_form_builder_rating").
 *
 * @extends AbstractType<mixed>
 */
final class RatingType extends AbstractType
{
    public const string DISPLAY_STARS = 'stars';
    public const string DISPLAY_SCALE = 'scale';

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['rating_display'] = $options['rating_display'];
        $view->vars['low_label'] = $options['low_label'];
        $view->vars['high_label'] = $options['high_label'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->define('rating_display')->default(self::DISPLAY_STARS)->allowedValues(self::DISPLAY_STARS, self::DISPLAY_SCALE);
        $resolver->define('low_label')->default(null)->allowedTypes('string', 'null');
        $resolver->define('high_label')->default(null)->allowedTypes('string', 'null');
        $resolver->setDefaults([
            'expanded' => true,
            'multiple' => false,
            'placeholder' => false,
            'choice_translation_domain' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'eekes_form_builder_rating';
    }
}
