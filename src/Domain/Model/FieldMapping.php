<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * Where one field an integration needs gets its value: the answer to a field of the form, or a
 * fixed value (which may hold {{ placeholders }}, like "Website form: {{ form_title }}").
 */
final readonly class FieldMapping
{
    public const string SOURCE_FIELD = 'field';
    public const string SOURCE_VALUE = 'value';

    private function __construct(
        public string $source,
        public string $value,
    ) {
    }

    public static function field(string $fieldKey): self
    {
        return new self(self::SOURCE_FIELD, $fieldKey);
    }

    public static function value(string $value): self
    {
        return new self(self::SOURCE_VALUE, $value);
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)) {
            return null;
        }

        $source = $data['source'] ?? null;

        if ($source === self::SOURCE_FIELD && \is_string($data['field'] ?? null) && $data['field'] !== '') {
            return self::field($data['field']);
        }

        if ($source === self::SOURCE_VALUE && is_scalar($data['value'] ?? null) && (string) $data['value'] !== '') {
            return self::value((string) $data['value']);
        }

        return null;
    }

    /**
     * @return array{source: string, field?: string, value?: string}
     */
    public function toArray(): array
    {
        return $this->source === self::SOURCE_FIELD
            ? ['source' => self::SOURCE_FIELD, 'field' => $this->value]
            : ['source' => self::SOURCE_VALUE, 'value' => $this->value];
    }

    public function isField(): bool
    {
        return $this->source === self::SOURCE_FIELD;
    }
}
