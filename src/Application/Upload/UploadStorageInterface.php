<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Upload;

use Eekes\Sulu\FormBuilderBundle\Domain\Model\StoredFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where uploaded files go. They are personal data, so the default keeps them outside the public
 * directory; the admin streams them through a secured route. Replace the service to put them in
 * object storage instead.
 */
interface UploadStorageInterface
{
    public function store(UploadedFile $file): StoredFile;

    /**
     * A local path the mailer can attach and the admin can stream.
     */
    public function absolutePath(StoredFile $file): string;

    public function delete(StoredFile $file): void;
}
