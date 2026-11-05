<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\FieldType\Type;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AbstractFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FieldDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;

/**
 * An upload. By the time a value reaches normalizeValue() the upload has already been moved to
 * the upload storage, so it arrives as one or more StoredFile objects; the stored value is the
 * list of original file names, and the files themselves travel with the SubmittedValue.
 */
#[AsFormFieldType(key: 'file', title: 'eekes_form_builder.field_type.file', icon: 'su-upload', group: 'input', position: 80)]
final class FileFieldType extends AbstractFieldType
{
    public function getFormType(FieldDefinition $field): string
    {
        return FileType::class;
    }

    protected function getTypeOptions(FieldDefinition $field): array
    {
        $extensions = $this->extensions($field);

        return [
            'multiple' => $field->boolOption('multiple'),
            'attr' => $extensions === [] ? [] : ['accept' => implode(',', array_map(static fn (string $extension): string => '.'.$extension, $extensions))],
        ];
    }

    protected function getConstraints(FieldDefinition $field): array
    {
        $maxSize = $field->intOption('maxSize');
        $extensions = $this->extensions($field);
        $file = new File(
            maxSize: $maxSize !== null && $maxSize > 0 ? $maxSize.'M' : null,
            extensions: $extensions === [] ? [] : $extensions,
        );

        if (!$field->boolOption('multiple')) {
            return [...parent::getConstraints($field), $file];
        }

        $constraints = [new All(constraints: [$file])];

        if ($field->required) {
            $constraints[] = new Count(min: 1);
        }

        return $constraints;
    }

    public function normalizeValue(mixed $data, FieldDefinition $field): mixed
    {
        $files = array_filter(\is_array($data) ? $data : [$data], static fn (mixed $file): bool => $file instanceof StoredFile);

        return array_values(array_map(static fn (StoredFile $file): string => $file->originalName, $files));
    }

    /**
     * @return list<string>
     */
    private function extensions(FieldDefinition $field): array
    {
        $extensions = array_map(
            static fn (string $extension): string => strtolower(ltrim(trim($extension), '.')),
            explode(',', $field->stringOption('extensions') ?? ''),
        );

        return array_values(array_filter($extensions, static fn (string $extension): bool => $extension !== ''));
    }
}
