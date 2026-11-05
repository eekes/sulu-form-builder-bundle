<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Event;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;

/**
 * Dispatched once the steps of a form have been added to the native Symfony flow builder, before
 * the form is created. Add, remove or change steps and children here - it is a plain
 * FormFlowBuilderInterface, so everything Symfony's documentation says about form flows applies.
 *
 * @see https://symfony.com/doc/current/form/form_flow.html
 */
final class FormBuildEvent
{
    /**
     * @param FormFlowBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options the options the flow type was created with
     */
    public function __construct(
        public readonly FormDefinition $definition,
        public readonly FormFlowBuilderInterface $builder,
        public readonly array $options,
    ) {
    }
}
