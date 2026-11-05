<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form\Type;

use Eekes\Sulu\FormBuilderBundle\Application\Condition\ConditionEvaluator;
use Eekes\Sulu\FormBuilderBundle\Application\Event\FieldBuildEvent;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Form\ColumnClassResolverInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Form\FlowData;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One step of a form: its fields, in the order of the grid.
 *
 * Every field is a plain child of a native Symfony type, so validation, form themes and data
 * transformers all work as documented. What this type adds is the grid classes, the condition
 * the browser evaluates, and - on submit - dropping the fields whose condition is not met, so a
 * hidden field is neither validated nor stored.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class StepType extends AbstractType
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypeRegistry,
        private readonly ColumnClassResolverInterface $columnClassResolver,
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SpamCheckRegistry $spamCheckRegistry = null,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var FormDefinition $definition */
        $definition = $options['definition'];
        /** @var StepDefinition $step */
        $step = $options['step'];
        /** @var array<string, mixed> $previousValues */
        $previousValues = $options['previous_values'];
        /** @var array<string, list<StoredFile>> $storedFiles */
        $storedFiles = $options['stored_files'];

        foreach ($step->fields as $field) {
            // Conditions on answers of earlier steps are already decided: such a field is left out,
            // or made required, here instead of by the browser.
            if (self::dependsOnOtherStepsOnly($field->visibleIf, $step) && !$this->conditionEvaluator->isSetMet($field->visibleIf, $previousValues)) {
                continue;
            }

            if (!$this->fieldTypeRegistry->has($field->type)) {
                // A field type that was removed from the code must not take the website down.
                $this->logger->warning('The form "{form}" has a field "{field}" of the unknown type "{type}"; it is not rendered.', [
                    'form' => $definition->key,
                    'field' => $field->key,
                    'type' => $field->type,
                ]);

                continue;
            }

            $required = $field->required
                || (self::dependsOnOtherStepsOnly($field->requiredIf, $step) && $this->conditionEvaluator->isSetMet($field->requiredIf, $previousValues));

            // A file uploaded on an earlier visit to this step is there already: the field only
            // needs a new one to replace it, and a ticked checkbox below it keeps the old one.
            $stored = $storedFiles[$field->key] ?? [];

            $this->addField($builder, $definition, $field->withRequired($required && $stored === []), $step);

            if ($stored !== [] && $builder->has($field->key)) {
                $this->addKeepCheckbox($builder, $field, $stored, $step);
            }
        }

        // Whatever the spam checks of the form need, on every step: they decide when the visitor
        // finishes, which can be on any step once the steps after it are skipped.
        foreach ($definition->spamChecks as $key) {
            if ($this->spamCheckRegistry?->has($key) ?? false) {
                $this->spamCheckRegistry->get($key)->buildStep($builder, $definition, $step);
            }
        }

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($definition, $step, $storedFiles): void {
            $this->applyConditions($event, $definition, $step, $storedFiles);
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['step'] = $options['step'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->define('definition')->required()->allowedTypes(FormDefinition::class);
        $resolver->define('step')->required()->allowedTypes(StepDefinition::class);
        $resolver->define('previous_values')->default([])->allowedTypes('array')->info('Answers of the other steps, by field key.');
        $resolver->define('stored_files')->default([])->allowedTypes('array')->info('Files uploaded on an earlier visit to this step, by field key.');
        $resolver->setDefaults([
            'label' => false,
            'translation_domain' => false,
            // A page rendered before an earlier answer changed may still post a field that has been
            // left out since; that is not the visitor's mistake.
            'allow_extra_fields' => true,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'eekes_form_builder_step';
    }

    /**
     * Adds a field as a native child. Also used on submit, to add a field again once a
     * conditional requirement applies - which is why it takes a form as well as a builder, and
     * why FieldBuildEvent can be dispatched twice for the same field.
     *
     * @param FormBuilderInterface<null|array<string, mixed>>|FormInterface<mixed> $parent
     */
    private function addField(FormBuilderInterface|FormInterface $parent, FormDefinition $definition, FieldDefinition $field, StepDefinition $step): void
    {
        $fieldType = $this->fieldTypeRegistry->get($field->type);
        $formOptions = $fieldType->getFormOptions($field);

        $rowAttr = \is_array($formOptions['row_attr'] ?? null) ? $formOptions['row_attr'] : [];
        $rowAttr['class'] = trim($this->columnClassResolver->resolve($field->width).' '.(\is_string($rowAttr['class'] ?? null) ? $rowAttr['class'] : ''));
        $rowAttr['data-eekes-field'] = $field->key;

        // What the browser evaluates: rules on answers of this step. Rules on earlier steps were
        // decided when the step was built.
        if ($field->visibleIf !== null && !self::dependsOnOtherStepsOnly($field->visibleIf, $step)) {
            $rowAttr['data-eekes-condition'] = json_encode($field->visibleIf->toArray(), \JSON_THROW_ON_ERROR);
        }

        if (!$field->required && $field->requiredIf !== null && !self::dependsOnOtherStepsOnly($field->requiredIf, $step)) {
            $rowAttr['data-eekes-required-if'] = json_encode($field->requiredIf->toArray(), \JSON_THROW_ON_ERROR);
        }

        $formOptions['row_attr'] = $rowAttr;

        $event = new FieldBuildEvent($definition, $field, $fieldType->getFormType($field), $formOptions);
        $this->eventDispatcher->dispatch($event);

        if (!$event->isRemoved()) {
            $parent->add($field->key, $event->getFormType(), $event->getOptions());
        }
    }

    /**
     * "Keep cv.pdf": ticked by default, in the same column as the file field and shown or hidden
     * with it. Unticked without a new file, the old one is let go (see UploadAwareDataStorage).
     *
     * @param FormBuilderInterface<null|array<string, mixed>> $builder
     * @param list<StoredFile> $stored
     */
    private function addKeepCheckbox(FormBuilderInterface $builder, FieldDefinition $field, array $stored, StepDefinition $step): void
    {
        $rowAttr = ['class' => trim($this->columnClassResolver->resolve($field->width).' eekes-form-keep-upload'), 'data-eekes-keep' => $field->key];

        if ($field->visibleIf !== null && !self::dependsOnOtherStepsOnly($field->visibleIf, $step)) {
            $rowAttr['data-eekes-condition'] = json_encode($field->visibleIf->toArray(), \JSON_THROW_ON_ERROR);
        }

        $builder->add(FlowData::KEEP_PREFIX.$field->key, CheckboxType::class, [
            'required' => false,
            'data' => true,
            'label' => 'eekes_form_builder.keep_upload',
            'label_translation_parameters' => ['%files%' => implode(', ', array_map(static fn (StoredFile $file): string => $file->originalName, $stored))],
            'translation_domain' => 'eekes_form_builder',
            'row_attr' => $rowAttr,
        ]);
    }

    /**
     * On submit, with the answers known: a field whose visibility is not met is removed, so it
     * is neither validated nor stored; a field whose requirement is met is added again as
     * required. Rules may refer to earlier steps, so they see everything answered so far.
     *
     * A file field whose earlier upload is no longer kept, and that gets no new one, is required
     * again if it was required at all.
     *
     * @param array<string, list<StoredFile>> $storedFiles
     */
    private function applyConditions(FormEvent $event, FormDefinition $definition, StepDefinition $step, array $storedFiles = []): void
    {
        $form = $event->getForm();
        $submitted = FlowData::stringKeyed($event->getData());
        $root = $form->getRoot()->getData();
        $values = [...FlowData::values(\is_array($root) ? $root : [], $step->key), ...$submitted];

        foreach ($step->fields as $field) {
            if (!$form->has($field->key)) {
                continue;
            }

            if (!$this->conditionEvaluator->isSetMet($field->visibleIf, $values)) {
                $form->remove($field->key);
                $form->remove(FlowData::KEEP_PREFIX.$field->key);
                unset($submitted[$field->key], $submitted[FlowData::KEEP_PREFIX.$field->key]);

                continue;
            }

            $kept = isset($storedFiles[$field->key])
                && ($submitted[FlowData::KEEP_PREFIX.$field->key] ?? false) !== false
                && !FlowData::hasUpload($submitted[$field->key] ?? null);

            if ($kept) {
                continue;
            }

            $requiredIf = !$field->required && $field->requiredIf !== null && $this->conditionEvaluator->isSetMet($field->requiredIf, $values);

            if ($requiredIf || ($field->required && isset($storedFiles[$field->key]))) {
                $this->addField($form, $definition, $field->withRequired(true), $step);
            }
        }

        $event->setData($submitted);
    }

    /**
     * Whether every rule of a set looks at fields outside this step, so it can be decided before
     * the step is shown.
     */
    private static function dependsOnOtherStepsOnly(?ConditionSet $set, StepDefinition $step): bool
    {
        if ($set === null) {
            return false;
        }

        foreach ($set->fields() as $key) {
            if ($step->field($key) !== null) {
                return false;
            }
        }

        return true;
    }
}
