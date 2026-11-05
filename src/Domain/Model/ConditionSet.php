<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * Rules combined with "all of" (AND) or "any of" (OR), where a rule is a single Condition or a
 * nested set - so "(topic is sales AND budget is filled) OR company is filled" is a set of one
 * nested set and one condition.
 *
 * Used for everything that applies conditionally: the visibility and the requirement of a field,
 * the visibility of a step, notifications and integration flows.
 */
final readonly class ConditionSet
{
    public const string ALL = 'all';
    public const string ANY = 'any';

    /**
     * @param list<Condition|ConditionSet> $rules
     */
    public function __construct(
        public array $rules,
        public string $match = self::ALL,
    ) {
        if (!\in_array($match, [self::ALL, self::ANY], true)) {
            throw new \InvalidArgumentException(\sprintf('A condition set matches "%s" or "%s", "%s" given.', self::ALL, self::ANY, $match));
        }
    }

    public static function of(Condition ...$conditions): self
    {
        return new self(array_values($conditions));
    }

    /**
     * Reads what is stored, and also the earlier shapes: a single condition, or a plain list of
     * conditions that all have to be met. Returns null when nothing usable is left, so a
     * half-filled rule in the admin means "always" instead of "never".
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || $data === []) {
            return null;
        }

        if (\array_key_exists('field', $data)) {
            $condition = Condition::fromArray($data);

            return $condition === null ? null : new self([$condition]);
        }

        $match = \is_string($data['match'] ?? null) && $data['match'] === self::ANY ? self::ANY : self::ALL;
        $items = \array_key_exists('rules', $data) ? $data['rules'] : (array_is_list($data) ? $data : []);
        $rules = [];

        foreach (\is_array($items) ? $items : [] as $item) {
            $rule = \is_array($item) && \array_key_exists('field', $item) ? Condition::fromArray($item) : self::fromArray($item);

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules === [] ? null : new self($rules, $match);
    }

    /**
     * @return array{match: string, rules: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'match' => $this->match,
            'rules' => array_map(static fn (Condition|self $rule): array => $rule->toArray(), $this->rules),
        ];
    }

    /**
     * Every field key the set refers to, nested sets included.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->rules as $rule) {
            if ($rule instanceof Condition) {
                $fields[] = $rule->field;
            } else {
                array_push($fields, ...$rule->fields());
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * The same set with several field keys replaced at once, so swapping two keys works.
     *
     * @param array<string, string> $renamed new key by old key
     */
    public function withRenamedFields(array $renamed): self
    {
        return new self(array_map(
            static fn (Condition|self $rule): Condition|self => $rule instanceof Condition
                ? (isset($renamed[$rule->field]) ? new Condition($renamed[$rule->field], $rule->operator, $rule->value) : $rule)
                : $rule->withRenamedFields($renamed),
            $this->rules,
        ), $this->match);
    }

    /**
     * The same set with every reference to a field key replaced, for when a field is renamed.
     */
    public function withRenamedField(string $from, string $to): self
    {
        return new self(array_map(
            static fn (Condition|self $rule): Condition|self => $rule instanceof Condition
                ? ($rule->field === $from ? new Condition($to, $rule->operator, $rule->value) : $rule)
                : $rule->withRenamedField($from, $to),
            $this->rules,
        ), $this->match);
    }
}
