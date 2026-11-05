<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Notification;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type\RepeaterFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;

/**
 * A submission with believable answers for every field of a form, so the preview and the test
 * e-mail of a notification look like the real thing - placeholders included. Never stored.
 */
final readonly class SampleSubmission
{
    /**
     * Answers for fields whose key says what they hold.
     */
    private const array BY_KEY = [
        'first_name' => 'Jane', 'firstname' => 'Jane', 'voornaam' => 'Jane',
        'last_name' => 'Doe', 'lastname' => 'Doe', 'achternaam' => 'Doe', 'naam' => 'Jane Doe', 'name' => 'Jane Doe',
        'company' => 'Acme', 'bedrijf' => 'Acme', 'organisation' => 'Acme', 'organization' => 'Acme',
        'city' => 'Ghent', 'gemeente' => 'Ghent', 'zip' => '9000', 'postcode' => '9000', 'street' => 'Main Street 1', 'straat' => 'Main Street 1',
        'subject' => 'A question about your services', 'onderwerp' => 'A question about your services',
    ];

    public function __construct(
        private FieldTypeRegistry $fieldTypeRegistry,
    ) {
    }

    public function create(FormDefinition $definition, Form $form): Submission
    {
        $values = [];

        foreach ($definition->fields() as $field) {
            if (!$this->fieldTypeRegistry->has($field->type) || !$this->fieldTypeRegistry->get($field->type)->isInput()) {
                continue;
            }

            $value = $this->answer($field);
            // Displayed by the field type itself, exactly like a real answer ("Yes", a choice's label).
            $display = $this->fieldTypeRegistry->get($field->type)->displayValue($value, $field, $definition->locale);
            $values[] = new SubmittedValue($field->key, strip_tags($field->displayLabel()), $field->type, $value, $display, $field->showInList);
        }

        $submission = new Submission($form, $definition->locale, $values);
        $submission->url = 'https://www.example.com/contact';

        return $submission;
    }

    private function answer(FieldDefinition $field): mixed
    {
        $key = strtolower($field->key);

        if (isset(self::BY_KEY[$key])) {
            return self::BY_KEY[$key];
        }

        // Two rows of believable answers to the sub-fields.
        $fieldType = $this->fieldTypeRegistry->has($field->type) ? $this->fieldTypeRegistry->get($field->type) : null;

        if ($fieldType instanceof RepeaterFieldType) {
            $row = [];

            foreach ($fieldType->subFields($field) as $subField) {
                $row[$subField->key] = $this->answer($subField);
            }

            return $row === [] ? [] : [$row, $row];
        }

        return match ($field->type) {
            'email' => 'jane.doe@example.com',
            'phone' => '+32 470 12 34 56',
            'url' => 'https://www.example.com',
            'number' => 42,
            'date' => date('Y-m-d'),
            'time' => '14:30',
            'datetime' => date('Y-m-d').' 14:30',
            'rating' => 4,
            'textarea' => "Hello,\nI would like to know more about this. Could you call me back?\nThank you!",
            'checkbox' => true,
            'file' => ['document.pdf'],
            'choice' => $this->firstChoice($field),
            'country' => 'BE',
            default => 'Lorem ipsum',
        };
    }

    /**
     * The value of the first choice - a list of it when the field takes several.
     */
    private function firstChoice(FieldDefinition $field): mixed
    {
        $choices = $field->option('choices');
        $first = \is_array($choices) ? ($choices[0] ?? null) : null;
        $value = \is_array($first) && is_scalar($first['value'] ?? null) ? (string) $first['value'] : 'option';

        return $field->boolOption('multiple') || $field->stringOption('display') === 'checkboxes' ? [$value] : $value;
    }
}
