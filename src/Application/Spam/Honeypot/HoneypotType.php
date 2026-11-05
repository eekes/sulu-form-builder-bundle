<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam\Honeypot;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The field of the honeypot: an ordinary text input, rendered out of sight and out of reach of
 * the keyboard and screen readers (block "eekes_form_builder_honeypot_row" of the form theme).
 * A person never fills it in; a bot that fills in every input does.
 *
 * @extends AbstractType<null|string>
 */
final class HoneypotType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped' => false,
            'required' => false,
            'label' => 'Website',
            'translation_domain' => false,
            'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'eekes_form_builder_honeypot';
    }
}
