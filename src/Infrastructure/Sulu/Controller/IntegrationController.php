<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller;

use Eekes\Sulu\FormBuilderBundle\Application\Integration\DynamicFieldsIntegrationInterface;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationAction;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\IntegrationRegistry;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\Setting;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\TargetField;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdmin;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\PlaceholderText;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the flow modal offers: the enabled integrations with their actions, fields and settings,
 * and the choices of a setting and the extra fields that come from the remote service.
 */
final class IntegrationController extends AbstractAdminController
{
    public const string LIST_ROUTE = 'eekes_form_builder.get_integrations';
    public const string RESOURCE_KEY = 'eekes_form_integrations';

    public function __construct(
        private readonly IntegrationRegistry $integrationRegistry,
        private readonly TranslatorInterface $translator,
        private readonly ?SecurityCheckerInterface $securityChecker = null,
    ) {
    }

    #[Route(path: '/form-integrations', name: self::LIST_ROUTE, methods: ['GET'])]
    public function list(Request $request): Response
    {
        $locale = $request->query->getString('userLocale') ?: null;
        $integrations = [];

        foreach ($this->integrationRegistry->enabled() as $metadata) {
            $status = $this->integrationRegistry->status($metadata->key);
            $integration = $this->integrationRegistry->find($metadata->key);
            $integrations[] = [
                'key' => $metadata->key,
                'title' => $this->trans($metadata->title, $locale),
                'icon' => $metadata->icon,
                // So the flow modal can warn before a flow is set up for an integration that
                // cannot run yet (not configured, not connected).
                'status' => [
                    'ready' => $status->isReady(),
                    'message' => $status->message !== null ? $this->trans($status->message, $locale) : null,
                ],
                'actions' => array_map(fn (IntegrationAction $action): array => [
                    'key' => $action->key,
                    'title' => $this->trans($action->title, $locale),
                    'description' => $action->description !== null ? $this->trans($action->description, $locale) : null,
                    'allowCustomFields' => $action->allowCustomFields,
                    // Whether the modal asks for more fields once the settings are picked.
                    'dynamicFields' => $integration instanceof DynamicFieldsIntegrationInterface,
                    'fields' => array_map(fn (TargetField $field): array => $this->field($field, $locale), $action->fields),
                    'settings' => array_map(fn (Setting $setting): array => [
                        'key' => $setting->key,
                        'label' => $this->trans($setting->label, $locale),
                        'type' => $setting->type,
                        'required' => $setting->required,
                        'options' => array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $this->trans($option['label'], $locale)], $setting->options),
                        'dynamicOptions' => $setting->dynamicOptions,
                        'help' => $setting->help !== null ? $this->trans($setting->help, $locale) : null,
                        'default' => $setting->default,
                    ], $action->settings),
                ], $integration?->getActions() ?? []),
            ];
        }

        return $this->json([
            '_embedded' => [self::RESOURCE_KEY => $integrations],
            'total' => \count($integrations),
            // Whether the user can open Settings → Form builder integrations to fix what is not ready.
            'mayManage' => $this->securityChecker?->hasPermission(FormAdmin::INTEGRATIONS_SECURITY_CONTEXT, PermissionTypes::VIEW) ?? false,
        ]);
    }

    /**
     * A remote service that is down or refuses the credentials is answered with 502 and its
     * message, so the editor sees why a list stays empty.
     */
    #[Route(path: '/form-integrations/{integration}/options', name: 'eekes_form_builder.get_integration_options', methods: ['GET'])]
    public function options(string $integration, Request $request): Response
    {
        if (!$this->integrationRegistry->isEnabled($integration)) {
            throw new NotFoundHttpException(\sprintf('The integration "%s" is not enabled.', $integration));
        }

        try {
            $options = $this->integrationRegistry->get($integration)->getOptions(
                $request->query->getString('action'),
                $request->query->getString('setting'),
            );
        } catch (\Throwable $exception) {
            return $this->json(['title' => $exception->getMessage(), 'detail' => $exception->getMessage(), 'options' => []], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['options' => $options]);
    }

    /**
     * The fields an action gets from the remote service for the settings the editor picked, as
     * a JSON object in the query parameter "settings". An integration without them answers an
     * empty list; a service that refuses is answered with 502 and its message, like the options.
     */
    #[Route(path: '/form-integrations/{integration}/fields', name: 'eekes_form_builder.get_integration_fields', methods: ['GET'])]
    public function fields(string $integration, Request $request): Response
    {
        if (!$this->integrationRegistry->isEnabled($integration)) {
            throw new NotFoundHttpException(\sprintf('The integration "%s" is not enabled.', $integration));
        }

        $service = $this->integrationRegistry->get($integration);
        $actionKey = $request->query->getString('action');
        $action = $this->integrationRegistry->action($integration, $actionKey);

        if (!$service instanceof DynamicFieldsIntegrationInterface || $action === null) {
            return $this->json(['fields' => []]);
        }

        try {
            $settings = json_decode($request->query->getString('settings') ?: '{}', true, 16, \JSON_THROW_ON_ERROR);
            $fields = $service->getDynamicFields($actionKey, \is_array($settings) ? self::stringKeys($settings) : []);
        } catch (\JsonException) {
            return $this->json(['title' => 'The settings are not valid JSON.', 'detail' => 'The settings are not valid JSON.', 'fields' => []], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->json(['title' => $exception->getMessage(), 'detail' => $exception->getMessage(), 'fields' => []], Response::HTTP_BAD_GATEWAY);
        }

        $locale = $request->query->getString('userLocale') ?: null;
        $result = [];
        $seen = array_map(static fn (TargetField $field): string => $field->key, $action->fields);

        foreach ($fields as $field) {
            if (!\in_array($field->key, $seen, true)) {
                $seen[] = $field->key;
                $result[] = $this->field($field, $locale);
            }
        }

        return $this->json(['fields' => $result]);
    }

    /**
     * @return array{key: string, label: string, required: bool, type: string, help: null|string}
     */
    private function field(TargetField $field, ?string $locale): array
    {
        return [
            'key' => $field->key,
            'label' => $this->trans($field->label, $locale),
            'required' => $field->required,
            'type' => $field->type,
            'help' => $field->help !== null ? $this->trans($field->help, $locale) : null,
        ];
    }

    /**
     * Only the named settings: a JSON list would give numeric keys.
     *
     * @param array<mixed> $settings
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $settings): array
    {
        return array_filter($settings, 'is_string', \ARRAY_FILTER_USE_KEY);
    }

    private function trans(string $text, ?string $locale): string
    {
        return PlaceholderText::expand($this->translator->trans($text, [], 'admin', $locale));
    }
}
