<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * How many of the twelve grid columns a field spans, per screen size.
 *
 * Mobile defaults to the full width: a field squeezed next to another on a phone is almost never
 * what an editor means when they put two fields side by side on a desktop.
 */
final readonly class ColumnWidths
{
    public const int COLUMNS = 12;

    public function __construct(
        public int $desktop = self::COLUMNS,
        public int $tablet = self::COLUMNS,
        public int $mobile = self::COLUMNS,
    ) {
        foreach (['desktop' => $desktop, 'tablet' => $tablet, 'mobile' => $mobile] as $name => $width) {
            if ($width < 1 || $width > self::COLUMNS) {
                throw new \InvalidArgumentException(\sprintf('The %s width must be between 1 and %d, %d given.', $name, self::COLUMNS, $width));
            }
        }
    }

    /**
     * Lenient on purpose: the value comes from JSON an editor produced, so anything out of range
     * is clamped instead of making the whole form unreadable.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $desktop = self::clamp($data['desktop'] ?? null, self::COLUMNS);

        return new self(
            $desktop,
            self::clamp($data['tablet'] ?? null, $desktop),
            self::clamp($data['mobile'] ?? null, self::COLUMNS),
        );
    }

    /**
     * @return array{desktop: int, tablet: int, mobile: int}
     */
    public function toArray(): array
    {
        return [
            'desktop' => $this->desktop,
            'tablet' => $this->tablet,
            'mobile' => $this->mobile,
        ];
    }

    private static function clamp(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max(1, min(self::COLUMNS, (int) $value));
    }
}
