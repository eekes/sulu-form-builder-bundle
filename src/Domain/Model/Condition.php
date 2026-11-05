<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * "Only when field X equals Y": decides whether a field, a step or a notification applies.
 */
final readonly class Condition
{
    public function __construct(
        public string $field,
        public ConditionOperator $operator,
        public ?string $value = null,
    ) {
    }

    /**
     * Returns null for anything incomplete, so a half-filled condition in the admin means "always"
     * instead of "never".
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)) {
            return null;
        }

        $field = $data['field'] ?? null;
        $operator = \is_string($data['operator'] ?? null) ? ConditionOperator::tryFrom($data['operator']) : null;
        $value = $data['value'] ?? null;

        if (!\is_string($field) || $field === '' || $operator === null) {
            return null;
        }

        return new self($field, $operator, is_scalar($value) ? (string) $value : null);
    }

    /**
     * @return array{field: string, operator: string, value: null|string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }
}
