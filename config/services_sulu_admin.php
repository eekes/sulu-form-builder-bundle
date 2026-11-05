<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdmin;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\FormAdminMapper;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\IntegrationRunPresenter;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\NotificationDeliveryPresenter;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Admin\SubmissionPresenter;
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
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Metadata\NotificationTemplateFormMetadataVisitor;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Metadata\PlaceholderTextFormMetadataVisitor;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Metadata\SpamProtectionFormMetadataVisitor;
use Eekes\Sulu\FormBuilderBundle\Infrastructure\Sulu\Metadata\SubmissionListMetadataLoader;

/*
 * Loaded only when the Sulu admin bundle is registered: the views, the endpoints behind them and
 * the columns of the submissions list.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire();

    $services->set(FormAdmin::class)
        ->arg('$viewBuilderFactory', service('sulu_admin.view_builder_factory'))
        ->arg('$securityChecker', service('sulu_security.security_checker'))
        ->arg('$webspaceManager', service('sulu_core.webspace.webspace_manager'))
        ->arg('$navigationPosition', param('eekes_sulu_form_builder.admin.navigation_position'))
        ->arg('$activityViewBuilderFactory', service('sulu_activity.activity_list_view_builder_factory')->nullOnInvalid())
        ->tag('sulu.admin')
        ->tag('sulu.context', ['context' => 'admin']);

    $services->set(FormAdminMapper::class);
    $services->set(IntegrationRunPresenter::class)->arg('$translator', service('translator'));
    $services->set(SubmissionPresenter::class)->arg('$translator', service('translator'));
    $services->set(NotificationDeliveryPresenter::class);
    $services->set(PlaceholderTextFormMetadataVisitor::class)->tag('sulu_admin.form_metadata_visitor');
    $services->set(NotificationTemplateFormMetadataVisitor::class)
        ->arg('$translator', service('translator'))
        ->tag('sulu_admin.form_metadata_visitor');

    $services->set(SpamProtectionFormMetadataVisitor::class)
        ->arg('$translator', service('translator'))
        ->tag('sulu_admin.form_metadata_visitor');

    $services->set(SubmissionListMetadataLoader::class)
        ->arg('$translator', service('translator'))
        ->tag('sulu_admin.list_metadata_loader');

    // The controllers are tagged by hand and never autoconfigured. Symfony answers a #[Route]
    // attribute on an autoconfigured service with the routing.controller tag as well, and an
    // application that imports "resource: routing.controllers" - the default of the Symfony
    // skeleton - then publishes these routes a second time, without the /admin/api prefix and
    // outside the firewall that guards the administration interface.
    //
    // Without autoconfiguration the container.service_subscriber tag has to be set by hand, or
    // AbstractController is never handed its container and every request fails.
    $controller = static fn (string $class) => $services->set($class)
        ->tag('container.service_subscriber')
        ->tag('controller.service_arguments');

    $controller(FormController::class)
        ->arg('$domainEventCollector', service('sulu_activity.domain_event_collector'))
        ->arg('$restHelper', service('sulu_core.doctrine_rest_helper'))
        ->arg('$fieldDescriptorFactory', service('sulu_core.list_builder.field_descriptor_factory'))
        ->arg('$listBuilderFactory', service('sulu_core.doctrine_list_builder_factory'));
    $controller(FieldTypeController::class)
        ->arg('$translator', service('translator'));
    $controller(NotificationController::class);
    $controller(FlowController::class)
        ->arg('$translator', service('translator'));
    $controller(IntegrationController::class)
        ->arg('$translator', service('translator'))
        ->arg('$securityChecker', service('sulu_security.security_checker'));
    $controller(IntegrationConnectionController::class)
        ->arg('$translator', service('translator'));
    $controller(IntegrationRunController::class);
    $controller(SubmissionController::class);
    $controller(SpamController::class);
    $controller(NotificationDeliveryController::class);
};
