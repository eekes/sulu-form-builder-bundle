<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin;

use Eekes\Sulu\FormBuilderBundle\Application\Definition\FormDefinitionFactory;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\AfterSubmit;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ChoiceList;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ColumnWidths;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ConditionSet;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FlowRepository;
use Eekes\Sulu\FormBuilderBundle\Domain\Repository\FormRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Translates between a stored form and what the admin edits, which is always one language.
 *
 * The builder receives every field with its texts for the language being edited flattened in
 * (label, placeholder, help and the localized options), plus the untouched translations of all
 * languages. On save the texts of the edited language are split off again and the rest is kept
 * as sent - which is why renaming a field key in one language does not lose the others.
 */
final readonly class FormAdminMapper
{
    /**
     * One switch per spam check in the settings form: "spamCheck_honeypot" and so on.
     */
    public const string SPAM_CHECK_PREFIX = 'spamCheck_';

    public function __construct(
        private FieldTypeRegistry $fieldTypeRegistry,
        private FormRepository $formRepository,
        private ?FlowRepository $flowRepository = null,
        private ?IntegrationRegistry $integrationRegistry = null,
        private ?SpamCheckRegistry $spamCheckRegistry = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Form $form, string $locale): array
    {
        $data = [
            'id' => $form->id ?? null,
            'key' => $form->key,
            'title' => $form->title,
            'afterSubmit' => $form->afterSubmit()->value,
            'redirectPage' => $form->redirectPageUuid,
            'redirectUrl' => $form->redirectUrl,
            'storeSubmissions' => $form->storeSubmissions,
            'builder' => $this->builderToArray($form->structure(), $locale),
            'integrationUsage' => (object) $this->integrationUsage($form),
            'requiredByIntegrations' => (object) $this->requiredByIntegrations($form),
            'createdAt' => $form->createdAt->format(\DATE_ATOM),
            'changedAt' => $form->changedAt->format(\DATE_ATOM),
        ];

        foreach (Form::TEXTS as $name) {
            $data[$name] = $form->text($locale, $name);
        }

        $used = $this->spamCheckRegistry?->resolve($form->spamChecks()) ?? [];

        foreach ($this->spamCheckRegistry?->all() ?? [] as $check) {
            $data[self::SPAM_CHECK_PREFIX.$check->key] = \in_array($check->key, $used, true);
        }

        return $data;
    }

    /**
     * Applies what the admin sent. Only keys that are present are touched: every tab of the form
     * view saves the whole resource, but a client that sends a subset should not wipe the rest.
     *
     * @param array<mixed> $data
     */
    public function apply(Form $form, array $data, string $locale): void
    {
        if (\is_string($data['title'] ?? null) && trim($data['title']) !== '') {
            $form->title = trim($data['title']);
        }

        if (\array_key_exists('key', $data) || !isset($form->key)) {
            $key = self::formKey(\is_string($data['key'] ?? null) ? $data['key'] : '', $form->title);
            $form->setKey($this->formRepository->nextFreeKey($key, $form->id ?? null));
        }

        if (\is_string($data['afterSubmit'] ?? null)) {
            $form->setAfterSubmit(AfterSubmit::tryFrom($data['afterSubmit']) ?? AfterSubmit::Message);
        }

        if (\array_key_exists('redirectPage', $data)) {
            $form->redirectPageUuid = self::nullableString($data['redirectPage']);
        }

        if (\array_key_exists('redirectUrl', $data)) {
            $form->redirectUrl = self::nullableString($data['redirectUrl']);
        }

        if (\array_key_exists('storeSubmissions', $data)) {
            $form->storeSubmissions = (bool) $data['storeSubmissions'];
        }

        foreach (Form::TEXTS as $name) {
            if (\array_key_exists($name, $data)) {
                $form->setText($locale, $name, self::nullableString($data[$name]));
            }
        }

        $this->applySpamChecks($form, $data);

        if (\is_array($data['builder'] ?? null)) {
            $form->setStructure($this->structureFromArray($data['builder'], $locale));
        }

        $form->touch();
    }

    /**
     * The switches of the spam checks. Only the ones sent change; a check that is not installed
     * (any more) keeps whatever the form chose for it, so reinstalling it restores the choice.
     *
     * @param array<mixed> $data
     */
    private function applySpamChecks(Form $form, array $data): void
    {
        if ($this->spamCheckRegistry === null) {
            return;
        }

        $chosen = $form->spamChecks() ?? $this->spamCheckRegistry->defaults();
        $changed = false;

        foreach ($this->spamCheckRegistry->all() as $check) {
            $name = self::SPAM_CHECK_PREFIX.$check->key;

            if (!\array_key_exists($name, $data)) {
                continue;
            }

            $changed = true;
            $chosen = array_values(array_diff($chosen, [$check->key]));

            if ((bool) $data[$name]) {
                $chosen[] = $check->key;
            }
        }

        if ($changed) {
            $form->setSpamChecks($chosen);
        }
    }

    /**
     * The flows that read each field, so the builder can mark those fields and warn before one
     * is removed.
     *
     * @return array<string, list<string>> flow names by field key
     */
    private function integrationUsage(Form $form): array
    {
        if ($this->flowRepository === null || !isset($form->id)) {
            return [];
        }

        $usage = [];

        foreach ($this->flowRepository->findByForm($form) as $flow) {
            foreach ($flow->mapping() as $mapping) {
                if ($mapping->isField()) {
                    $usage[$mapping->value][] = $flow->name;
                }
            }

            foreach ($flow->conditions()?->fields() ?? [] as $key) {
                $usage[$key][] = $flow->name;
            }
        }

        return array_map(static fn (array $names): array => array_values(array_unique($names)), $usage);
    }

    /**
     * The flows that fail when a field is left empty, because they map it to a target their
     * integration requires - so the builder can warn before such a field is made optional.
     *
     * @return array<string, list<string>> flow names by field key
     */
    private function requiredByIntegrations(Form $form): array
    {
        if ($this->flowRepository === null || $this->integrationRegistry === null || !isset($form->id)) {
            return [];
        }

        $required = [];

        foreach ($this->flowRepository->findEnabledByForm($form) as $flow) {
            $action = $this->integrationRegistry->action($flow->integration, $flow->action);

            foreach ($flow->mapping() as $target => $mapping) {
                if ($mapping->isField() && ($action?->field($target)->required ?? false)) {
                    $required[$mapping->value][] = $flow->name;
                }
            }
        }

        return array_map(static fn (array $names): array => array_values(array_unique($names)), $required);
    }

    /**
     * @return array{steps: list<array<string, mixed>>}
     */
    public function builderToArray(FormStructure $structure, string $locale): array
    {
        $steps = [];

        foreach ($structure->steps as $step) {
            $steps[] = [
                'key' => $step->key,
                'title' => FormDefinitionFactory::stepTitle($step, $locale),
                'titles' => (object) $step->titles,
                'visibleIf' => $step->visibleIf?->toArray(),
                'fields' => array_map(fn (FieldStructure $field): array => $this->fieldToArray($field, $locale), $step->fields),
            ];
        }

        return ['steps' => $steps];
    }

    /**
     * @param array<mixed> $builder
     */
    public function structureFromArray(array $builder, string $locale): FormStructure
    {
        $steps = [];
        $stepKeys = [];
        $fieldKeys = [];
        $fieldIds = [];

        foreach (\is_array($builder['steps'] ?? null) ? $builder['steps'] : [] as $index => $step) {
            if (!\is_array($step)) {
                continue;
            }

            $key = self::uniqueKey(self::machineKey(\is_string($step['key'] ?? null) ? $step['key'] : '', 'step'.(\count($steps) + 1)), $stepKeys);
            $titles = self::stringMap($step['titles'] ?? []);
            $titles[$locale] = \is_string($step['title'] ?? null) ? trim($step['title']) : '';

            $fields = [];

            foreach (\is_array($step['fields'] ?? null) ? $step['fields'] : [] as $field) {
                $field = \is_array($field) ? $this->fieldFromArray($field, $locale, $fieldKeys, $fieldIds) : null;

                if ($field !== null) {
                    $fields[] = $field;
                }
            }

            $steps[] = new StepStructure($key, array_filter($titles, static fn (string $title): bool => $title !== ''), $fields, ConditionSet::fromArray($step['visibleIf'] ?? null));
        }

        return $steps === [] ? FormStructure::empty() : new FormStructure($steps);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldToArray(FieldStructure $field, string $locale): array
    {
        $texts = FormDefinitionFactory::fieldTexts($field, $locale);
        $options = $field->options;

        foreach ($this->localizedOptions($field->type) as $name) {
            $options[$name] = FormDefinitionFactory::localizedOption($field, $name, $texts);
        }

        return [
            'id' => $field->id,
            'key' => $field->key,
            'type' => $field->type,
            'width' => $field->width->toArray(),
            'required' => $field->required,
            'showInList' => $field->showInList,
            'visibleIf' => $field->visibleIf?->toArray(),
            'requiredIf' => $field->requiredIf?->toArray(),
            'label' => \is_string($texts['label'] ?? null) ? $texts['label'] : '',
            'placeholder' => \is_string($texts['placeholder'] ?? null) ? $texts['placeholder'] : null,
            'help' => \is_string($texts['help'] ?? null) ? $texts['help'] : null,
            // An object even when empty, so the builder never has to tell [] and {} apart.
            'options' => (object) $options,
            'translations' => (object) $field->translations,
        ];
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $usedKeys keys taken by earlier fields, across all steps
     * @param list<string> $usedIds ids taken by earlier fields: a duplicated card that kept the id
     *                              of its original gets a new one
     */
    private function fieldFromArray(array $data, string $locale, array &$usedKeys, array &$usedIds): ?FieldStructure
    {
        $type = $data['type'] ?? null;

        if (!\is_string($type) || $type === '') {
            return null;
        }

        $label = \is_string($data['label'] ?? null) ? $data['label'] : '';
        $key = self::uniqueKey(self::machineKey(\is_string($data['key'] ?? null) ? $data['key'] : '', strip_tags($label), $type), $usedKeys);
        $id = \is_string($data['id'] ?? null) && $data['id'] !== '' && !\in_array($data['id'], $usedIds, true) ? $data['id'] : FieldStructure::newId();
        $usedIds[] = $id;

        $options = [];

        foreach (\is_array($data['options'] ?? null) ? $data['options'] : [] as $name => $value) {
            if (\is_string($name)) {
                $options[$name] = $value;
            }
        }

        $texts = [
            'label' => $label,
            'placeholder' => self::nullableString($data['placeholder'] ?? null),
            'help' => self::nullableString($data['help'] ?? null),
        ];

        foreach ($this->localizedOptions($type) as $name) {
            $texts[$name] = $options[$name] ?? null;
            unset($options[$name]);
        }

        $translations = [];

        foreach (\is_array($data['translations'] ?? null) ? $data['translations'] : [] as $translationLocale => $translation) {
            if (\is_string($translationLocale) && \is_array($translation)) {
                $translations[$translationLocale] = array_filter($translation, 'is_string', \ARRAY_FILTER_USE_KEY);
            }
        }

        // Choices share their values, order and set across languages, sub-fields their keys: the
        // other languages follow the edited one and keep their own labels.
        $before = \is_array($translations[$locale] ?? null) ? $translations[$locale] : (array_values(array_filter($translations, static fn (array $translation): bool => $translation !== []))[0] ?? []);

        foreach ($this->localizedOptions($type) as $name) {
            $edited = $texts[$name];
            $identifier = ChoiceList::identifier($edited);

            if ($identifier === null || !ChoiceList::isChoiceList($edited, $identifier)) {
                continue;
            }

            $previous = $before[$name] ?? null;
            $previous = ChoiceList::isChoiceList($previous, $identifier) ? $previous : [];

            foreach ($translations as $other => $translation) {
                $theirs = $translation[$name] ?? null;

                if ($other !== $locale && ChoiceList::isChoiceList($theirs, $identifier)) {
                    $translations[$other][$name] = ChoiceList::follow($edited, $previous, $theirs, $identifier);
                }
            }
        }

        $translations[$locale] = $texts;

        return new FieldStructure(
            key: $key,
            type: $type,
            width: ColumnWidths::fromArray(\is_array($data['width'] ?? null) ? $data['width'] : []),
            required: (bool) ($data['required'] ?? false),
            showInList: (bool) ($data['showInList'] ?? false),
            visibleIf: ConditionSet::fromArray($data['visibleIf'] ?? null),
            options: $options,
            translations: $translations,
            requiredIf: ConditionSet::fromArray($data['requiredIf'] ?? null),
            id: $id,
        );
    }

    /**
     * @return list<string>
     */
    private function localizedOptions(string $type): array
    {
        return $this->fieldTypeRegistry->has($type) ? $this->fieldTypeRegistry->get($type)->getLocalizedOptions() : [];
    }

    /**
     * A valid field or step key: the one asked for when it is valid, otherwise derived from the
     * label, otherwise the fallback.
     */
    private static function machineKey(string $key, string ...$fallbacks): string
    {
        if (FieldStructure::isValidKey($key)) {
            return $key;
        }

        foreach ([$key, ...$fallbacks] as $candidate) {
            $slug = substr((string) (new AsciiSlugger())->slug($candidate, '_')->lower(), 0, 64);

            if ($slug !== '' && !ctype_alpha($slug[0])) {
                $slug = substr('f_'.$slug, 0, 64);
            }

            if (FieldStructure::isValidKey($slug)) {
                return $slug;
            }
        }

        return 'field';
    }

    /**
     * @param list<string> $usedKeys
     */
    private static function uniqueKey(string $key, array &$usedKeys): string
    {
        $candidate = $key;
        $suffix = 1;

        while (\in_array($candidate, $usedKeys, true)) {
            $candidate = substr($key, 0, 60).'_'.++$suffix;
        }

        $usedKeys[] = $candidate;

        return $candidate;
    }

    private static function formKey(string $key, string $title): string
    {
        if (Form::isValidKey($key)) {
            return $key;
        }

        $slug = substr((string) (new AsciiSlugger())->slug($key !== '' ? $key : $title)->lower(), 0, 64);

        return Form::isValidKey($slug) ? $slug : 'form';
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $result = [];

        foreach (\is_array($value) ? $value : [] as $key => $item) {
            if (\is_string($key) && \is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
