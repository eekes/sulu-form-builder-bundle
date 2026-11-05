<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form\Type;

use Eekes\Sulu\FormBuilderBundle\Application\Condition\ConditionEvaluator;
use Eekes\Sulu\FormBuilderBundle\Application\Event\FormBuildEvent;
use Eekes\Sulu\FormBuilderBundle\Application\Form\FlowData;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRequest;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamVerdict;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamVerdicts;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\Type\FinishFlowType;
use Symfony\Component\Form\Flow\Type\NextFlowType;
use Symfony\Component\Form\Flow\Type\PreviousFlowType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A form an editor built, as a native Symfony form flow.
 *
 * Every form is a flow, also one with a single step: the "finish" button is then the only one,
 * and nothing about it looks multi-step. The data is an array with one entry per step (see
 * FlowData), the shape Symfony's flow stores between requests.
 *
 * @see https://symfony.com/doc/current/form/form_flow.html
 */
final class FormBuilderFlowType extends AbstractFlowType
{
    public function __construct(
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?SpamCheckRegistry $spamCheckRegistry = null,
        private readonly ?SpamVerdicts $spamVerdicts = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
    {
        /** @var FormDefinition $definition */
        $definition = $options['definition'];

        // The parent type has already loaded what the visitor answered on earlier steps.
        $data = $builder->getData();

        foreach ($definition->steps as $step) {
            $builder->addStep(
                $step->key,
                StepType::class,
                [
                    'definition' => $definition,
                    'step' => $step,
                    'previous_values' => FlowData::values(\is_array($data) ? $data : [], $step->key),
                    'stored_files' => FlowData::storedFiles(\is_array($data) ? $data : [], $step->key),
                ],
                $this->skip($step),
            );
        }

        $builder->add('navigator', FormType::class, [
            'label' => false,
            'mapped' => false,
            'priority' => -100,
            'translation_domain' => 'eekes_form_builder',
            'block_prefix' => 'eekes_form_builder_navigator',
        ]);

        $navigator = $builder->get('navigator');
        $navigator->add('previous', PreviousFlowType::class, [
            'label' => $definition->previousLabel ?? 'eekes_form_builder.previous',
            'translation_domain' => $definition->previousLabel === null ? 'eekes_form_builder' : false,
            'attr' => ['class' => 'btn btn-outline-secondary', 'formnovalidate' => 'formnovalidate'],
        ]);
        $navigator->add('next', NextFlowType::class, [
            'label' => $definition->nextLabel ?? 'eekes_form_builder.next',
            'translation_domain' => $definition->nextLabel === null ? 'eekes_form_builder' : false,
            'attr' => ['class' => 'btn btn-primary'],
        ]);
        $navigator->add('finish', FinishFlowType::class, [
            'label' => $definition->submitLabel ?? 'eekes_form_builder.submit',
            'translation_domain' => $definition->submitLabel === null ? 'eekes_form_builder' : false,
            'attr' => ['class' => 'btn btn-primary'],
        ]);

        // After validation (priority 0), so a check only runs on an otherwise valid last step.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($definition): void {
            $this->checkForSpam($event->getForm(), $definition);
        }, -10);

        $this->eventDispatcher->dispatch(new FormBuildEvent($definition, $builder, $options));
    }

    public function buildViewFlow(FormView $view, FormFlowInterface $form, array $options): void
    {
        $data = $form->getData();
        $current = $form->getCursor()->getCurrentStep();

        $view->vars['definition'] = $options['definition'];
        // What the browser needs to evaluate a condition on a field of an earlier step.
        $view->vars['eekes_values'] = array_filter(
            FlowData::values(\is_array($data) ? $data : [], $current),
            static fn (mixed $value): bool => is_scalar($value) || $value === null || (\is_array($value) && array_filter($value, 'is_scalar') === $value),
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->define('definition')->required()->allowedTypes(FormDefinition::class);
        $resolver->setDefaults([
            'data_class' => null,
            'step_property_path' => '['.FlowData::STEP_KEY.']',
            'translation_domain' => false,
            'attr' => ['novalidate' => 'novalidate', 'class' => 'eekes-form'],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'eekes_form_builder';
    }

    /**
     * Runs the spam checks of the form once the visitor finishes it. A check that cannot decide
     * puts an error on the step, so the flow does not finish and the visitor can send again; a
     * check that catches spam leaves the flow alone - the visitor sees the usual success - and
     * hands its verdict to SubmissionProcessor.
     *
     * @param FormInterface<mixed> $form
     */
    private function checkForSpam(FormInterface $form, FormDefinition $definition): void
    {
        if ($definition->spamChecks === [] || $this->spamCheckRegistry === null || $this->spamVerdicts === null || !$form instanceof FormFlowInterface) {
            return;
        }

        $button = $form->getClickedButton();
        $current = $form->getCursor()->getCurrentStep();

        if (!$button instanceof ButtonFlowInterface || !self::finishes($form, $button) || !$form->has($current) || !$form->isValid()) {
            return;
        }

        $request = new SpamCheckRequest($definition, $form->get($current), $this->requestStack?->getCurrentRequest());

        foreach ($definition->spamChecks as $key) {
            if (!$this->spamCheckRegistry->has($key)) {
                continue;
            }

            $result = $this->spamCheckRegistry->get($key)->check($request);

            if ($result->isRetry()) {
                $form->get($current)->addError(new FormError($result->message));

                return;
            }

            if ($result->isSpam()) {
                $this->spamVerdicts->set($definition->key, new SpamVerdict($key, $result->message, $result->score));

                return;
            }
        }
    }

    /**
     * "finish" ends the flow, and so does "next" when every step after this one is skipped.
     *
     * @param FormFlowInterface<mixed> $flow
     */
    private static function finishes(FormFlowInterface $flow, ButtonFlowInterface $button): bool
    {
        if ($button->isFinishAction()) {
            return true;
        }

        if (!$button->isNextAction()) {
            return false;
        }

        $steps = $flow->getCursor()->getSteps();
        $later = \array_slice($steps, (int) array_search($flow->getCursor()->getCurrentStep(), $steps, true) + 1);
        $data = $flow->getData();

        foreach ($later as $step) {
            if (\is_string($step) && !$flow->getConfig()->getStep($step)->isSkipped($data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return null|(\Closure(mixed): bool)
     */
    private function skip(StepDefinition $step): ?\Closure
    {
        $condition = $step->visibleIf;

        if ($condition === null) {
            return null;
        }

        return fn (mixed $data): bool => !$this->conditionEvaluator->isSetMet($condition, FlowData::values(\is_array($data) ? $data : []));
    }
}
