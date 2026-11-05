<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Infrastructure\Symfony\DependencyInjection;

use Eekes\Sulu\FormBuilderBundle\Application\FieldType\AsFormFieldType;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\AsFormIntegration;
use Eekes\Sulu\FormBuilderBundle\Application\Integration\Webhook\WebhookIntegration;
use Eekes\Sulu\FormBuilderBundle\Application\Spam\AsSpamCheck;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Flow;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Form;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Notification;
use Eekes\Sulu\FormBuilderBundle\Domain\Entity\Submission;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\FieldTypeController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\FlowController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\FormController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\IntegrationConnectionController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\IntegrationController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\IntegrationRunController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\NotificationController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\NotificationDeliveryController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\SpamController;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Controller\SubmissionController;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Loads the core services always, and the Sulu admin and Sulu content integrations only when
 * those bundles are registered - which keeps the website part usable on its own.
 */
final class EekesSuluFormBuilderExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        foreach (self::flatten($config, '', ['integrations.webhook.endpoints', 'integrations.retry.integrations', 'notifications.templates', 'notifications.branding']) as $name => $value) {
            $container->setParameter(Configuration::ROOT_NODE.'.'.$name, $value);
        }

        $container->registerAttributeForAutoconfiguration(
            AsFormIntegration::class,
            static function (ChildDefinition $definition, AsFormIntegration $attribute): void {
                $definition->addTag(AsFormIntegration::TAG, ['key' => $attribute->key, 'title' => $attribute->title, 'icon' => $attribute->icon]);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsSpamCheck::class,
            static function (ChildDefinition $definition, AsSpamCheck $attribute): void {
                $definition->addTag(AsSpamCheck::TAG, [
                    'key' => $attribute->key,
                    'title' => $attribute->title,
                    'description' => $attribute->description,
                    'enabledByDefault' => $attribute->enabledByDefault,
                ]);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsFormFieldType::class,
            static function (ChildDefinition $definition, AsFormFieldType $attribute): void {
                $definition->addTag(AsFormFieldType::TAG, $attribute->toTagAttributes());
            },
        );

        $loader = new PhpFileLoader($container, new FileLocator(self::bundleRoot().'/config'));
        $loader->load('services.php');

        $httpClient = $container->getParameter(Configuration::ROOT_NODE.'.integrations.webhook.http_client');
        $container->getDefinition(WebhookIntegration::class)->setArgument('$httpClient', new Reference(\is_string($httpClient) ? $httpClient : 'http_client'));

        if (self::hasBundle($container, 'SuluAdminBundle')) {
            $loader->load('services_sulu_admin.php');
        }

        if (interface_exists(MessageBusInterface::class)) {
            $loader->load('services_messenger.php');
        }

        if (self::hasBundle($container, 'SuluContentBundle')) {
            $loader->load('services_sulu_content.php');
        }

        if (self::hasBundle($container, 'TwigComponentBundle')) {
            $loader->load('services_twig_component.php');
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'EekesSuluFormBuilder' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => self::bundleRoot().'/src/Domain/Entity',
                            'prefix' => 'Eekes\Sulu\FormBuilderBundle\Domain\Entity',
                            'alias' => 'EekesSuluFormBuilder',
                        ],
                    ],
                ],
            ]);
        }

        // Declaring the channel is what makes the monolog.logger tag in services.php resolve.
        if ($container->hasExtension('monolog')) {
            $container->prependExtensionConfig('monolog', ['channels' => ['form_builder']]);
        }

        if ($container->hasExtension('sulu_admin')) {
            $container->prependExtensionConfig('sulu_admin', [
                'lists' => [
                    'directories' => [self::bundleRoot().'/config/lists'],
                ],
                'forms' => [
                    'directories' => [self::bundleRoot().'/config/forms'],
                ],
                'resources' => [
                    Form::RESOURCE_KEY => [
                        'routes' => [
                            'list' => FormController::LIST_ROUTE,
                            'detail' => FormController::DETAIL_ROUTE,
                        ],
                    ],
                    Notification::RESOURCE_KEY => [
                        'routes' => [
                            'list' => NotificationController::LIST_ROUTE,
                            'detail' => NotificationController::DETAIL_ROUTE,
                        ],
                    ],
                    FieldTypeController::RESOURCE_KEY => [
                        'routes' => [
                            'list' => FieldTypeController::ROUTE,
                            'detail' => FieldTypeController::ROUTE,
                        ],
                    ],
                    Flow::RESOURCE_KEY => [
                        'routes' => [
                            'list' => FlowController::LIST_ROUTE,
                            'detail' => FlowController::DETAIL_ROUTE,
                        ],
                    ],
                    IntegrationController::RESOURCE_KEY => [
                        'routes' => [
                            'list' => IntegrationController::LIST_ROUTE,
                            'detail' => IntegrationController::LIST_ROUTE,
                        ],
                    ],
                    // Only posted to, to retry a run: ResourceRequester needs a detail route for that.
                    IntegrationConnectionController::RESOURCE_KEY => [
                        'routes' => [
                            'list' => IntegrationConnectionController::LIST_ROUTE,
                            'detail' => 'eekes_form_builder.post_integration_connection_trigger',
                        ],
                    ],
                    IntegrationRunController::RESOURCE_KEY => [
                        'routes' => [
                            'list' => IntegrationRunController::ROUTE,
                            'detail' => IntegrationRunController::ROUTE,
                        ],
                    ],
                    Submission::RESOURCE_KEY => [
                        'routes' => [
                            'list' => SubmissionController::LIST_ROUTE,
                            'detail' => SubmissionController::DETAIL_ROUTE,
                        ],
                    ],
                    NotificationDeliveryController::RESOURCE_KEY => [
                        'routes' => [
                            'list' => NotificationDeliveryController::ROUTE,
                            'detail' => NotificationDeliveryController::ROUTE,
                        ],
                    ],
                    'eekes_form_notification_previews' => [
                        'routes' => [
                            'list' => NotificationController::PREVIEW_ROUTE,
                            'detail' => NotificationController::PREVIEW_ROUTE,
                        ],
                    ],
                    Submission::SPAM_RESOURCE_KEY => [
                        'routes' => [
                            'list' => SpamController::LIST_ROUTE,
                            'detail' => SpamController::DETAIL_ROUTE,
                        ],
                    ],
                ],
                'field_type_options' => [
                    'single_selection' => [
                        'single_eekes_form_selection' => [
                            'default_type' => 'list_overlay',
                            'resource_key' => Form::RESOURCE_KEY,
                            'view' => [
                                'name' => 'eekes_form_builder.forms.edit_form',
                                'result_to_view' => ['id' => 'id'],
                            ],
                            'types' => [
                                'list_overlay' => [
                                    'adapter' => 'table',
                                    'list_key' => Form::LIST_KEY,
                                    'display_properties' => ['title'],
                                    'empty_text' => 'eekes_form_builder.no_form_selected',
                                    'icon' => 'su-list-check',
                                    'overlay_title' => 'eekes_form_builder.select_form',
                                ],
                                'auto_complete' => [
                                    'display_property' => 'title',
                                    'search_properties' => ['title', 'key'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }
    }

    public function getAlias(): string
    {
        return Configuration::ROOT_NODE;
    }

    /**
     * Whether a project registered a bundle.
     *
     * Deliberately not hasExtension(): load() is handed a throwaway container that holds the
     * parameters but none of the extensions, so asking it about sulu_admin would always answer no
     * and silently leave half the bundle unregistered.
     */
    private static function hasBundle(ContainerBuilder $container, string $bundleName): bool
    {
        $bundles = $container->getParameter('kernel.bundles');

        return \is_array($bundles) && \array_key_exists($bundleName, $bundles);
    }

    /**
     * The root of the bundle, where config/ and src/ live next to each other.
     */
    private static function bundleRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * The processed configuration as parameters: rendering.template becomes
     * eekes_sulu_form_builder.rendering.template. Paths in $keep stay one array parameter.
     *
     * @param array<mixed> $config
     * @param list<string> $keep
     *
     * @return array<string, null|array<mixed>|bool|float|int|string>
     */
    private static function flatten(array $config, string $prefix = '', array $keep = []): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            if (\is_array($value) && $value !== [] && !array_is_list($value) && !\in_array($prefix.$key, $keep, true)) {
                $result = [...$result, ...self::flatten($value, $prefix.$key.'.', $keep)];

                continue;
            }

            if ($value === null || \is_scalar($value) || \is_array($value)) {
                $result[$prefix.$key] = $value;
            }
        }

        return $result;
    }
}
