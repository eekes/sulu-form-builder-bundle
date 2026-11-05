<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * One step in one language.
 */
final readonly class StepDefinition
{
    /**
     * @param list<FieldDefinition> $fields
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $fields = [],
        public ?ConditionSet $visibleIf = null,
    ) {
    }

    public function field(string $key): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }
}
