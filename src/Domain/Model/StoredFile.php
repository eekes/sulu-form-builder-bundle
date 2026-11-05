<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * A file a visitor uploaded, after it was moved out of PHP's temporary directory.
 *
 * $path is relative to the upload storage, never absolute, so moving the storage directory does
 * not break the submissions that point into it.
 */
final readonly class StoredFile
{
    public function __construct(
        public string $path,
        public string $originalName,
        public ?string $mimeType = null,
        public ?int $size = null,
    ) {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || !\is_string($data['path'] ?? null) || !\is_string($data['originalName'] ?? null)) {
            return null;
        }

        return new self(
            $data['path'],
            $data['originalName'],
            \is_string($data['mimeType'] ?? null) ? $data['mimeType'] : null,
            \is_int($data['size'] ?? null) ? $data['size'] : null,
        );
    }

    /**
     * @return array{path: string, originalName: string, mimeType: null|string, size: null|int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'originalName' => $this->originalName,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
        ];
    }
}
