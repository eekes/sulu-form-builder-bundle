<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Application\Integration;

/**
 * An integration whose fields depend on the remote account, e.g. the merge fields of a Mailchimp
 * audience or the custom fields of a CRM. The flow modal asks for them once the editor has
 * picked the settings, and shows them as mapping rows next to the fields the action declares,
 * so editors pick a field instead of typing its key.
 *
 * Only the admin asks: a run sends whatever the flow maps, by key, without calling this. A
 * dynamic field marked required is shown as such but does not block saving, since that would
 * mean calling the remote service on every save; the service's own refusal ends up in the run
 * log. Declare the action with allowCustomFields, so the keys are accepted when the service
 * cannot be reached and the editor adds them by hand.
 */
interface DynamicFieldsIntegrationInterface extends IntegrationInterface
{
    /**
     * Called when the flow modal opens and whenever the editor changes a setting. A field with
     * the key of a declared field is ignored.
     *
     * @param array<string, mixed> $settings the settings of the flow as the editor has them now,
     *                                       possibly incomplete
     *
     * @return list<TargetField>
     */
    public function getDynamicFields(string $action, array $settings): array;
}
