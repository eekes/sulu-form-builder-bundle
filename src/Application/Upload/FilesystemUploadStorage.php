<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Upload;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores uploads in a private directory, one random name per file grouped by month.
 *
 * The original name is only kept as metadata: a visitor chooses it, so it never becomes part of
 * a path on disk.
 */
final readonly class FilesystemUploadStorage implements UploadStorageInterface
{
    public function __construct(
        private string $directory,
    ) {
    }

    public function store(UploadedFile $file): StoredFile
    {
        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.'.$extension : '';
        $relativeDirectory = date('Y/m');
        $name = bin2hex(random_bytes(16)).$extension;
        $size = $file->getSize();
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType();

        $file->move($this->directory.'/'.$relativeDirectory, $name);

        return new StoredFile(
            $relativeDirectory.'/'.$name,
            $file->getClientOriginalName(),
            $mimeType,
            \is_int($size) ? $size : null,
        );
    }

    public function absolutePath(StoredFile $file): string
    {
        // The path comes from the database; a value that tries to leave the directory is refused.
        if (str_contains($file->path, '..') || str_starts_with($file->path, '/')) {
            throw new \InvalidArgumentException(\sprintf('The path "%s" is not inside the upload directory.', $file->path));
        }

        return $this->directory.'/'.$file->path;
    }

    public function delete(StoredFile $file): void
    {
        $path = $this->absolutePath($file);

        if (is_file($path)) {
            unlink($path);
        }
    }
}
