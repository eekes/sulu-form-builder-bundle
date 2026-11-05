<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Form;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The data array of a flow, and how to read it.
 *
 * A flow keeps one array per step under the step key, the current step under STEP_KEY and the
 * files of earlier steps under FILES_KEY (see UploadAwareDataStorage). Conditions and placeholders
 * want one flat list of answers instead, which is what values() gives.
 */
final class FlowData
{
    public const string STEP_KEY = '_step';
    public const string FILES_KEY = '_files';

    /**
     * Prefix of the checkbox next to a file field that already has a file: "_keep_cv" ticked keeps
     * the upload of an earlier visit to the step, unticked lets it go. Never an answer.
     */
    public const string KEEP_PREFIX = '_keep_';

    /**
     * @return array<string, mixed>
     */
    public static function stringKeyed(mixed $value): array
    {
        $result = [];

        foreach (\is_array($value) ? $value : [] as $key => $item) {
            if (\is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed> answers by field key, over all steps
     */
    public static function values(array $data, ?string $exceptStep = null): array
    {
        $values = [];

        foreach ($data as $step => $fields) {
            if (!\is_string($step) || str_starts_with($step, '_') || $step === $exceptStep || !\is_array($fields)) {
                continue;
            }

            foreach ($fields as $key => $value) {
                // A field key starts with a letter; what starts with an underscore is the flow's own.
                if (\is_string($key) && !str_starts_with($key, '_')) {
                    $values[$key] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * The files already uploaded on a step, by field key (see UploadAwareDataStorage).
     *
     * @param array<mixed> $data
     *
     * @return array<string, list<StoredFile>>
     */
    public static function storedFiles(array $data, string $step): array
    {
        $all = $data[self::FILES_KEY] ?? null;
        $files = \is_array($all) ? ($all[$step] ?? null) : null;
        $result = [];

        foreach (\is_array($files) ? $files : [] as $key => $stored) {
            $stored = array_values(array_filter(\is_array($stored) ? $stored : [$stored], static fn (mixed $file): bool => $file instanceof StoredFile));

            if (\is_string($key) && $stored !== []) {
                $result[$key] = $stored;
            }
        }

        return $result;
    }

    /**
     * Whether a submitted value holds a new upload.
     */
    public static function hasUpload(mixed $value): bool
    {
        foreach (\is_array($value) ? $value : [$value] as $item) {
            if ($item instanceof UploadedFile) {
                return true;
            }
        }

        return false;
    }
}
