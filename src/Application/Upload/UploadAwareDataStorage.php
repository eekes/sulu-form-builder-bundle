<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Upload;

use Eekes\Sulu\FormBuilderBundle\Application\Form\FlowData;
use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Symfony\Component\Form\Flow\DataStorage\DataStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Keeps the uploads of earlier steps alive between requests.
 *
 * A flow stores its data in the session after every step, but an uploaded file only exists in
 * PHP's temporary directory until the request ends. So before the data is stored, every upload is
 * moved to the upload storage and remembered under FlowData::FILES_KEY - and the field itself is
 * emptied, because a FileType only accepts an uploaded file as its data.
 *
 * Back on such a step, a new upload replaces the earlier one, and an unticked "keep" checkbox
 * (FlowData::KEEP_PREFIX) lets it go; either way the file that is no longer needed is deleted.
 */
final readonly class UploadAwareDataStorage implements DataStorageInterface
{
    public function __construct(
        private DataStorageInterface $inner,
        private UploadStorageInterface $uploadStorage,
    ) {
    }

    /**
     * @param array<mixed>|object $data
     */
    public function save(object|array $data): void
    {
        $this->inner->save(\is_array($data) ? $this->storeUploads($data) : $data);
    }

    /**
     * @param null|array<mixed>|object $default
     *
     * @return null|array<mixed>|object
     */
    public function load(object|array|null $default = null): object|array|null
    {
        return $this->inner->load($default);
    }

    public function clear(): void
    {
        $this->inner->clear();
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function storeUploads(array $data): array
    {
        $files = \is_array($data[FlowData::FILES_KEY] ?? null) ? $data[FlowData::FILES_KEY] : [];

        foreach ($data as $step => $fields) {
            if (!\is_string($step) || str_starts_with($step, '_') || !\is_array($fields)) {
                continue;
            }

            $stepFiles = \is_array($files[$step] ?? null) ? $files[$step] : [];

            foreach ($fields as $key => $value) {
                if (!\is_string($key)) {
                    continue;
                }

                if (str_starts_with($key, FlowData::KEEP_PREFIX)) {
                    $field = substr($key, \strlen(FlowData::KEEP_PREFIX));
                    unset($fields[$key]);

                    if ($value === false && !FlowData::hasUpload($fields[$field] ?? null)) {
                        $this->delete($stepFiles[$field] ?? null);
                        unset($stepFiles[$field]);
                    }

                    continue;
                }

                $uploads = array_filter(\is_array($value) ? $value : [$value], static fn (mixed $file): bool => $file instanceof UploadedFile);

                if ($uploads === []) {
                    continue;
                }

                $this->delete($stepFiles[$key] ?? null);
                $stepFiles[$key] = array_values(array_map(fn (UploadedFile $file): StoredFile => $this->uploadStorage->store($file), $uploads));
                $fields[$key] = null;
            }

            $data[$step] = $fields;

            if ($stepFiles !== []) {
                $files[$step] = $stepFiles;
            } else {
                unset($files[$step]);
            }
        }

        if ($files !== []) {
            $data[FlowData::FILES_KEY] = $files;
        } else {
            unset($data[FlowData::FILES_KEY]);
        }

        return $data;
    }

    private function delete(mixed $stored): void
    {
        foreach (\is_array($stored) ? $stored : [$stored] as $file) {
            if ($file instanceof StoredFile) {
                $this->uploadStorage->delete($file);
            }
        }
    }
}
