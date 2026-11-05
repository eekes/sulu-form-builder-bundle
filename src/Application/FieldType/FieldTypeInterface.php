<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Turns a field an editor configured into a native Symfony form field, and its answer back into
 * something that can be stored and read.
 *
 * Extend AbstractFieldType rather than implementing this directly: it handles the label, help
 * text, placeholder and the "required" checkbox the same way for every type.
 */
interface FieldTypeInterface
{
    /**
     * @return class-string<FormTypeInterface<mixed>>
     */
    public function getFormType(FieldDefinition $field): string;

    /**
     * Options for the Symfony form type. Width, conditions and the event listeners are added on
     * top of these by the bundle, so they are not a concern of the field type.
     *
     * @return array<string, mixed>
     */
    public function getFormOptions(FieldDefinition $field): array;

    /**
     * Whether the field collects an answer. Headings and blocks of text do not: they are part of
     * the layout, and are left out of submissions, e-mails and conditions.
     */
    public function isInput(): bool;

    /**
     * Names of the options that are stored per locale, like the labels of choices. Every other
     * option is the same in all languages.
     *
     * @return list<string>
     */
    public function getLocalizedOptions(): array;

    /**
     * The submitted value as it is stored: JSON-safe, so a scalar, null or a list of scalars.
     */
    public function normalizeValue(mixed $data, FieldDefinition $field): mixed;

    /**
     * The stored value as a person reads it, in the language of the submission.
     */
    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string;
}
