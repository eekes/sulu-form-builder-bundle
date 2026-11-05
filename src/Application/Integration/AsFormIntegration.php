<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * Registers a class as an integration editors can use in the "Integrations" tab of a form - once
 * a developer has enabled it under eekes_sulu_form_builder.integrations.enabled.
 *
 * ```php
 * #[AsFormIntegration(key: 'acme_erp', title: 'Acme ERP', icon: 'su-industry')]
 * final class AcmeErpIntegration implements IntegrationInterface { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsFormIntegration
{
    public const string TAG = 'eekes_form_builder.integration';

    /**
     * @param string $title translation key in the "admin" domain, or literal text
     * @param string $icon a Sulu icon name
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $icon = 'su-share',
    ) {
    }
}
