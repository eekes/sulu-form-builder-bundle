<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

/**
 * Formats a date or time in the notation of a language, falling back to a fixed format without
 * the intl extension.
 *
 * @internal
 */
final class DateFormat
{
    /**
     * @param 'medium'|'none'|'short' $date
     * @param 'medium'|'none'|'short' $time
     */
    public static function format(\DateTimeInterface $value, string $locale, string $date, string $time, string $fallback): string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return $value->format($fallback);
        }

        $formatted = (new \IntlDateFormatter($locale, self::style($date), self::style($time), $value->getTimezone()))->format($value);

        return \is_string($formatted) ? $formatted : $value->format($fallback);
    }

    private static function style(string $style): int
    {
        return match ($style) {
            'short' => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            default => \IntlDateFormatter::NONE,
        };
    }
}
