<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

final readonly class IntegrationMetadata
{
    public function __construct(
        public string $key,
        public string $title,
        public string $icon,
    ) {
    }
}
