<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * The steps and fields of a form in every locale, as they are stored in the JSON column of Form.
 */
final readonly class FormStructure
{
    /**
     * @param list<StepStructure> $steps
     */
    public function __construct(
        public array $steps = [],
    ) {
    }

    /**
     * An empty form still has one step, so the builder always has somewhere to put a field.
     */
    public static function empty(): self
    {
        return new self([new StepStructure('step1')]);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $steps = [];

        foreach (\is_array($data['steps'] ?? null) ? $data['steps'] : [] as $step) {
            $step = \is_array($step) ? StepStructure::fromArray($step) : null;

            if ($step !== null) {
                $steps[] = $step;
            }
        }

        return $steps === [] ? self::empty() : new self($steps);
    }

    /**
     * @return array{steps: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'steps' => array_map(static fn (StepStructure $step): array => $step->toArray(), $this->steps),
        ];
    }

    /**
     * @return list<FieldStructure>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->steps as $step) {
            array_push($fields, ...$step->fields);
        }

        return $fields;
    }

    public function field(string $key): ?FieldStructure
    {
        foreach ($this->fields() as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The fields whose key changed since an earlier version of this structure, matched by id.
     *
     * @return array<string, string> new key by old key
     */
    public function renamedFieldKeys(self $before): array
    {
        $oldKeys = [];

        foreach ($before->fields() as $field) {
            $oldKeys[$field->id] = $field->key;
        }

        $renamed = [];

        foreach ($this->fields() as $field) {
            $oldKey = $oldKeys[$field->id] ?? null;

            if ($oldKey !== null && $oldKey !== $field->key) {
                $renamed[$oldKey] = $field->key;
            }
        }

        return $renamed;
    }
}
