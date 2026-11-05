<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType;

use Eekes\Sulu\FormBuilderBundle\Application\Form\Type\StaticContentType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;

/**
 * A field that is only layout: it takes a place in the grid but collects nothing, so it never
 * shows up in a submission, an e-mail or a condition.
 */
abstract class AbstractContentFieldType implements FieldTypeInterface
{
    public function getFormType(FieldDefinition $field): string
    {
        return StaticContentType::class;
    }

    public function getFormOptions(FieldDefinition $field): array
    {
        return ['html' => $this->getHtml($field)];
    }

    public function isInput(): bool
    {
        return false;
    }

    public function getLocalizedOptions(): array
    {
        return [];
    }

    public function normalizeValue(mixed $data, FieldDefinition $field): mixed
    {
        return null;
    }

    public function displayValue(mixed $value, FieldDefinition $field, string $locale): string
    {
        return '';
    }

    abstract protected function getHtml(FieldDefinition $field): string;
}
