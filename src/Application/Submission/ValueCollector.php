<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Submission;

use Eekes\Sulu\FormBuilderBundle\Application\Condition\ConditionEvaluator;
use Eekes\Sulu\FormBuilderBundle\Application\FieldType\FieldTypeRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Form\FlowData;
use Eekes\Sulu\FormBuilderBundle\Application\Upload\UploadStorageInterface;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\FormDefinition;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\SubmittedValue;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Turns the data of a finished flow into the answers of a submission.
 *
 * Conditions are evaluated once more on the complete data: a visitor may have gone back and
 * changed an answer that hides a field they filled in earlier, and an answer to a field the
 * visitor can no longer see must not end up in the submission.
 */
final readonly class ValueCollector
{
    public function __construct(
        private FieldTypeRegistry $fieldTypeRegistry,
        private ConditionEvaluator $conditionEvaluator,
        private UploadStorageInterface $uploadStorage,
    ) {
    }

    /**
     * @param array<mixed> $data the data of the flow
     *
     * @return list<SubmittedValue>
     */
    public function collect(FormDefinition $definition, array $data): array
    {
        $flat = FlowData::values($data);
        $files = \is_array($data[FlowData::FILES_KEY] ?? null) ? $data[FlowData::FILES_KEY] : [];
        $values = [];

        foreach ($definition->steps as $step) {
            if (!$this->conditionEvaluator->isSetMet($step->visibleIf, $flat)) {
                continue;
            }

            $stepData = \is_array($data[$step->key] ?? null) ? $data[$step->key] : [];
            $stepFiles = \is_array($files[$step->key] ?? null) ? $files[$step->key] : [];

            foreach ($step->fields as $field) {
                if (!$this->fieldTypeRegistry->has($field->type)) {
                    continue;
                }

                $fieldType = $this->fieldTypeRegistry->get($field->type);

                if (!$fieldType->isInput()) {
                    continue;
                }

                if (!$this->conditionEvaluator->isSetMet($field->visibleIf, $flat)) {
                    continue;
                }

                $raw = $this->storeUploads($stepData[$field->key] ?? null);
                $storedFiles = self::storedFiles($raw);
                $earlier = self::storedFiles($stepFiles[$field->key] ?? null);

                // An upload of an earlier visit to the step was moved out of the data by
                // UploadAwareDataStorage. It counts unless a new upload replaced it or the visitor
                // unticked "keep" on the step that finished the form.
                if ($storedFiles !== [] || ($stepData[FlowData::KEEP_PREFIX.$field->key] ?? true) === false) {
                    foreach ($earlier as $file) {
                        $this->uploadStorage->delete($file);
                    }
                } elseif ($earlier !== []) {
                    $storedFiles = $earlier;
                    $raw = $field->boolOption('multiple') ? $storedFiles : $storedFiles[0];
                }

                $value = $fieldType->normalizeValue($raw, $field);

                $values[] = new SubmittedValue(
                    key: $field->key,
                    label: strip_tags($field->displayLabel()),
                    type: $field->type,
                    value: $value,
                    display: $fieldType->displayValue($value, $field, $definition->locale),
                    showInList: $field->showInList,
                    files: $storedFiles,
                );
            }
        }

        return $values;
    }

    private function storeUploads(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return $this->uploadStorage->store($value);
        }

        if (\is_array($value)) {
            return array_map(fn (mixed $item): mixed => $item instanceof UploadedFile ? $this->uploadStorage->store($item) : $item, $value);
        }

        return $value;
    }

    /**
     * @return list<StoredFile>
     */
    private static function storedFiles(mixed $value): array
    {
        $values = \is_array($value) ? $value : [$value];

        return array_values(array_filter($values, static fn (mixed $file): bool => $file instanceof StoredFile));
    }
}
