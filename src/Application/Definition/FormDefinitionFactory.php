<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Definition;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\SpamCheckRegistry;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\ChoiceList;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldStructure;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StepStructure;

/**
 * Reads a stored form in one language.
 *
 * A field that was never translated into the requested language falls back to the first language
 * it has texts in, so a site that adds a language shows the form in the original language instead
 * of a form full of empty labels.
 */
final readonly class FormDefinitionFactory
{
    /**
     * The texts every field has, besides the options its type declares as localized.
     */
    public const array FIELD_TEXTS = ['label', 'placeholder', 'help'];

    public function __construct(
        private FieldTypeRegistry $fieldTypeRegistry,
        private ?SpamCheckRegistry $spamCheckRegistry = null,
    ) {
    }

    public function create(Form $form, string $locale): FormDefinition
    {
        $steps = array_map(fn (StepStructure $step): StepDefinition => $this->step($step, $locale), $form->structure()->steps);

        return new FormDefinition(
            id: $form->id,
            key: $form->key,
            title: $form->title,
            locale: $locale,
            steps: $steps,
            afterSubmit: $form->afterSubmit(),
            successMessage: $form->text($locale, 'successMessage'),
            redirectPageUuid: $form->redirectPageUuid,
            redirectUrl: $form->redirectUrl,
            submitLabel: $form->text($locale, 'submitLabel'),
            nextLabel: $form->text($locale, 'nextLabel'),
            previousLabel: $form->text($locale, 'previousLabel'),
            storeSubmissions: $form->storeSubmissions,
            spamChecks: $this->spamCheckRegistry?->resolve($form->spamChecks()) ?? [],
        );
    }

    /**
     * The texts of a field in a language, falling back as described above. Shared with the admin,
     * which shows the fallback as a starting point for the translation.
     *
     * @return array<string, mixed>
     */
    public static function fieldTexts(FieldStructure $field, string $locale): array
    {
        $texts = $field->translation($locale);

        if (self::hasText($texts)) {
            return $texts;
        }

        foreach ($field->translations as $translation) {
            if (self::hasText($translation)) {
                return $translation;
            }
        }

        return $texts;
    }

    /**
     * A localized option as a language reads it. A list of choices takes its values (a list of
     * sub-fields its keys) from the first language that has one, so every language compares against the same values - also
     * for a form stored before values were shared (see ChoiceList).
     *
     * @param array<string, mixed> $texts the texts of the language, from fieldTexts()
     */
    public static function localizedOption(FieldStructure $field, string $name, array $texts): mixed
    {
        $own = $texts[$name] ?? null;
        $identifier = ChoiceList::identifier($own);

        if ($identifier === null || !ChoiceList::isChoiceList($own, $identifier)) {
            return $own;
        }

        foreach ($field->translations as $translation) {
            $canonical = $translation[$name] ?? null;

            if (ChoiceList::isChoiceList($canonical, $identifier)) {
                return ChoiceList::withSharedValues($own, $canonical, $identifier);
            }
        }

        return $own;
    }

    public static function stepTitle(StepStructure $step, string $locale): string
    {
        if (($step->titles[$locale] ?? '') !== '') {
            return $step->titles[$locale];
        }

        foreach ($step->titles as $title) {
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    private function step(StepStructure $step, string $locale): StepDefinition
    {
        return new StepDefinition(
            key: $step->key,
            title: self::stepTitle($step, $locale),
            fields: array_map(fn (FieldStructure $field): FieldDefinition => $this->field($field, $step->key, $locale), $step->fields),
            visibleIf: $step->visibleIf,
        );
    }

    private function field(FieldStructure $field, string $stepKey, string $locale): FieldDefinition
    {
        $texts = self::fieldTexts($field, $locale);
        $localized = $this->fieldTypeRegistry->has($field->type) ? $this->fieldTypeRegistry->get($field->type)->getLocalizedOptions() : [];
        $options = $field->options;

        foreach ($localized as $name) {
            if (\array_key_exists($name, $texts)) {
                $options[$name] = self::localizedOption($field, $name, $texts);
            }
        }

        return new FieldDefinition(
            key: $field->key,
            type: $field->type,
            label: self::string($texts['label'] ?? null) ?? '',
            placeholder: self::string($texts['placeholder'] ?? null),
            help: self::string($texts['help'] ?? null),
            required: $field->required,
            width: $field->width,
            showInList: $field->showInList,
            visibleIf: $field->visibleIf,
            options: $options,
            stepKey: $stepKey,
            requiredIf: $field->requiredIf,
        );
    }

    /**
     * @param array<string, mixed> $texts
     */
    private static function hasText(array $texts): bool
    {
        foreach ($texts as $text) {
            if ($text !== null && $text !== '' && $text !== []) {
                return true;
            }
        }

        return false;
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
