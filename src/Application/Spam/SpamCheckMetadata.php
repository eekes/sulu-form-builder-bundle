<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Spam;

final readonly class SpamCheckMetadata
{
    public function __construct(
        public string $key,
        public string $title,
        public ?string $description,
        public bool $enabledByDefault,
    ) {
    }
}
