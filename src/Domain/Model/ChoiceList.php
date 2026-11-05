<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * The choices of a field, the same in every language except for their labels.
 *
 * A choice is stored per language as {label, value, ...}, because the admin edits one language at
 * a time. But conditions, notification conditions and integration flows compare against the
 * value, so a value that differs per language would silently break them for some visitors. These
 * functions keep the values, their order and the set of choices shared, and only the labels
 * translated.
 *
 * The same holds for any list of items with a label: the sub-fields of a repeater are identified
 * by their "key" instead of a "value". Pass that name as $identifier, or let identifier() find it.
 */
final class ChoiceList
{
    public const string VALUE = 'value';
    public const string KEY = 'key';

    /**
     * @phpstan-assert-if-true list<array<mixed>> $value
     */
    public static function isChoiceList(mixed $value, string $identifier = self::VALUE): bool
    {
        if (!\is_array($value) || $value === [] || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!\is_array($item) || !\array_key_exists($identifier, $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What identifies the items of a list: "value" for choices, "key" for sub-fields, null when
     * the value is not such a list.
     */
    public static function identifier(mixed $value): ?string
    {
        foreach ([self::VALUE, self::KEY] as $identifier) {
            if (self::isChoiceList($value, $identifier)) {
                return $identifier;
            }
        }

        return null;
    }

    /**
     * Another language after the list was edited in one language: the values, the order and the
     * set of choices of the edited list, with the labels the other language already had. A value
     * that was changed is recognised by its position in the list before the edit; a new choice
     * starts with the label it was given.
     *
     * @param list<array<mixed>> $edited the list as just saved
     * @param list<array<mixed>> $before the list of the edited language before it was saved
     * @param list<array<mixed>> $other the list of the other language
     *
     * @return list<array<mixed>>
     */
    public static function follow(array $edited, array $before, array $other, string $identifier = self::VALUE): array
    {
        $labels = self::labelsByValue($other, $identifier);
        $editedValues = array_map(static fn (array $item): string => self::value($item, $identifier), $edited);
        $result = [];

        foreach ($edited as $index => $item) {
            $value = self::value($item, $identifier);
            $label = $labels[$value] ?? null;

            // Renamed: the value that was at this position is gone from the list.
            $previous = isset($before[$index]) ? self::value($before[$index], $identifier) : null;

            if ($label === null && $previous !== null && !\in_array($previous, $editedValues, true)) {
                $label = $labels[$previous] ?? null;
            }

            $result[] = $label === null ? $item : ['label' => $label] + $item;
        }

        return array_map(static fn (array $item): array => self::ordered($item), $result);
    }

    /**
     * A language as it is read: the values of the canonical list, with this language's labels. A
     * list with as many choices is a translated copy and is matched by position; otherwise by
     * value, and a choice this language lacks shows the canonical label.
     *
     * @param list<array<mixed>> $own
     * @param list<array<mixed>> $canonical
     *
     * @return list<array<mixed>>
     */
    public static function withSharedValues(array $own, array $canonical, string $identifier = self::VALUE): array
    {
        $labels = self::labelsByValue($own, $identifier);
        $result = [];

        foreach ($canonical as $index => $item) {
            $label = \count($own) === \count($canonical) ? ($own[$index]['label'] ?? null) : ($labels[self::value($item, $identifier)] ?? null);
            $result[] = self::ordered($label === null ? $item : ['label' => $label] + $item);
        }

        return $result;
    }

    /**
     * @param list<array<mixed>> $list
     *
     * @return array<string, mixed>
     */
    private static function labelsByValue(array $list, string $identifier): array
    {
        $labels = [];

        foreach ($list as $item) {
            if (\array_key_exists('label', $item)) {
                $labels[self::value($item, $identifier)] = $item['label'];
            }
        }

        return $labels;
    }

    /**
     * @param array<mixed> $item
     */
    private static function value(array $item, string $identifier): string
    {
        return is_scalar($item[$identifier] ?? null) ? (string) $item[$identifier] : '';
    }

    /**
     * The keys in the order the admin stores them, so a list that did not change compares equal.
     *
     * @param array<mixed> $item
     *
     * @return array<mixed>
     */
    private static function ordered(array $item): array
    {
        $ordered = [];

        foreach (['type', 'label', 'value'] as $key) {
            if (\array_key_exists($key, $item)) {
                $ordered[$key] = $item[$key];
            }
        }

        return $ordered + $item;
    }
}
