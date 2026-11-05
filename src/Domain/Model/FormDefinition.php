<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * A form in one language, everything the website needs to render and process it.
 *
 * This is the object the public events hand out. It is immutable on purpose: a listener that wants
 * a different form changes the Symfony form builder, not the definition, so what was configured
 * in the admin and what was rendered can always be told apart.
 */
final readonly class FormDefinition
{
    /**
     * @param list<StepDefinition> $steps
     * @param list<string> $spamChecks the keys of the spam checks the form uses
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $title,
        public string $locale,
        public array $steps,
        public AfterSubmit $afterSubmit = AfterSubmit::Message,
        public ?string $successMessage = null,
        public ?string $redirectPageUuid = null,
        public ?string $redirectUrl = null,
        public ?string $submitLabel = null,
        public ?string $nextLabel = null,
        public ?string $previousLabel = null,
        public bool $storeSubmissions = true,
        public array $spamChecks = [],
    ) {
        if ($steps === []) {
            throw new \InvalidArgumentException(\sprintf('The form "%s" has no steps.', $key));
        }
    }

    /**
     * @return list<FieldDefinition>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->steps as $step) {
            array_push($fields, ...$step->fields);
        }

        return $fields;
    }

    public function field(string $key): ?FieldDefinition
    {
        foreach ($this->steps as $step) {
            $field = $step->field($key);

            if ($field !== null) {
                return $field;
            }
        }

        return null;
    }

    public function step(string $key): ?StepDefinition
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        return null;
    }

    public function isMultiStep(): bool
    {
        return \count($this->steps) > 1;
    }
}
