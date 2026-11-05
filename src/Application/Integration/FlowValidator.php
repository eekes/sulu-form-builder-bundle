<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;

/**
 * What keeps a flow from being saved: an integration that is not enabled, a required setting
 * left empty, or a field the action needs that is not mapped.
 */
final readonly class FlowValidator
{
    public function __construct(
        private IntegrationRegistry $integrationRegistry,
    ) {
    }

    /**
     * @return list<string> one message per problem; empty when the flow can be saved
     */
    public function validate(Flow $flow): array
    {
        if (!$this->integrationRegistry->isEnabled($flow->integration)) {
            return [\sprintf('The integration "%s" is not enabled.', $flow->integration)];
        }

        $action = $this->integrationRegistry->action($flow->integration, $flow->action);

        if ($action === null) {
            return [\sprintf('The integration "%s" has no action "%s".', $flow->integration, $flow->action)];
        }

        $problems = [];

        foreach ($action->settings as $setting) {
            $value = $flow->settings[$setting->key] ?? null;

            if ($setting->required && ($value === null || $value === '' || $value === false)) {
                $problems[] = \sprintf('The setting "%s" is required.', $setting->key);
            }
        }

        $mapping = $flow->mapping();

        foreach ($action->fields as $field) {
            if ($field->required && !isset($mapping[$field->key])) {
                $problems[] = \sprintf('The field "%s" is required: map it to an answer or a fixed value.', $field->key);
            }
        }

        return $problems;
    }
}
